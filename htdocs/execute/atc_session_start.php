<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_permissions.php';
require_once __DIR__ . '/../includes/airport_atc_data.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_frequency_catalog.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';
require_once __DIR__ . '/../includes/division_schema.php';

function atcScopeForPosition(string $position): array
{
    $scopes = [
        'INFO' => ['INFO'],
        'DEL' => ['INFO', 'DEL'],
        'GND' => ['INFO', 'DEL', 'GND'],
        'TWR' => ['INFO', 'DEL', 'GND', 'TWR'],
        'APP' => ['INFO', 'DEL', 'GND', 'TWR', 'APP'],
        'DEP' => ['INFO', 'DEL', 'GND', 'TWR', 'APP', 'DEP'],
        'CTR' => ['INFO', 'DEL', 'GND', 'TWR', 'APP', 'DEP', 'CTR'],
    ];
    return $scopes[$position] ?? [];
}

function atcMapProfileForPosition(string $position): string
{
    $profiles = [
        'INFO' => 'airport_info',
        'DEL' => 'airport_delivery',
        'GND' => 'airport_ground',
        'TWR' => 'airport_tower',
        'APP' => 'terminal_approach',
        'DEP' => 'terminal_departure',
        'CTR' => 'enroute_center',
    ];
    return $profiles[$position] ?? 'airport_info';
}

