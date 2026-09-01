<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=20');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/language.php';
require_once __DIR__ . '/../includes/atc_permissions.php';
require_once __DIR__ . '/../includes/airport_atc_data.php';
require_once __DIR__ . '/../includes/atc_frequency_catalog.php';
require_once __DIR__ . '/../includes/division_schema.php';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        http_response_code(401);
        throw new RuntimeException('login_required');
    }
    $userStmt = $pdo->prepare(
        "SELECT rating_atc, rating_special, division_code, op_permission
         FROM users WHERE id = :id LIMIT 1"
    );
    $userStmt->execute(['id' => (int)$_SESSION['web_user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    // Die Suche darf keine parallel laufenden Karten-, Voice- oder
    // Verkehrsabfragen durch die PHP-Session blockieren.
    session_write_close();
    ensureDivisionManagementSchema($pdo);
    $trainer = (string)($_GET['trainer'] ?? '0') === '1';
    $spectator = !$trainer && (string)($_GET['spectator'] ?? '0') === '1';
    $observer = $spectator || $trainer;
    $trainerRole = $pdo->prepare("SELECT 1 FROM division_staff WHERE user_id=:user_id AND is_active=1 LIMIT 1");
    $trainerRole->execute(['user_id'=>(int)$_SESSION['web_user_id']]);
    $isDivisionTrainer = (bool)$trainerRole->fetchColumn();
    if ($trainer
        && (int)($user['op_permission'] ?? 0) < 1
        && (int)($user['rating_special'] ?? 0) < 1
        && !$isDivisionTrainer) {
        http_response_code(403);
        throw new RuntimeException('atc_trainer_denied');
    }
    if (!$observer && !canUseAtcClient(
        (int)($user['rating_atc'] ?? 0),
        (int)($user['rating_special'] ?? 0)
    )) {
        http_response_code(403);
        throw new RuntimeException('access_denied');
    }

    $rating = (int)($user['rating_atc'] ?? 0);
    $specialRating = (int)($user['rating_special'] ?? 0);
    $globalStationAccess = $spectator || $specialRating > 0
        || ($trainer && (int)($user['op_permission'] ?? 0) >= 1);
    $divisionCode = strtoupper(trim((string)($user['division_code'] ?? '')));
    $gcaDivisions = ($observer || $rating < 5) ? [] : getApprovedGcaDivisions($pdo, (int)$_SESSION['web_user_id']);
    $searchAllCountries = $globalStationAccess || !empty($gcaDivisions);
    $permissions = getAtcPositionPermissions($rating, $specialRating);
    $gcaEffectiveRating = getGcaEffectiveAtcRating($rating);
    $gcaAllowedPositions = getGcaAllowedAtcPositions($rating);
    if ($observer) {
        foreach ($permissions as &$permission) $permission['allowed'] = true;
        unset($permission);
    }

    $query = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
    if (mb_strlen($query) < 2) {
        echo json_encode(['success' => true, 'items' => []]);
        exit;
    }
    $normalizedQuery = strtoupper($query);
    $isExactIdentifier = (bool)preg_match(
        '/^(?:[A-Z0-9]{4}|[A-Z]{2}-[A-Z0-9]{2,8})$/',
        $normalizedQuery
    );
    if ($isExactIdentifier) {
        // Exact identifiers are the most common ATC lookup. Avoid a costly
        // leading-wildcard scan over the worldwide airport table.
        $countryClause = $searchAllCountries
            ? '' : 'iso_country = :division_code AND';
        $airportStmt = $pdo->prepare(
            "SELECT ident, icao_code, gps_code, name, municipality, type,
                    latitude_deg, longitude_deg, iso_country
             FROM airports
             WHERE $countryClause
                   (ident = :q1 OR icao_code = :q2 OR gps_code = :q3)
             ORDER BY scheduled_service DESC, name
             LIMIT 15"
        );
        $airportParams = [
            'q1' => $normalizedQuery,
            'q2' => $normalizedQuery,
            'q3' => $normalizedQuery,
        ];
        if (!$searchAllCountries) {
            $airportParams['division_code'] = $divisionCode;
        }
    } else {
        $like = '%' . $query . '%';
        $starts = $query . '%';
        $countryClause = $searchAllCountries
            ? '' : 'AND iso_country = :division_code';
        $airportStmt = $pdo->prepare(
            "SELECT ident, icao_code, gps_code, name, municipality, type,
                    latitude_deg, longitude_deg, iso_country
             FROM airports
             WHERE (ident LIKE :q1 OR icao_code LIKE :q2 OR gps_code LIKE :q3
                OR name LIKE :q4 OR municipality LIKE :q5)
               $countryClause
             ORDER BY
                CASE WHEN ident LIKE :starts1 OR icao_code LIKE :starts2
                          OR gps_code LIKE :starts3 THEN 0 ELSE 1 END,
                scheduled_service DESC, name
             LIMIT 15"
        );
        $airportParams = [
            'q1' => $like, 'q2' => $like, 'q3' => $like,
            'q4' => $like, 'q5' => $like,
            'starts1' => $starts, 'starts2' => $starts, 'starts3' => $starts,
        ];
        if (!$searchAllCountries) {
            $airportParams['division_code'] = $divisionCode;
        }
    }
    $airportStmt->execute($airportParams);
    $items = [];
    $frequencyCsv = dirname(__DIR__) . '/data/airports/airport-frequencies.csv';
    foreach ($airportStmt->fetchAll(PDO::FETCH_ASSOC) as $airport) {
        $code = strtoupper(trim((string)(
            $airport['icao_code'] ?: $airport['gps_code'] ?: $airport['ident']
        )));
        if ($code === '') continue;
        $classification = getAirportAtcClassification($code, $frequencyCsv);
        $airportDivision = strtoupper((string)($airport['iso_country'] ?? ''));
        if (!$globalStationAccess && $airportDivision !== $divisionCode && !in_array($airportDivision, $gcaDivisions, true)) continue;
        $airportPermissions = (!$globalStationAccess && $airportDivision !== $divisionCode)
            ? getAtcPositionPermissions($gcaEffectiveRating, 0) : $permissions;
        $eligiblePositions = $observer
            ? getSpectatorAirportPositions($airport, $classification)
            : $classification['positions'];
        if (!$globalStationAccess && $airportDivision !== $divisionCode) {
            $eligiblePositions = array_values(array_intersect($eligiblePositions, $gcaAllowedPositions));
        }
        $operation = (string)($classification['operation'] ?? (
            $classification['controlled'] ? 'controlled' : 'uncontrolled'
        ));
        if (!hasAtcPositionIntersection($eligiblePositions, $airportPermissions)) continue;
        $items[] = [
            'code' => $code,
            'name' => (string)$airport['name'],
            'municipality' => (string)($airport['municipality'] ?? ''),
            'kind' => 'airport',
            'kind_label' => t('atc_station_airport'),
            'airport_type' => (string)$airport['type'],
            'latitude' => (float)$airport['latitude_deg'],
            'longitude' => (float)$airport['longitude_deg'],
            'country_code' => (string)$airport['iso_country'],
            'eligible_positions' => $eligiblePositions,
            'controlled' => $classification['controlled'],
            'frequency_types' => $classification['frequency_types'],
            'operation' => $operation,
            'operation_label' => t('atc_operation_' . $operation),
        ];
    }

    $sourcePath = dirname(__DIR__) . '/data/atc/VATSpy.dat';
    $divisionPrefixes = getDivisionAtcPrefixes($divisionCode);
    $canUseRadar = $observer
        || canOccupyAtcPosition($rating, 'CTR', $specialRating);
    if (!$canUseRadar && $gcaDivisions) $canUseRadar = in_array('CTR', $gcaAllowedPositions, true);
    $seenRadarPositions = [];
    if ($canUseRadar && is_file($sourcePath) && is_readable($sourcePath)) {
        $section = '';
        $handle = fopen($sourcePath, 'rb');
        while ($handle !== false && ($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (preg_match('/^\[([A-Za-z]+)\]$/', $line, $match)) {
                $section = strtoupper($match[1]);
                continue;
            }
            if (!in_array($section, ['FIRS', 'UIRS'], true)
                || $line === '' || substr($line, 0, 1) === ';') continue;
            $columns = explode('|', $line);
            $code = strtoupper(trim((string)($columns[0] ?? '')));
            $name = trim((string)($columns[1] ?? ''));
            $sectorAlias = strtoupper(trim((string)($columns[2] ?? '')));
            if ($code === '' || $name === '') continue;
            $matchesDivision = false;
            foreach ($divisionPrefixes as $prefix) {
                if (strpos($code, $prefix) === 0) {
                    $matchesDivision = true;
                    break;
                }
            }
            $matchesGca = false;
            foreach ($gcaDivisions as $gcaDivision) foreach (array_unique(array_merge(getDivisionAtcPrefixes($gcaDivision), getDivisionAirportPrefixes($pdo, $gcaDivision))) as $prefix) {
                if (strpos($code, $prefix) === 0) { $matchesGca = true; break 2; }
            }
            if (!$globalStationAccess && !$matchesDivision && !$matchesGca) continue;
            if (!$globalStationAccess && !$matchesDivision && $matchesGca
                && !in_array('CTR', $gcaAllowedPositions, true)) continue;
            if (mb_stripos($code, $query) === false
                && mb_stripos($sectorAlias, $query) === false
                && mb_stripos($name, $query) === false) continue;
            $selectableCode = normalizeAtcStationCode(
                $sectorAlias !== '' ? $sectorAlias : $code
            );
            if (isset($seenRadarPositions[$selectableCode])) continue;
            $seenRadarPositions[$selectableCode] = true;
            $items[] = [
                'code' => $selectableCode,
                'name' => $name . ($sectorAlias !== '' ? ' – ' . $sectorAlias : ''),
                'municipality' => '',
                'kind' => 'fir',
                'kind_label' => $section === 'UIRS'
                    ? t('atc_station_uir') : t('atc_station_fir'),
                'eligible_positions' => ['CTR'],
                'radar_boundary_code' => $code,
            ];
            if (count($items) >= 25) break;
        }
        if (is_resource($handle)) fclose($handle);
    }

    // VATGlasses contains many operational sub-sectors which VATSpy only
    // exposes as repeated aliases of a larger parent FIR. Make the exact
    // position searchable while retaining the existing division restriction.
    if ($canUseRadar) {
        foreach (getCompiledAtcSectors() as $compiledCode => $compiled) {
            $compiledCode = normalizeAtcStationCode((string)$compiledCode);
            if ($compiledCode === '' || isset($seenRadarPositions[$compiledCode])) continue;
            $matchesDivision = false;
            foreach ($divisionPrefixes as $prefix) {
                if (strpos($compiledCode, $prefix) === 0) {
                    $matchesDivision = true;
                    break;
                }
            }
            $matchesGca = false;
            foreach ($gcaDivisions as $gcaDivision) foreach (array_unique(array_merge(getDivisionAtcPrefixes($gcaDivision), getDivisionAirportPrefixes($pdo, $gcaDivision))) as $prefix) {
                if (strpos($compiledCode, $prefix) === 0) { $matchesGca = true; break 2; }
            }
            if (!$globalStationAccess && !$matchesDivision && !$matchesGca) continue;
            if (!$globalStationAccess && !$matchesDivision && $matchesGca
                && !in_array('CTR', $gcaAllowedPositions, true)) continue;
            $callsign = trim((string)($compiled['callsign'] ?? ''));
            $positionKey = trim((string)($compiled['position_key'] ?? ''));
            if (mb_stripos($compiledCode, $query) === false
                && mb_stripos($callsign, $query) === false
                && mb_stripos($positionKey, $query) === false) continue;
            $seenRadarPositions[$compiledCode] = true;
            $frequency = normalizeAtcVoiceFrequency((string)($compiled['frequency'] ?? ''));
            $items[] = [
                'code' => $compiledCode,
                'name' => ($callsign !== '' ? $callsign : $compiledCode)
                    . ($positionKey !== '' ? ' – ' . $positionKey : '')
                    . ($frequency !== '' ? ' (' . $frequency . ' MHz)' : ''),
                'municipality' => '',
                'kind' => 'fir',
                'kind_label' => t('atc_station_fir'),
                'eligible_positions' => ['CTR'],
                'radar_boundary_code' => strtoupper((string)($compiled['group'] ?? '')),
            ];
        }
    }

    usort($items, static function (array $left, array $right) use ($normalizedQuery): int {
        $leftCode = strtoupper((string)($left['code'] ?? ''));
        $rightCode = strtoupper((string)($right['code'] ?? ''));
        $score = static function (string $code) use ($normalizedQuery): int {
            if ($code === $normalizedQuery) return 0;
            if (strpos($code, $normalizedQuery . '-') === 0
                || strpos($code, $normalizedQuery . '_') === 0) return 1;
            if (strpos($code, $normalizedQuery) === 0) return 2;
            return 3;
        };
        return [$score($leftCode), $leftCode] <=> [$score($rightCode), $rightCode];
    });

    echo json_encode(
        ['success' => true, 'items' => array_slice($items, 0, 25)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    $message = $error->getMessage();
    $translated = t($message);
    if ($translated !== $message) $message = $translated;
    echo json_encode(
        ['success' => false, 'message' => $message],
        JSON_UNESCAPED_UNICODE
    );
}
