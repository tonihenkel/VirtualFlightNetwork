<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/atc_schema.php';

$period = (int)($_GET['days'] ?? 30);
if (!in_array($period, [0, 1, 7, 30, 90, 365], true)) {
    $period = 30;
}
$periodStartSql = $period === 0 ? "'1000-01-01 00:00:00'" : "DATE_SUB(NOW(),INTERVAL $period DAY)";
$includeHeatmap = !empty($_GET['include_heatmap']);
$pilotSort = (string)($_GET['pilot_sort'] ?? 'flights');
$pilotSortColumns = [
    'flights' => 'flights',
    'distance' => 'distance_nm',
    'hours' => 'duration_seconds',
    'landings' => 'landings'
];
if (!isset($pilotSortColumns[$pilotSort])) {
    $pilotSort = 'flights';
}
$pilotOrderColumn = $pilotSortColumns[$pilotSort];
$userId = max(0, (int)($_GET['user_id'] ?? 0));
$aircraftSort = (string)($_GET['aircraft_sort'] ?? 'flights');
$aircraftSortColumns = ['flights'=>'flights','distance'=>'distance_nm','hours'=>'duration_seconds','airports'=>'airports'];
if (!isset($aircraftSortColumns[$aircraftSort])) $aircraftSort = 'flights';
$aircraftOrderColumn = $aircraftSortColumns[$aircraftSort];
$atcSort = (string)($_GET['atc_sort'] ?? 'hours');
$atcSortColumns = ['hours' => 'duration_seconds', 'sessions' => 'sessions', 'average' => 'average_duration_seconds'];
if (!isset($atcSortColumns[$atcSort])) $atcSort = 'hours';
$atcOrderColumn = $atcSortColumns[$atcSort];

