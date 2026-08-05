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
    $stmt = $pdo->prepare(
        "SELECT id, rating_atc, rating_special, division_code
         FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => (int)$_SESSION['web_user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new RuntimeException('user_not_found');

    $spectator = (string)($_POST['spectator'] ?? '0') === '1';
    $station = strtoupper(trim((string)($_POST['station'] ?? '')));
    $position = strtoupper(trim((string)($_POST['position'] ?? '')));
    if (!preg_match('/^[A-Z0-9-]{2,12}$/', $station)
        || !in_array($position, ['INFO', 'DEL', 'GND', 'TWR', 'APP', 'DEP', 'CTR'], true)) {
        http_response_code(422);
        throw new RuntimeException('invalid_station_or_position');
    }

    $rating = (int)$user['rating_atc'];
    $special = (int)$user['rating_special'];
    $globalStationAccess = $spectator || $special > 0;
    if (!$spectator && !canOccupyAtcPosition($rating, $position, $special)) {
        http_response_code(403);
        throw new RuntimeException('atc_position_denied');
    }

    $division = strtoupper(trim((string)$user['division_code']));
    $stationPositions = [];
    $countryClause = $globalStationAccess
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
    if (!$globalStationAccess) $airportParams['division'] = $division;
    $airportStmt->execute($airportParams);
    $airport = $airportStmt->fetch(PDO::FETCH_ASSOC);
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
                if (strtoupper(trim((string)($columns[0] ?? ''))) === $station) {
                    $stationExists = true;
                    break;
                }
            }
            if (is_resource($handle)) fclose($handle);
        }
        $divisionAllowed = $globalStationAccess;
        if (!$globalStationAccess) {
            foreach (getDivisionAtcPrefixes($division) as $prefix) {
                if (strpos($station, $prefix) === 0) {
                    $divisionAllowed = true;
                    break;
                }
            }
        }
        if ($stationExists && $divisionAllowed) $stationPositions = ['CTR'];
    }
    if (!in_array($position, $stationPositions, true)) {
        http_response_code(422);
        throw new RuntimeException('station_position_unavailable');
    }

    $lockName = 'vfn_atc_callsign_' . substr(hash('sha256', $station . '_' . $position), 0, 32);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
    $lockStmt->execute(['lock_name' => $lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) throw new RuntimeException('atc_position_busy');
    try {
        $pdo->prepare(
            "UPDATE atc_sessions SET is_active = 0, disconnected_at = NOW()
             WHERE user_id = :user_id AND is_active = 1"
        )->execute(['user_id' => (int)$user['id']]);

        $base = $station . '_' . $position;
        if ($spectator) {
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
        $scope = atcScopeForPosition($position);
        $mapProfile = atcMapProfileForPosition($position);
        $insert = $pdo->prepare(
            "INSERT INTO atc_sessions
             (user_id, session_token, callsign, station_code, position_code,
              is_spectator, can_control, can_transmit_voice, scope_positions,
              map_profile)
             VALUES
             (:user_id, :token, :callsign, :station, :position,
              :spectator, :can_control, :can_voice, :scope, :map_profile)"
        );
        $insert->execute([
            'user_id' => (int)$user['id'], 'token' => $token,
            'callsign' => $callsign, 'station' => $station, 'position' => $position,
            'spectator' => $spectator ? 1 : 0,
            'can_control' => $spectator ? 0 : 1,
            'can_voice' => $spectator ? 0 : 1,
            'scope' => implode(',', $scope),
            'map_profile' => $mapProfile,
        ]);
        $_SESSION['atc_session_id'] = (int)$pdo->lastInsertId();
        $_SESSION['atc_session_token'] = $token;
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }

    echo json_encode([
        'success' => true,
        'callsign' => $callsign,
        'spectator' => $spectator,
        'can_control' => !$spectator,
        'voice_mode' => $spectator ? 'receive_only' : 'transmit_receive',
        'scope_positions' => $scope,
        'map_profile' => $mapProfile,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
