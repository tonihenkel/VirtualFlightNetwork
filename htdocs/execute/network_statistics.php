<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

require_once __DIR__ . '/config.php';

$period = (int)($_GET['days'] ?? 30);
if (!in_array($period, [1, 7, 30, 90, 365], true)) {
    $period = 30;
}
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

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $airportStmt = $pdo->query(
        "SELECT routes.airport_code, routes.movements,
                MAX(a.name) AS airport_name, MAX(a.municipality) AS municipality,
                MAX(a.latitude_deg) AS latitude, MAX(a.longitude_deg) AS longitude
         FROM (
            SELECT movement_rows.airport_code, COUNT(*) AS movements
            FROM (
                SELECT departure_airport AS airport_code
                FROM pilot_flights
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
                  AND departure_airport IS NOT NULL AND departure_airport <> '' AND departure_airport <> 'ZZZZ'
                UNION ALL
                SELECT arrival_airport AS airport_code
                FROM pilot_flights
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
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
          AND f.started_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
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
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
                  AND departure_airport IS NOT NULL AND departure_airport <> '' AND departure_airport <> 'ZZZZ'
                UNION ALL
                SELECT arrival_airport AS airport_code
                FROM pilot_flights
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
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
           AND f.completed_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
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
         WHERE started_at >= DATE_SUB(NOW(), INTERVAL $period DAY)"
    )->fetch(PDO::FETCH_ASSOC);

    $heatmap = [];
    if ($includeHeatmap) {
        $heatStmt = $pdo->query(
            "SELECT ROUND(latitude, 2) AS latitude, ROUND(longitude, 2) AS longitude,
                    COUNT(*) AS point_count
             FROM pilot_tracks
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL $period DAY)
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
        'top_pilot_countries' => $pilotCountries,
        'top_movement_countries' => $movementCountries,
        'pilot_sort' => $pilotSort,
        'top_pilots' => $topPilots,
        'heatmap' => $heatmap
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