function movementAirportsByCountry(PDO $pdo, int $period, int $userId = 0): array
{
    $periodStartSql = $period === 0 ? "'1000-01-01 00:00:00'" : "DATE_SUB(NOW(),INTERVAL $period DAY)";
    $userDeparture = $userId > 0 ? "user_id=:departure_user AND status='completed' AND completed_at>=$periodStartSql" : "started_at>=$periodStartSql";
    $userArrival = $userId > 0 ? "user_id=:arrival_user AND status='completed' AND completed_at>=$periodStartSql" : "started_at>=$periodStartSql";
    $stmt = $pdo->prepare(
        "SELECT routes.airport_code, routes.movements,
                COALESCE((SELECT UPPER(NULLIF(a.iso_country,'')) FROM airports a WHERE a.ident=routes.airport_code OR a.icao_code=routes.airport_code OR a.gps_code=routes.airport_code LIMIT 1),'--') country_code,
                COALESCE((SELECT a.name FROM airports a WHERE a.ident=routes.airport_code OR a.icao_code=routes.airport_code OR a.gps_code=routes.airport_code LIMIT 1),'') airport_name,
                COALESCE((SELECT a.municipality FROM airports a WHERE a.ident=routes.airport_code OR a.icao_code=routes.airport_code OR a.gps_code=routes.airport_code LIMIT 1),'') municipality
         FROM (
            SELECT movement_rows.airport_code,COUNT(*) movements
            FROM (
                SELECT departure_airport airport_code FROM pilot_flights
                WHERE $userDeparture AND departure_airport IS NOT NULL AND departure_airport<>'' AND departure_airport<>'ZZZZ'
                UNION ALL
                SELECT arrival_airport airport_code FROM pilot_flights
                WHERE $userArrival AND arrival_airport IS NOT NULL AND arrival_airport<>'' AND arrival_airport<>'ZZZZ'
            ) movement_rows
            GROUP BY movement_rows.airport_code
         ) routes
         ORDER BY routes.movements DESC,routes.airport_code ASC"
    );
    $stmt->execute($userId > 0 ? ['departure_user'=>$userId,'arrival_user'=>$userId] : []);
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $countryCode = (string)$row['country_code'];
        $grouped[$countryCode][] = [
            'code' => (string)$row['airport_code'],
            'name' => (string)$row['airport_name'],
            'municipality' => (string)$row['municipality'],
            'movements' => (int)$row['movements']
        ];
    }
    return $grouped;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    ensureAtcSchema($pdo);

    $atcWhere = $userId > 0 ? 'user_id=:atc_user_id AND ' : '';
    $atcParams = $userId > 0 ? ['atc_user_id'=>$userId] : [];
    $atcSummaryStmt = $pdo->prepare(
        "SELECT COUNT(*) sessions,COUNT(DISTINCT user_id) controllers,
                COUNT(DISTINCT callsign) positions,COUNT(DISTINCT station_code) stations,
                COALESCE(SUM(duration_seconds),0) duration_seconds,
                COALESCE(AVG(duration_seconds),0) average_duration_seconds
         FROM atc_session_history WHERE {$atcWhere}is_trainer=0 AND disconnected_at>=$periodStartSql"
    );
    $atcSummaryStmt->execute($atcParams);
    $atcSummary = $atcSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $atcPositionStmt = $pdo->prepare(
        "SELECT callsign,station_code,position_code,COUNT(*) sessions,
                COALESCE(SUM(duration_seconds),0) duration_seconds,
                COALESCE(AVG(duration_seconds),0) average_duration_seconds
         FROM atc_session_history WHERE {$atcWhere}is_trainer=0 AND disconnected_at>=$periodStartSql
         GROUP BY callsign,station_code,position_code ORDER BY $atcOrderColumn DESC,duration_seconds DESC,sessions DESC,callsign LIMIT 10"
    );
    $atcPositionStmt->execute($atcParams);
    $topAtcPositions = $atcPositionStmt->fetchAll(PDO::FETCH_ASSOC);
    $topControllers = [];
    if ($userId === 0) {
        $topControllers = $pdo->query(
            "SELECT h.user_id,u.username,u.real_name,u.country_code,COUNT(*) sessions,
                    COALESCE(SUM(h.duration_seconds),0) duration_seconds,
                    COALESCE(AVG(h.duration_seconds),0) average_duration_seconds
             FROM atc_session_history h JOIN users u ON u.id=h.user_id
             WHERE h.is_trainer=0 AND h.disconnected_at>=$periodStartSql
             GROUP BY h.user_id,u.username,u.real_name,u.country_code
             ORDER BY $atcOrderColumn DESC,duration_seconds DESC,sessions DESC,u.username LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($userId > 0) {
        $userStmt=$pdo->prepare('SELECT id,username,real_name,country_code FROM users WHERE id=:id AND is_active=1 LIMIT 1');
        $userStmt->execute(['id'=>$userId]); $selectedUser=$userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$selectedUser) { http_response_code(404); throw new RuntimeException('user_not_found'); }
        $summaryStmt=$pdo->prepare("SELECT COUNT(*) flights,COALESCE(SUM(distance_nm),0) distance_nm,COALESCE(SUM(duration_seconds),0) duration_seconds FROM pilot_flights WHERE user_id=:uid AND status='completed' AND completed_at>=$periodStartSql");
        $summaryStmt->execute(['uid'=>$userId]);$personalSummary=$summaryStmt->fetch(PDO::FETCH_ASSOC);
        $aircraftStmt=$pdo->prepare("SELECT UPPER(COALESCE(NULLIF(aircraft_icao,''),'----')) code,COUNT(*) flights,COALESCE(SUM(distance_nm),0) distance_nm,COALESCE(SUM(duration_seconds),0) duration_seconds,COUNT(DISTINCT NULLIF(departure_airport,'ZZZZ'))+COUNT(DISTINCT NULLIF(arrival_airport,'ZZZZ')) airports FROM pilot_flights WHERE user_id=:uid AND status='completed' AND completed_at>=$periodStartSql GROUP BY code ORDER BY $aircraftOrderColumn DESC,flights DESC,code LIMIT 10");
        $aircraftStmt->execute(['uid'=>$userId]);$personalAircraft=$aircraftStmt->fetchAll(PDO::FETCH_ASSOC);
        $airportStmt=$pdo->prepare("SELECT m.code,COUNT(*) movements,MAX(a.name) name,MAX(a.municipality) municipality FROM (SELECT departure_airport code FROM pilot_flights WHERE user_id=:u1 AND status='completed' AND completed_at>=$periodStartSql UNION ALL SELECT arrival_airport code FROM pilot_flights WHERE user_id=:u2 AND status='completed' AND completed_at>=$periodStartSql)m LEFT JOIN airports a ON a.ident=m.code OR a.icao_code=m.code OR a.gps_code=m.code WHERE m.code IS NOT NULL AND m.code<>'' AND m.code<>'ZZZZ' GROUP BY m.code ORDER BY movements DESC,m.code LIMIT 10");
        $airportStmt->execute(['u1'=>$userId,'u2'=>$userId]);$personalAirports=$airportStmt->fetchAll(PDO::FETCH_ASSOC);
        $countryStmt=$pdo->prepare("SELECT COALESCE(UPPER(NULLIF(a.iso_country,'')),'--') code,COUNT(*) movements,COUNT(DISTINCT m.code) airports FROM (SELECT departure_airport code FROM pilot_flights WHERE user_id=:u1 AND status='completed' AND completed_at>=$periodStartSql UNION ALL SELECT arrival_airport code FROM pilot_flights WHERE user_id=:u2 AND status='completed' AND completed_at>=$periodStartSql)m LEFT JOIN airports a ON a.ident=m.code OR a.icao_code=m.code OR a.gps_code=m.code WHERE m.code IS NOT NULL AND m.code<>'' AND m.code<>'ZZZZ' GROUP BY COALESCE(UPPER(NULLIF(a.iso_country,'')),'--') ORDER BY movements DESC,airports DESC,code LIMIT 10");
        $countryStmt->execute(['u1'=>$userId,'u2'=>$userId]);$personalCountries=$countryStmt->fetchAll(PDO::FETCH_ASSOC);
        $personalAirportsByCountry=movementAirportsByCountry($pdo,$period,$userId);
        foreach($personalCountries as &$country){$country['airport_details']=$personalAirportsByCountry[(string)$country['code']]??[];}unset($country);
        echo json_encode(['success'=>true,'mode'=>'player','user'=>['id'=>(int)$selectedUser['id'],'username'=>$selectedUser['username'],'real_name'=>$selectedUser['real_name'],'country_code'=>$selectedUser['country_code']],'period_days'=>$period,'summary'=>['flights'=>(int)$personalSummary['flights'],'pilots'=>1,'distance_nm'=>round((float)$personalSummary['distance_nm'],1),'duration_seconds'=>(int)$personalSummary['duration_seconds']],'atc_summary'=>$atcSummary,'top_atc_positions'=>$topAtcPositions,'top_controllers'=>[],'top_aircraft'=>$personalAircraft,'top_airports'=>$personalAirports,'top_movement_countries'=>$personalCountries,'top_pilot_countries'=>[],'top_pilots'=>[],'aircraft_sort'=>$aircraftSort,'heatmap'=>[]],JSON_UNESCAPED_UNICODE); exit;
    }

    $aircraftStmt = $pdo->query(
        "SELECT UPPER(COALESCE(NULLIF(aircraft_icao,''),'----')) AS code,
                COUNT(*) AS flights, COALESCE(SUM(distance_nm),0) AS distance_nm,
                COALESCE(SUM(duration_seconds),0) AS duration_seconds,
                COUNT(DISTINCT NULLIF(departure_airport,'ZZZZ')) + COUNT(DISTINCT NULLIF(arrival_airport,'ZZZZ')) AS airports
         FROM pilot_flights WHERE status='completed' AND completed_at>=$periodStartSql
         GROUP BY code ORDER BY $aircraftOrderColumn DESC, flights DESC, code ASC LIMIT 10"
    );
    $topAircraft = $aircraftStmt->fetchAll(PDO::FETCH_ASSOC);

    $airportStmt = $pdo->query(
        "SELECT routes.airport_code, routes.movements,
                MAX(a.name) AS airport_name, MAX(a.municipality) AS municipality,
                MAX(a.latitude_deg) AS latitude, MAX(a.longitude_deg) AS longitude
         FROM (
            SELECT movement_rows.airport_code, COUNT(*) AS movements
            FROM (
                SELECT departure_airport AS airport_code
                FROM pilot_flights
                WHERE started_at >= $periodStartSql
                  AND departure_airport IS NOT NULL AND departure_airport <> '' AND departure_airport <> 'ZZZZ'
                UNION ALL
                SELECT arrival_airport AS airport_code
                FROM pilot_flights
                WHERE started_at >= $periodStartSql
                  AND arrival_airport IS NOT NULL AND arrival_airport <> '' AND arrival_airport <> 'ZZZZ'
            ) movement_rows
            GROUP BY movement_rows.airport_code
         ) routes
         LEFT JOIN airports a
           ON a.ident=routes.airport_code OR a.icao_code=routes.airport_code OR a.gps_code=routes.airport_code
         GROUP BY routes.airport_code, routes.movements
         ORDER BY movements DESC, routes.airport_code ASC
         LIMIT 10"
    );
    $airports = array_map(static function (array $row): array {
        return [
            'code' => (string)$row['airport_code'],
            'name' => (string)($row['airport_name'] ?? ''),
            'municipality' => (string)($row['municipality'] ?? ''),
            'movements' => (int)$row['movements'],
            'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null
        ];
    }, $airportStmt->fetchAll(PDO::FETCH_ASSOC));

    $countryStmt = $pdo->query(
        "SELECT UPPER(COALESCE(NULLIF(u.country_code,''),'--')) AS country_code,
                COUNT(f.id) AS flights, COUNT(DISTINCT u.id) AS pilots,
                COALESCE(SUM(f.distance_nm),0) AS distance_nm,
                COALESCE(SUM(f.duration_seconds),0) AS duration_seconds
         FROM users u
         LEFT JOIN pilot_flights f
           ON f.user_id=u.id
          AND f.started_at >= $periodStartSql
         WHERE u.is_active=1
         GROUP BY UPPER(COALESCE(NULLIF(u.country_code,''),'--'))
         ORDER BY pilots DESC, flights DESC, country_code ASC
         LIMIT 10"
    );
    $pilotCountries = array_map(static function (array $row): array {
        return [
            'code' => (string)$row['country_code'],
            'flights' => (int)$row['flights'],
            'pilots' => (int)$row['pilots'],
            'distance_nm' => round((float)$row['distance_nm'], 1),
            'duration_seconds' => (int)$row['duration_seconds']
        ];
    }, $countryStmt->fetchAll(PDO::FETCH_ASSOC));

    $movementCountryStmt = $pdo->query(
        "SELECT resolved.country_code, COUNT(*) AS movements,
                COUNT(DISTINCT resolved.airport_code) AS airports
         FROM (
            SELECT movements.airport_code,
                   COALESCE((
                       SELECT UPPER(NULLIF(a.iso_country,''))
                       FROM airports a
                       WHERE a.ident=movements.airport_code
                          OR a.icao_code=movements.airport_code
                          OR a.gps_code=movements.airport_code
                       LIMIT 1
                   ), '--') AS country_code
            FROM (
                SELECT departure_airport AS airport_code
                FROM pilot_flights
                WHERE started_at >= $periodStartSql
                  AND departure_airport IS NOT NULL AND departure_airport <> '' AND departure_airport <> 'ZZZZ'
                UNION ALL
                SELECT arrival_airport AS airport_code
                FROM pilot_flights
                WHERE started_at >= $periodStartSql
                  AND arrival_airport IS NOT NULL AND arrival_airport <> '' AND arrival_airport <> 'ZZZZ'
            ) movements
         ) resolved
         GROUP BY resolved.country_code
         ORDER BY movements DESC, airports DESC, resolved.country_code ASC
         LIMIT 10"
    );
    $movementCountries = array_map(static function (array $row): array {
        return [
            'code' => (string)$row['country_code'],
            'movements' => (int)$row['movements'],
            'airports' => (int)$row['airports']
        ];
    }, $movementCountryStmt->fetchAll(PDO::FETCH_ASSOC));
    $airportsByCountry = movementAirportsByCountry($pdo, $period);
    foreach ($movementCountries as &$movementCountry) {
        $movementCountry['airport_details'] = $airportsByCountry[$movementCountry['code']] ?? [];
    }
    unset($movementCountry);

    $pilotStmt = $pdo->query(
        "SELECT u.id AS user_id, u.username, u.real_name, u.country_code,
                COUNT(f.id) AS flights,
                COALESCE(SUM(f.distance_nm),0) AS distance_nm,
                COALESCE(SUM(f.duration_seconds),0) AS duration_seconds,
                SUM(CASE WHEN f.landing_rate_fpm IS NOT NULL THEN 1 ELSE 0 END) AS landings,
                EXISTS (
                    SELECT 1
                    FROM user_sessions s
                    INNER JOIN pilot_positions p
                       ON p.session_token=s.token
                      AND p.last_update >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
                    WHERE s.user_id=u.id
                      AND s.is_active=1
                      AND s.last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
                ) AS is_online
         FROM users u
         INNER JOIN pilot_flights f
            ON f.user_id=u.id
           AND f.status='completed'
           AND f.completed_at >= $periodStartSql
         WHERE u.is_active=1
         GROUP BY u.id, u.username, u.real_name, u.country_code
         HAVING COUNT(f.id) > 0
         ORDER BY $pilotOrderColumn DESC, flights DESC, u.username ASC
         LIMIT 10"
    );
    $topPilots = array_map(static function (array $row): array {
        return [
            'user_id' => (int)$row['user_id'],
            'username' => (string)$row['username'],
            'real_name' => (string)($row['real_name'] ?? ''),
            'country_code' => strtoupper((string)($row['country_code'] ?? '')),
            'flights' => (int)$row['flights'],
            'distance_nm' => round((float)$row['distance_nm'], 1),
            'duration_seconds' => (int)$row['duration_seconds'],
            'landings' => (int)$row['landings'],
            'online' => (int)$row['is_online'] === 1
        ];
    }, $pilotStmt->fetchAll(PDO::FETCH_ASSOC));

    $summary = $pdo->query(
        "SELECT COUNT(*) AS flights, COUNT(DISTINCT user_id) AS pilots,
                COALESCE(SUM(distance_nm),0) AS distance_nm,
                COALESCE(SUM(duration_seconds),0) AS duration_seconds
         FROM pilot_flights
         WHERE started_at >= $periodStartSql"
    )->fetch(PDO::FETCH_ASSOC);

    $heatmap = [];
    if ($includeHeatmap) {
        $heatStmt = $pdo->query(
            "SELECT ROUND(latitude, 2) AS latitude, ROUND(longitude, 2) AS longitude,
                    COUNT(*) AS point_count
             FROM pilot_tracks
             WHERE created_at >= $periodStartSql
               AND latitude BETWEEN -90 AND 90
               AND longitude BETWEEN -180 AND 180
             GROUP BY ROUND(latitude, 2), ROUND(longitude, 2)
             ORDER BY point_count DESC
             LIMIT 2500"
        );
        $heatmap = array_map(static function (array $row): array {
            return [
                'latitude' => (float)$row['latitude'],
                'longitude' => (float)$row['longitude'],
                'count' => (int)$row['point_count']
            ];
        }, $heatStmt->fetchAll(PDO::FETCH_ASSOC));
    }

    echo json_encode([
        'success' => true,
        'period_days' => $period,
        'summary' => [
            'flights' => (int)($summary['flights'] ?? 0),
            'pilots' => (int)($summary['pilots'] ?? 0),
            'distance_nm' => round((float)($summary['distance_nm'] ?? 0), 1),
            'duration_seconds' => (int)($summary['duration_seconds'] ?? 0)
        ],
        'top_airports' => $airports,
        'top_aircraft' => $topAircraft,
        'aircraft_sort' => $aircraftSort,
        'top_pilot_countries' => $pilotCountries,
        'top_movement_countries' => $movementCountries,
        'pilot_sort' => $pilotSort,
        'top_pilots' => $topPilots,
        'atc_summary' => $atcSummary,
        'top_atc_positions' => $topAtcPositions,
        'top_controllers' => $topControllers,
        'atc_sort' => $atcSort,
        'heatmap' => $heatmap
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
