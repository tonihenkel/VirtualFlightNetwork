<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';

function airportH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function airportDuration(int $seconds): string
{
    $hours = intdiv(max(0, $seconds), 3600);
    $minutes = intdiv(max(0, $seconds) % 3600, 60);
    return sprintf('%02d:%02d', $hours, $minutes);
}

$requestedCode = strtoupper(trim((string)($_GET['icao'] ?? '')));
$airport = null;
$summary = [
    'movements' => 0,
    'departures' => 0,
    'arrivals' => 0,
    'pilots' => 0,
];
$liveFlights = [];
$recentFlights = [];
$topAircraft = [];
$topRoutes = [];
$topPilots = [];
$loadError = false;

if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,14}$/', $requestedCode)) {
    try {
        $pdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $airportStmt = $pdo->prepare(
            "SELECT ident, name, municipality, latitude_deg, longitude_deg,
                    icao_code, gps_code, iso_country, type, elevation_ft,
                    iata_code
             FROM airports
             WHERE ident = :code OR icao_code = :code OR gps_code = :code
             ORDER BY CASE WHEN icao_code = :code_order THEN 0 ELSE 1 END
             LIMIT 1"
        );
        $airportStmt->execute([
            'code' => $requestedCode,
            'code_order' => $requestedCode,
        ]);
        $airport = $airportStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($airport) {
            $airportCode = strtoupper(trim((string)(
                $airport['icao_code']
                ?: $airport['gps_code']
                ?: $airport['ident']
            )));

            $summaryStmt = $pdo->prepare(
                "SELECT
                    COUNT(*) AS movements,
                    SUM(CASE WHEN departure_airport = :departure THEN 1 ELSE 0 END)
                        AS departures,
                    SUM(CASE WHEN arrival_airport = :arrival THEN 1 ELSE 0 END)
                        AS arrivals,
                    COUNT(DISTINCT user_id) AS pilots
                 FROM pilot_flights
                 WHERE status = 'completed'
                   AND (departure_airport = :code1 OR arrival_airport = :code2)"
            );
            $summaryStmt->execute([
                'departure' => $airportCode,
                'arrival' => $airportCode,
                'code1' => $airportCode,
                'code2' => $airportCode,
            ]);
            $summary = array_merge(
                $summary,
                $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []
            );

            $liveStmt = $pdo->prepare(
                "SELECT p.user_id, p.callsign, p.aircraft_icao, p.altitude,
                        p.airspeed, p.last_update, u.username,
                        fp.departure_airport, fp.arrival_airport
                 FROM pilot_positions p
                 INNER JOIN user_sessions s
                    ON s.token = p.session_token AND s.is_active = 1
                 INNER JOIN users u ON u.id = p.user_id
                 LEFT JOIN pilot_flightplans fp
                    ON fp.session_token = p.session_token
                 WHERE s.is_invisible = 0
                   AND p.last_update >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
                   AND (
                        fp.departure_airport = :departure
                        OR fp.arrival_airport = :arrival
                   )
                 ORDER BY p.callsign"
            );
            $liveStmt->execute([
                'departure' => $airportCode,
                'arrival' => $airportCode,
            ]);
            $liveFlights = $liveStmt->fetchAll(PDO::FETCH_ASSOC);

            $recentStmt = $pdo->prepare(
                "SELECT f.user_id, f.callsign, f.aircraft_icao,
                        f.departure_airport, f.arrival_airport, f.completed_at,
                        f.duration_seconds, f.distance_nm, f.landing_rate_fpm,
                        u.username
                 FROM pilot_flights f
                 INNER JOIN users u ON u.id = f.user_id
                 WHERE f.status = 'completed'
                   AND (
                        f.departure_airport = :departure
                        OR f.arrival_airport = :arrival
                   )
                 ORDER BY f.completed_at DESC
                 LIMIT 12"
            );
            $recentStmt->execute([
                'departure' => $airportCode,
                'arrival' => $airportCode,
            ]);
            $recentFlights = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

            $aircraftStmt = $pdo->prepare(
                "SELECT aircraft_icao, COUNT(*) AS movements
                 FROM pilot_flights
                 WHERE status = 'completed'
                   AND (departure_airport = :departure OR arrival_airport = :arrival)
                   AND aircraft_icao <> ''
                 GROUP BY aircraft_icao
                 ORDER BY movements DESC, aircraft_icao
                 LIMIT 8"
            );
            $aircraftStmt->execute([
                'departure' => $airportCode,
                'arrival' => $airportCode,
            ]);
            $topAircraft = $aircraftStmt->fetchAll(PDO::FETCH_ASSOC);

            $routeStmt = $pdo->prepare(
                "SELECT
                    CASE
                        WHEN departure_airport = :airport
                            THEN arrival_airport
                        ELSE departure_airport
                    END AS other_airport,
                    COUNT(*) AS flights
                 FROM pilot_flights
                 WHERE status = 'completed'
                   AND (departure_airport = :departure OR arrival_airport = :arrival)
                 GROUP BY other_airport
                 HAVING other_airport IS NOT NULL
                    AND other_airport <> ''
                    AND other_airport <> 'ZZZZ'
                 ORDER BY flights DESC, other_airport
                 LIMIT 8"
            );
            $routeStmt->execute([
                'airport' => $airportCode,
                'departure' => $airportCode,
                'arrival' => $airportCode,
            ]);
            $topRoutes = $routeStmt->fetchAll(PDO::FETCH_ASSOC);

            $pilotStmt = $pdo->prepare(
                "SELECT f.user_id, u.username, u.real_name, COUNT(*) AS movements
                 FROM pilot_flights f
                 INNER JOIN users u ON u.id = f.user_id
                 WHERE f.status = 'completed'
                   AND (f.departure_airport = :departure OR f.arrival_airport = :arrival)
                 GROUP BY f.user_id, u.username, u.real_name
                 ORDER BY movements DESC, u.username
                 LIMIT 8"
            );
            $pilotStmt->execute([
                'departure' => $airportCode,
                'arrival' => $airportCode,
            ]);
            $topPilots = $pilotStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $error) {
        error_log('Airport page failed: ' . $error->getMessage());
        $loadError = true;
    }
}