function atcFrequencyForPosition(string $airport, string $position): string
{
    $overrides = require dirname(__DIR__) . '/includes/airport_atc_overrides.php';
    $overrideFrequency = trim((string)($overrides[$airport]['frequencies'][$position] ?? ''));
    if ($overrideFrequency !== '') return $overrideFrequency;
    $path = dirname(__DIR__) . '/data/airports/airport-frequencies.csv';
    $wanted = [
        'INFO' => ['AFIS', 'INFO', 'INFORMATION', 'FIS'],
        'DEL' => ['DEL', 'CLD', 'CLR'],
        'GND' => ['GND', 'RMP', 'APRON'],
        'TWR' => ['TWR'],
        'APP' => ['APP', 'ARR', 'A/D', 'RDR', 'DIR'],
        'DEP' => ['DEP', 'A/D', 'RDR', 'DIR'],
    ][$position] ?? [];
    $handle = @fopen($path, 'rb');
    if ($handle === false) return '';
    $header = fgetcsv($handle);
    $columns = is_array($header) ? array_flip($header) : [];
    while (($row = fgetcsv($handle)) !== false) {
        if (strtoupper(trim((string)($row[$columns['airport_ident'] ?? 2] ?? ''))) !== $airport) continue;
        $type = strtoupper(trim((string)($row[$columns['type'] ?? 3] ?? '')));
        if (in_array($type, $wanted, true)) {
            $frequency = trim((string)($row[$columns['frequency_mhz'] ?? 5] ?? ''));
            fclose($handle);
            return $frequency;
        }
    }
    fclose($handle);
    return '';
}

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
    ensureAtcSchema($pdo);
    ensureDivisionManagementSchema($pdo);
    $stmt = $pdo->prepare(
        "SELECT id, rating_atc, rating_special, division_code, op_permission
         FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => (int)$_SESSION['web_user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new RuntimeException('user_not_found');
    if (empty($atcLoginEnabled) && (int)($user['op_permission'] ?? 0) < 5) {
        http_response_code(403);
        throw new RuntimeException('atc_login_disabled');
    }

    $trainer = (string)($_POST['trainer'] ?? '0') === '1';
    $spectator = $trainer || (string)($_POST['spectator'] ?? '0') === '1';
    $trainerRole = $pdo->prepare("SELECT 1 FROM division_staff WHERE user_id=:user_id AND is_active=1 LIMIT 1");
    $trainerRole->execute(['user_id'=>(int)$user['id']]);
    $isDivisionTrainer = (bool)$trainerRole->fetchColumn();
    if ($trainer && (int)($user['op_permission'] ?? 0) < 1 && !$isDivisionTrainer) {
        http_response_code(403);
        throw new RuntimeException('atc_trainer_denied');
    }
    $station = strtoupper(trim((string)($_POST['station'] ?? '')));
    $position = strtoupper(trim((string)($_POST['position'] ?? '')));
    if (!preg_match('/^[A-Z0-9_-]{2,24}$/', $station)
        || !in_array($position, ['INFO', 'DEL', 'GND', 'TWR', 'APP', 'DEP', 'CTR'], true)) {
        http_response_code(422);
        throw new RuntimeException('invalid_station_or_position');
    }

    $rating = (int)$user['rating_atc'];
    $special = (int)$user['rating_special'];
    $globalStationAccess = (!$trainer && $spectator) || $special > 0 || ($trainer && (int)($user['op_permission'] ?? 0) >= 1);
    if (!$spectator && !canOccupyAtcPosition($rating, $position, $special)) {
        http_response_code(403);
        throw new RuntimeException('atc_position_denied');
    }

    $division = strtoupper(trim((string)$user['division_code']));
    $gcaDivisions = ($spectator || $rating < 5) ? [] : getApprovedGcaDivisions($pdo, (int)$user['id']);
    $stationDivision = '';
    $searchAllCountries = $globalStationAccess || !empty($gcaDivisions);
    $stationPositions = [];
    $radarBoundaryCode = '';
    $countryClause = $searchAllCountries
        ? '' : 'iso_country = :division AND';
    $airportStmt = $pdo->prepare(
        "SELECT ident, icao_code, gps_code, type FROM airports
         WHERE $countryClause
               (UPPER(ident) = :code1 OR UPPER(icao_code) = :code2 OR UPPER(gps_code) = :code3)
         LIMIT 1"
    );
    $airportParams = [
        'code1' => $station, 'code2' => $station, 'code3' => $station,
    ];
    if (!$searchAllCountries) $airportParams['division'] = $division;
    $airportStmt->execute($airportParams);
    $airport = $airportStmt->fetch(PDO::FETCH_ASSOC);
    if ($airport) {
        $countryStmt = $pdo->prepare("SELECT iso_country FROM airports WHERE UPPER(ident)=:code1 OR UPPER(icao_code)=:code2 OR UPPER(gps_code)=:code3 LIMIT 1");
        $countryStmt->execute(['code1'=>$station,'code2'=>$station,'code3'=>$station]);
        $stationDivision = strtoupper((string)$countryStmt->fetchColumn());
        if (!$globalStationAccess && $stationDivision !== $division && !in_array($stationDivision, $gcaDivisions, true)) $airport = false;
    }
    if ($airport) {
        $classification = getAirportAtcClassification(
            $station,
            dirname(__DIR__) . '/data/airports/airport-frequencies.csv'
        );
        $stationPositions = $spectator
            ? getSpectatorAirportPositions($airport, $classification)
            : $classification['positions'];
    } elseif ($position === 'CTR') {
        $sourcePath = dirname(__DIR__) . '/data/atc/VATSpy.dat';
        $stationExists = false;
        if (is_file($sourcePath) && is_readable($sourcePath)) {
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
                $parentCode = strtoupper(trim((string)($columns[0] ?? '')));
                $sectorAlias = strtoupper(trim((string)($columns[2] ?? '')));
                if ($parentCode === $station || $sectorAlias === $station) {
                    $stationExists = true;
                    $radarBoundaryCode = $parentCode;
                    break;
                }
            }
            if (is_resource($handle)) fclose($handle);
        }
        if (!$stationExists) {
            $compiledSector = findCompiledAtcSector($station);
            if ($compiledSector !== null) {
                $stationExists = true;
                $radarBoundaryCode = strtoupper((string)($compiledSector['group'] ?? $station));
            }
        }
        $divisionAllowed = $globalStationAccess;
        $guestRadar = false;
        if (!$globalStationAccess) {
            foreach (getDivisionAtcPrefixes($division) as $prefix) {
                if (strpos($station, $prefix) === 0) {
                    $divisionAllowed = true;
                    break;
                }
            }
            if (!$divisionAllowed) foreach ($gcaDivisions as $gcaDivision) foreach (array_unique(array_merge(getDivisionAtcPrefixes($gcaDivision), getDivisionAirportPrefixes($pdo, $gcaDivision))) as $prefix) {
                if (strpos($station, $prefix) === 0) { $divisionAllowed = true; $guestRadar = true; break 2; }
            }
        }
        if ($stationExists && $divisionAllowed) $stationPositions = ['CTR'];
    }
    if (!in_array($position, $stationPositions, true)) {
        http_response_code(422);
        throw new RuntimeException('station_position_unavailable');
    }
    $usesGca = !$globalStationAccess && (($stationDivision !== '' && $stationDivision !== $division) || (!empty($guestRadar)));
    if ($usesGca && !in_array($position, getGcaAllowedAtcPositions($rating), true)) {
        http_response_code(403);
        throw new RuntimeException('gca_rating_limit');
    }

    $lockName = 'vfn_atc_callsign_' . substr(hash('sha256', $station . '_' . $position), 0, 32);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
    $lockStmt->execute(['lock_name' => $lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) throw new RuntimeException('atc_position_busy');
    try {
        // Match the heartbeat resume grace. Sessions abandoned for longer
        // than five minutes must no longer reserve their controller position.
        $pdo->exec(
            "UPDATE atc_sessions SET is_active=0, disconnected_at=NOW()
             WHERE is_active=1 AND last_seen_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)"
        );
        $pdo->prepare(
            "UPDATE atc_sessions SET is_active = 0, disconnected_at = NOW()
             WHERE user_id = :user_id AND is_active = 1"
        )->execute(['user_id' => (int)$user['id']]);
        archiveAtcSessions($pdo, 'a.user_id=:history_user AND a.is_active=0', ['history_user'=>(int)$user['id']]);

        $base = $station . '_' . $position;
        if ($trainer) {
            if ($position === 'CTR') {
                $parts = preg_split('/[_-]+/', $station, 2);
                $callsign = (string)($parts[0] ?? $station) . '_X';
                if (!empty($parts[1])) $callsign .= '_' . (string)$parts[1];
            } else {
                $callsign = $station . '_X_' . $position;
            }
            $wantedCallsign = $callsign;
            $suffix = 2;
            $occupiedStmt = $pdo->prepare("SELECT 1 FROM atc_sessions WHERE callsign=:callsign AND is_active=1 LIMIT 1");
            while (true) {
                $occupiedStmt->execute(['callsign'=>$callsign]);
                if (!$occupiedStmt->fetchColumn()) break;
                $callsign = $wantedCallsign . '_' . $suffix++;
            }
        } elseif ($spectator) {
            $activeStmt = $pdo->prepare(
                "SELECT callsign FROM atc_sessions
                 WHERE station_code = :station AND position_code = :position
                   AND is_spectator = 1 AND is_active = 1"
            );
            $activeStmt->execute(['station' => $station, 'position' => $position]);
            $used = [];
            foreach ($activeStmt->fetchAll(PDO::FETCH_COLUMN) as $activeCallsign) {
                if (preg_match('/_SPEC([0-9]+)$/', (string)$activeCallsign, $match)) {
                    $used[(int)$match[1]] = true;
                }
            }
            $number = 1;
            while (isset($used[$number])) ++$number;
            $callsign = $base . '_SPEC' . $number;
        } else {
            $callsign = $base;
            $occupiedStmt = $pdo->prepare(
                "SELECT 1 FROM atc_sessions WHERE callsign = :callsign AND is_active = 1 LIMIT 1"
            );
            $occupiedStmt->execute(['callsign' => $callsign]);
            if ($occupiedStmt->fetchColumn()) {
                http_response_code(409);
                throw new RuntimeException('atc_position_busy');
            }
        }

        $token = bin2hex(random_bytes(32));
        // ATC visibility is independent from a simultaneously active pilot
        // session. A controlling or training position starts visible and can
        // only be hidden deliberately through the ATC option (OP level 1+).
        $isInvisible = 0;
        if ($spectator && !$trainer) {
            $isInvisible = ((int)($user['op_permission'] ?? 0) >= 1
                && (string)($_POST['invisible_spectator'] ?? '0') === '1') ? 1 : 0;
        }
        $scope = atcScopeForPosition($position);
        $mapProfile = atcMapProfileForPosition($position);
        $availableFrequencies = findAtcFrequencies($pdo, $station, $position);
        $frequency = $airport ? atcFrequencyForPosition($station, $position) : '';
        if ($frequency === '' && !empty($availableFrequencies)) {
            $frequency = (string)$availableFrequencies[0]['frequency'];
        }
        $insert = $pdo->prepare(
            "INSERT INTO atc_sessions
             (user_id, session_token, callsign, station_code, position_code,
              is_gca, is_spectator, is_trainer, is_invisible, can_control, can_transmit_voice, scope_positions,
              map_profile, radar_boundary_code, frequency)
             VALUES
             (:user_id, :token, :callsign, :station, :position,
              :is_gca, :spectator, :trainer, :is_invisible, :can_control, :can_voice, :scope, :map_profile,
              :radar_boundary_code, :frequency)"
        );
        $insert->execute([
            'user_id' => (int)$user['id'], 'token' => $token,
            'callsign' => $callsign, 'station' => $station, 'position' => $position,
            'is_gca' => $usesGca ? 1 : 0,
            'spectator' => $spectator ? 1 : 0,
            'trainer' => $trainer ? 1 : 0,
            'is_invisible' => $isInvisible,
            'can_control' => $spectator ? 0 : 1,
            'can_voice' => (!$spectator || $trainer) ? 1 : 0,
            'scope' => implode(',', $scope),
            'map_profile' => $mapProfile,
            'radar_boundary_code' => $radarBoundaryCode,
            'frequency' => $frequency,
        ]);
        $_SESSION['atc_session_id'] = (int)$pdo->lastInsertId();
        $_SESSION['atc_session_token'] = $token;
        if (!$spectator) {
            $atisSession = [
                'id' => $_SESSION['atc_session_id'],
                'user_id' => (int)$user['id'],
                'station_code' => $station,
                'position_code' => $position,
                'radar_boundary_code' => $radarBoundaryCode,
            ];
            $atisAirports = getAtisAirportsForSession($pdo, $atisSession);
            $storeAtisScope = $pdo->prepare(
                "INSERT INTO atc_session_atis_airports
                 (session_id,airport_icao,frequency,airport_name,latitude,longitude)
                 VALUES (:session_id,:icao,:frequency,:name,:latitude,:longitude)"
            );
            foreach ($atisAirports as $atisAirport) {
                $storeAtisScope->execute([
                    'session_id' => $_SESSION['atc_session_id'],
                    'icao' => (string)$atisAirport['icao'],
                    'frequency' => (string)$atisAirport['frequency'],
                    'name' => (string)$atisAirport['name'],
                    'latitude' => $atisAirport['latitude'],
                    'longitude' => $atisAirport['longitude'],
                ]);
            }
            $pdo->prepare("UPDATE atc_sessions SET atis_scope_ready=1 WHERE id=:id")
                ->execute(['id'=>$_SESSION['atc_session_id']]);
        }
        $voiceToken = (string)($_SESSION['web_voice_token'] ?? '');
        if ($voiceToken !== '') {
            $pdo->prepare(
                "UPDATE user_sessions
                 SET callsign=:callsign, is_spectator=:spectator
                 WHERE user_id=:user_id AND token=:token AND is_active=1"
            )->execute([
                'callsign'=>$callsign, 'spectator'=>($spectator && !$trainer) ? 1 : 0,
                'user_id'=>(int)$user['id'], 'token'=>$voiceToken,
            ]);
        }
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }

    echo json_encode([
        'success' => true,
        'callsign' => $callsign,
        'station_code' => $station,
        'position_code' => $position,
        'is_gca' => $usesGca,
        'spectator' => $spectator,
        'is_spectator' => $spectator,
        'trainer' => $trainer,
        'is_trainer' => $trainer,
        'is_invisible' => $isInvisible,
        'can_control' => !$spectator,
        'can_transmit_voice' => !$spectator || $trainer,
        'voice_mode' => ($spectator && !$trainer) ? 'receive_only' : 'transmit_receive',
        'scope_positions' => $scope,
        'map_profile' => $mapProfile,
        'radar_boundary_code' => $radarBoundaryCode,
        'frequency' => $frequency,
        'available_frequencies' => $availableFrequencies,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
