<?php
header('Content-Type: text/plain; charset=utf-8');

require_once 'config.php';

const VFN_TRAFFIC_MAX_AIRCRAFT = 100;
const VFN_TRAFFIC_MAX_DISTANCE_NM = 30.0;
const VFN_TRAFFIC_ACTIVE_SECONDS = 10;

function trafficDistanceNm(
    float $lat1,
    float $lon1,
    float $lat2,
    float $lon2
): float {
    $earthRadiusNm = 3440.065;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a =
        sin($dLat / 2.0) ** 2
        + cos(deg2rad($lat1))
        * cos(deg2rad($lat2))
        * sin($dLon / 2.0) ** 2;

    return $earthRadiusNm * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
}

function trafficField(string $value): string
{
    return str_replace(["\t", "\r", "\n"], ' ', trim($value));
}

$token = trim((string)($_POST['token'] ?? ''));
$hideInvisibleRequested =
    (string)($_POST['hide_invisible'] ?? '1') !== '0';

if ($token === '') {
    echo "ERR\tmissing_token\n";
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $viewerStmt = $pdo->prepare(
        "SELECT
            s.user_id,
            u.op_permission,
            p.latitude,
            p.longitude
         FROM user_sessions s
         INNER JOIN users u
            ON u.id = s.user_id
         INNER JOIN pilot_positions p
            ON p.session_token = s.token
         WHERE s.token = :token
           AND s.is_active = 1
           AND p.last_update >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
         LIMIT 1"
    );
    $viewerStmt->execute(['token' => $token]);
    $viewer = $viewerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$viewer) {
        echo "ERR\tinvalid_session_or_position\n";
        exit;
    }

    $viewerOpPermission = (int)$viewer['op_permission'];
    $maySeeSpectators = $viewerOpPermission >= 1;
    $maySeeInvisible = $viewerOpPermission > 1 && !$hideInvisibleRequested;
    $invisibleCondition = $maySeeInvisible
        ? "AND (s.is_invisible = 0 OR u.op_permission <= :viewer_op_permission)"
        : "AND s.is_invisible = 0";

    $trafficStmt = $pdo->prepare(
        "SELECT
            p.user_id,
            p.callsign,
            p.aircraft_icao,
            p.latitude,
            p.longitude,
            p.altitude,
            p.heading,
            p.pitch,
            p.roll_angle,
            p.airspeed,
            p.vertical_speed,
            p.on_ground,
            p.gear_ratio,
            p.flap_ratio,
            p.speedbrake_ratio,
            p.thrust_ratio,
            p.engine_rpm,
            p.yoke_pitch_ratio,
            p.yoke_roll_ratio,
            p.yoke_heading_ratio,
            p.taxi_lights,
            p.landing_lights,
            p.beacon_lights,
            p.strobe_lights,
            p.nav_lights,
            s.is_spectator,
            u.op_permission,
            fp.departure_airport,
            fp.arrival_airport
         FROM pilot_positions p
         INNER JOIN user_sessions s
            ON s.token = p.session_token
         INNER JOIN users u
            ON u.id = p.user_id
         LEFT JOIN pilot_flightplans fp
            ON fp.session_token = p.session_token
         WHERE p.user_id <> :viewer_user_id
           AND s.is_active = 1
           AND (s.is_spectator = 0 OR :may_see_spectators = 1)
           $invisibleCondition
           AND p.last_update >= DATE_SUB(NOW(), INTERVAL 10 SECOND)"
    );
    $trafficParameters = [
        'viewer_user_id' => (int)$viewer['user_id'],
        'may_see_spectators' => $maySeeSpectators ? 1 : 0
    ];
    if ($maySeeInvisible) {
        $trafficParameters['viewer_op_permission'] = $viewerOpPermission;
    }
    $trafficStmt->execute($trafficParameters);

    $nearby = [];
    while ($row = $trafficStmt->fetch(PDO::FETCH_ASSOC)) {
        $distanceNm = trafficDistanceNm(
            (float)$viewer['latitude'],
            (float)$viewer['longitude'],
            (float)$row['latitude'],
            (float)$row['longitude']
        );

        if ($distanceNm > VFN_TRAFFIC_MAX_DISTANCE_NM) {
            continue;
        }

        $row['distance_nm'] = $distanceNm;
        $nearby[] = $row;
    }

    usort(
        $nearby,
        static fn(array $a, array $b): int =>
            $a['distance_nm'] <=> $b['distance_nm']
    );
    $nearby = array_slice($nearby, 0, VFN_TRAFFIC_MAX_AIRCRAFT);

    echo "OK\t" . count($nearby) . "\n";

    foreach ($nearby as $row) {
        echo implode("\t", [
            (string)(int)$row['user_id'],
            trafficField((string)$row['callsign']),
            trafficField((string)$row['aircraft_icao']),
            number_format((float)$row['latitude'], 7, '.', ''),
            number_format((float)$row['longitude'], 7, '.', ''),
            number_format((float)$row['altitude'], 2, '.', ''),
            number_format((float)$row['heading'], 2, '.', ''),
            number_format((float)$row['pitch'], 2, '.', ''),
            number_format((float)$row['roll_angle'], 2, '.', ''),
            number_format((float)$row['airspeed'], 2, '.', ''),
            number_format((float)$row['vertical_speed'], 2, '.', ''),
            (string)((int)$row['on_ground'] === 1 ? 1 : 0),
            number_format((float)$row['gear_ratio'], 3, '.', ''),
            number_format((float)$row['flap_ratio'], 3, '.', ''),
            number_format((float)$row['speedbrake_ratio'], 3, '.', ''),
            number_format((float)$row['thrust_ratio'], 3, '.', ''),
            number_format((float)$row['engine_rpm'], 1, '.', ''),
            number_format((float)$row['yoke_pitch_ratio'], 3, '.', ''),
            number_format((float)$row['yoke_roll_ratio'], 3, '.', ''),
            number_format((float)$row['yoke_heading_ratio'], 3, '.', ''),
            (string)((int)$row['taxi_lights'] === 1 ? 1 : 0),
            (string)((int)$row['landing_lights'] === 1 ? 1 : 0),
            (string)((int)$row['beacon_lights'] === 1 ? 1 : 0),
            (string)((int)$row['strobe_lights'] === 1 ? 1 : 0),
            (string)((int)$row['nav_lights'] === 1 ? 1 : 0),
            trafficField((string)$row['aircraft_icao']),
            trafficField((string)($row['departure_airport'] ?: 'ZZZZ')),
            trafficField((string)($row['arrival_airport'] ?: 'ZZZZ')),
            number_format((float)$row['distance_nm'], 1, '.', ''),
            (string)((int)$row['is_spectator'] === 1 ? 1 : 0),
            (string)(int)$row['op_permission'],
        ]) . "\n";
    }
} catch (Throwable $error) {
    error_log('VFN traffic poll failed: ' . $error->getMessage());
    echo "ERR\tserver_error\n";
}