$pageCode = $airportCode ?? $requestedCode;
$pageTitle = $airport
    ? $pageCode . ' – ' . (string)$airport['name']
    : t('airport_not_found_title');
?>
<!doctype html>
<html lang="<?php echo airportH($currentLanguage); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo airportH($pageTitle); ?> - <?php echo airportH($projectName); ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        *{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 15% 0,rgba(0,132,255,.18),transparent 34%),#07141f;color:#d7e8ff;font-family:Arial,sans-serif}
        .airport-shell{width:min(1480px,calc(100% - 36px));margin:34px auto 50px}.airport-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:20px}
        .airport-code{color:#55e9c1;font-size:15px;letter-spacing:2px;text-transform:uppercase}.airport-hero h1{font-size:38px;margin:6px 0}.airport-muted{color:#93acc3}
        .airport-actions{display:flex;gap:10px;flex-wrap:wrap}.airport-button{display:inline-block;padding:10px 14px;border:1px solid #2976ad;border-radius:5px;background:#176dcc;color:#fff;text-decoration:none}
        .airport-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.airport-stat,.airport-card{background:#0d1d2a;border:1px solid #285475;border-radius:8px;padding:18px}
        .airport-stat strong{display:block;color:#55e9c1;font-size:28px;margin-bottom:4px}.airport-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(340px,.8fr);gap:18px;margin-bottom:18px}
        #airportMap{height:410px;border-radius:6px;background:#081925}.airport-card h2{margin:0 0 14px}.airport-details{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .airport-detail{padding:11px;background:#081925;border-radius:5px}.airport-detail span{display:block;color:#7fa4c3;font-size:12px;margin-bottom:4px}
        .airport-metar{font-family:Consolas,monospace;line-height:1.6;color:#d9f6ff;word-break:break-word}.airport-metar-time{color:#7fa4c3;font:12px Arial,sans-serif;margin-top:8px}
        .airport-columns{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-bottom:18px}.airport-ranking{display:grid;gap:8px}.airport-rank{display:grid;grid-template-columns:34px 1fr auto;gap:9px;align-items:center;padding:10px;background:#081925;border-radius:5px}
        .airport-rank-number{font-weight:bold;color:#55aaff}.airport-link{color:#65bdff;text-decoration:none}.airport-live{display:grid;gap:8px}.airport-live-row{display:grid;grid-template-columns:1fr auto;gap:10px;padding:11px;background:#081925;border-radius:5px}
        .airport-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#36c64b;box-shadow:0 0 7px #36c64b;margin-right:7px}
        .airport-table-wrap{overflow-x:auto}.airport-table{width:100%;border-collapse:collapse}.airport-table th,.airport-table td{padding:11px 9px;border-bottom:1px solid #24445c;text-align:left;white-space:nowrap}.airport-table th{color:#75bfff}
        .airport-empty{color:#7fa4c3}.airport-error{max-width:720px;margin:70px auto;padding:28px;background:#0d1d2a;border:1px solid #733;border-radius:8px;text-align:center}
        @media(max-width:1000px){.airport-grid,.airport-columns{grid-template-columns:1fr}.airport-summary{grid-template-columns:1fr 1fr}}@media(max-width:620px){.airport-shell{width:min(100% - 20px,1480px)}.airport-hero{align-items:flex-start;flex-direction:column}.airport-hero h1{font-size:30px}.airport-summary{grid-template-columns:1fr}.airport-details{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header.php'; ?>

<?php if (!$airport || $loadError): ?>
    <main class="airport-shell">
        <div class="airport-error">
            <h1><?php echo airportH(t('airport_not_found_title')); ?></h1>
            <p><?php echo airportH(t('airport_not_found_text')); ?></p>
            <a class="airport-button" href="map.php"><?php echo airportH(t('airport_back_to_map')); ?></a>
        </div>
    </main>
<?php else: ?>
    <main class="airport-shell">
        <section class="airport-hero">
            <div>
                <div class="airport-code"><?php echo airportH($pageCode); ?></div>
                <h1><?php echo airportH($airport['name']); ?></h1>
                <div class="airport-muted">
                    <?php echo airportH($airport['municipality'] ?: t('airport_unknown_location')); ?>
                    <?php if (!empty($airport['iso_country'])): ?>
                        · <?php echo airportH($airport['iso_country']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="airport-actions">
                <a class="airport-button" href="map.php?airport=<?php echo rawurlencode($pageCode); ?>"><?php echo airportH(t('airport_open_map')); ?></a>
            </div>
        </section>

        <section class="airport-summary">
            <div class="airport-stat"><strong><?php echo (int)$summary['movements']; ?></strong><?php echo airportH(t('airport_movements')); ?></div>
            <div class="airport-stat"><strong><?php echo (int)$summary['departures']; ?></strong><?php echo airportH(t('airport_departures')); ?></div>
            <div class="airport-stat"><strong><?php echo (int)$summary['arrivals']; ?></strong><?php echo airportH(t('airport_arrivals')); ?></div>
            <div class="airport-stat"><strong><?php echo (int)$summary['pilots']; ?></strong><?php echo airportH(t('airport_unique_pilots')); ?></div>
        </section>

        <section class="airport-grid">
            <div class="airport-card"><div id="airportMap"></div></div>
            <div class="airport-card">
                <h2><?php echo airportH(t('airport_information')); ?></h2>
                <div class="airport-details">
                    <div class="airport-detail"><span><?php echo airportH(t('airport_icao')); ?></span><?php echo airportH($pageCode); ?></div>
                    <div class="airport-detail"><span><?php echo airportH(t('airport_iata')); ?></span><?php echo airportH($airport['iata_code'] ?: '—'); ?></div>
                    <div class="airport-detail"><span><?php echo airportH(t('airport_type')); ?></span><?php echo airportH($airport['type'] ?: '—'); ?></div>
                    <div class="airport-detail"><span><?php echo airportH(t('airport_elevation')); ?></span><?php echo $airport['elevation_ft'] !== null ? airportH($airport['elevation_ft']) . ' ft' : '—'; ?></div>
                    <div class="airport-detail"><span><?php echo airportH(t('airport_latitude')); ?></span><?php echo airportH(number_format((float)$airport['latitude_deg'], 6, '.', '')); ?></div>
                    <div class="airport-detail"><span><?php echo airportH(t('airport_longitude')); ?></span><?php echo airportH(number_format((float)$airport['longitude_deg'], 6, '.', '')); ?></div>
                </div>
                <h2 style="margin-top:20px"><?php echo airportH(t('airport_metar')); ?></h2>
                <div id="airportMetar" class="airport-metar"><?php echo airportH(t('airport_metar_loading')); ?></div>
            </div>
        </section>

        <section class="airport-card" style="margin-bottom:18px">
            <h2><?php echo airportH(t('airport_live_traffic')); ?></h2>
            <?php if (!$liveFlights): ?>
                <p class="airport-empty"><?php echo airportH(t('airport_no_live_traffic')); ?></p>
            <?php else: ?>
                <div class="airport-live">
                    <?php foreach ($liveFlights as $flight): ?>
                        <div class="airport-live-row">
                            <div>
                                <span class="airport-dot"></span>
                                <a class="airport-link" href="profile.php?id=<?php echo (int)$flight['user_id']; ?>"><?php echo airportH($flight['callsign']); ?></a>
                                <span class="airport-muted"> · <?php echo airportH($flight['aircraft_icao']); ?> · <?php echo airportH(($flight['departure_airport'] ?: 'ZZZZ') . ' → ' . ($flight['arrival_airport'] ?: 'ZZZZ')); ?></span>
                            </div>
                            <a class="airport-link" href="map.php?pilot_id=<?php echo (int)$flight['user_id']; ?>&follow=1"><?php echo airportH(t('airport_follow')); ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="airport-columns">
            <div class="airport-card"><h2><?php echo airportH(t('airport_top_routes')); ?></h2><div class="airport-ranking">
                <?php foreach ($topRoutes as $index => $route): ?><div class="airport-rank"><span class="airport-rank-number"><?php echo $index + 1; ?></span><a class="airport-link" href="airport.php?icao=<?php echo rawurlencode($route['other_airport']); ?>"><?php echo airportH($pageCode . ' ↔ ' . $route['other_airport']); ?></a><strong><?php echo (int)$route['flights']; ?></strong></div><?php endforeach; ?>
                <?php if (!$topRoutes): ?><p class="airport-empty"><?php echo airportH(t('airport_no_statistics')); ?></p><?php endif; ?>
            </div></div>
            <div class="airport-card"><h2><?php echo airportH(t('airport_top_aircraft')); ?></h2><div class="airport-ranking">
                <?php foreach ($topAircraft as $index => $entry): ?><div class="airport-rank"><span class="airport-rank-number"><?php echo $index + 1; ?></span><span><?php echo airportH($entry['aircraft_icao']); ?></span><strong><?php echo (int)$entry['movements']; ?></strong></div><?php endforeach; ?>
                <?php if (!$topAircraft): ?><p class="airport-empty"><?php echo airportH(t('airport_no_statistics')); ?></p><?php endif; ?>
            </div></div>
            <div class="airport-card"><h2><?php echo airportH(t('airport_top_pilots')); ?></h2><div class="airport-ranking">
                <?php foreach ($topPilots as $index => $pilot): ?><div class="airport-rank"><span class="airport-rank-number"><?php echo $index + 1; ?></span><a class="airport-link" href="profile.php?id=<?php echo (int)$pilot['user_id']; ?>"><?php echo airportH($pilot['real_name'] ?: $pilot['username']); ?></a><strong><?php echo (int)$pilot['movements']; ?></strong></div><?php endforeach; ?>
                <?php if (!$topPilots): ?><p class="airport-empty"><?php echo airportH(t('airport_no_statistics')); ?></p><?php endif; ?>
            </div></div>
        </section>

        <section class="airport-card">
            <h2><?php echo airportH(t('airport_recent_flights')); ?></h2>
            <?php if (!$recentFlights): ?>
                <p class="airport-empty"><?php echo airportH(t('airport_no_recent_flights')); ?></p>
            <?php else: ?>
                <div class="airport-table-wrap"><table class="airport-table"><thead><tr>
                    <th><?php echo airportH(t('airport_time')); ?></th><th><?php echo airportH(t('airport_pilot')); ?></th><th><?php echo airportH(t('airport_route')); ?></th><th><?php echo airportH(t('airport_aircraft')); ?></th><th><?php echo airportH(t('airport_duration')); ?></th><th><?php echo airportH(t('airport_distance')); ?></th><th><?php echo airportH(t('airport_landing_rate')); ?></th>
                </tr></thead><tbody>
                    <?php foreach ($recentFlights as $flight): ?><tr>
                        <td><?php echo airportH(date('d.m.Y H:i', strtotime((string)$flight['completed_at']))); ?></td>
                        <td><a class="airport-link" href="profile.php?id=<?php echo (int)$flight['user_id']; ?>"><?php echo airportH($flight['callsign']); ?></a></td>
                        <td><a class="airport-link" href="airport.php?icao=<?php echo rawurlencode($flight['departure_airport']); ?>"><?php echo airportH($flight['departure_airport']); ?></a> → <a class="airport-link" href="airport.php?icao=<?php echo rawurlencode($flight['arrival_airport']); ?>"><?php echo airportH($flight['arrival_airport']); ?></a></td>
                        <td><?php echo airportH($flight['aircraft_icao']); ?></td>
                        <td><?php echo airportH(airportDuration((int)$flight['duration_seconds'])); ?></td>
                        <td><?php echo airportH(number_format((float)$flight['distance_nm'], 1, ',', '.')); ?> NM</td>
                        <td><?php echo $flight['landing_rate_fpm'] !== null ? airportH($flight['landing_rate_fpm']) . ' fpm' : '—'; ?></td>
                    </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    const airportPosition = [
        <?php echo json_encode((float)$airport['latitude_deg']); ?>,
        <?php echo json_encode((float)$airport['longitude_deg']); ?>
    ];
    const airportMap = L.map('airportMap').setView(airportPosition, 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 20,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(airportMap);
    L.circleMarker(airportPosition, {
        radius: 9, color: '#fff', weight: 3,
        fillColor: '#168cff', fillOpacity: 1
    }).addTo(airportMap).bindPopup(
        <?php echo json_encode($pageCode . ' – ' . $airport['name'], JSON_UNESCAPED_UNICODE); ?>
    ).openPopup();

    fetch('execute/airport_metar.php?airport=<?php echo rawurlencode($pageCode); ?>')
        .then(response => response.json())
        .then(data => {
            const box = document.getElementById('airportMetar');
            if (!data.success) throw new Error();
            box.textContent = data.raw_text || '—';
            if (data.observed_at) {
                const time = document.createElement('div');
                time.className = 'airport-metar-time';
                time.textContent = <?php echo json_encode(t('airport_observed_at')); ?>
                    + ': ' + data.observed_at;
                box.appendChild(time);
            }
        })
        .catch(() => {
            document.getElementById('airportMetar').textContent =
                <?php echo json_encode(t('airport_metar_unavailable')); ?>;
        });
    </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/auth_modals.php'; ?>
</body>
</html>
