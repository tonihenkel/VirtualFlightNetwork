<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function flightDistanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 3440.065 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}

function flightDuration(int $seconds): string
{
    return sprintf('%d:%02d h', intdiv(max(0, $seconds), 3600), intdiv(max(0, $seconds) % 3600, 60));
}

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
    header('Location:index.php?type=error&message=login_required');
    exit;
}

$id = max(0, (int)($_GET['id'] ?? 0));
$stmt = $pdo->prepare(
    "SELECT f.*, u.username, u.real_name
     FROM pilot_flights f
     INNER JOIN users u ON u.id = f.user_id
     WHERE f.id = :id LIMIT 1"
);
$stmt->execute(['id' => $id]);
$flight = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$flight) {
    http_response_code(404);
}

$allPoints = [];
$mapPoints = [];
$chartPoints = [];
$analysis = null;
if ($flight) {
    $track = $pdo->prepare(
        "SELECT latitude, longitude, altitude, heading, created_at
         FROM pilot_tracks
         WHERE session_token = :token AND callsign = :callsign
         ORDER BY id ASC"
    );
    $track->execute([
        'token' => $flight['session_token'],
        'callsign' => $flight['callsign'],
    ]);
    $allPoints = $track->fetchAll(PDO::FETCH_ASSOC);
    $mapStep = max(1, (int)ceil(count($allPoints) / 2500));
    $chartStep = max(1, (int)ceil(count($allPoints) / 600));
    foreach ($allPoints as $index => $point) {
        $normalized = [
            'lat' => (float)$point['latitude'],
            'lon' => (float)$point['longitude'],
            'alt' => (float)$point['altitude'],
            'time' => (string)$point['created_at'],
            'timestamp' => strtotime((string)$point['created_at']) ?: 0,
        ];
        if ($index % $mapStep === 0 || $index === count($allPoints) - 1) {
            $mapPoints[] = $normalized;
        }
    }

    if (count($allPoints) >= 2) {
        $trackedDistance = 0.0;
        $speedSum = 0.0;
        $speedSamples = 0;
        $maxSpeed = 0.0;
        $maxAltitude = -INF;
        $minAltitude = INF;
        $maxClimb = 0.0;
        $maxDescent = 0.0;
        $phaseSeconds = ['climb' => 0, 'level' => 0, 'descent' => 0];
        $previous = null;
        foreach ($allPoints as $index => $raw) {
            $current = [
                'lat' => (float)$raw['latitude'],
                'lon' => (float)$raw['longitude'],
                'alt' => (float)$raw['altitude'],
                'timestamp' => strtotime((string)$raw['created_at']) ?: 0,
            ];
            $maxAltitude = max($maxAltitude, $current['alt']);
            $minAltitude = min($minAltitude, $current['alt']);
            $speed = null;
            if ($previous !== null) {
                $seconds = $current['timestamp'] - $previous['timestamp'];
                if ($seconds >= 1 && $seconds <= 600) {
                    $distance = flightDistanceNm(
                        $previous['lat'], $previous['lon'], $current['lat'], $current['lon']
                    );
                    $candidateSpeed = $distance / ($seconds / 3600);
                    if ($candidateSpeed <= 1500 && $distance <= 50) {
                        $trackedDistance += $distance;
                        $speed = $candidateSpeed;
                        if ($speed >= 30) {
                            $speedSum += $speed;
                            $speedSamples++;
                            $maxSpeed = max($maxSpeed, $speed);
                        }
                    }
                    $verticalRate = ($current['alt'] - $previous['alt']) / ($seconds / 60);
                    if (abs($verticalRate) <= 12000) {
                        $maxClimb = max($maxClimb, $verticalRate);
                        $maxDescent = min($maxDescent, $verticalRate);
                        $phase = $verticalRate > 300
                            ? 'climb' : ($verticalRate < -300 ? 'descent' : 'level');
                        $phaseSeconds[$phase] += $seconds;
                    }
                }
            }
            if ($index % $chartStep === 0 || $index === count($allPoints) - 1) {
                $chartPoints[] = [
                    'time' => $current['timestamp'],
                    'altitude' => $current['alt'],
                    'speed' => $speed,
                ];
            }
            $previous = $current;
        }
        $first = $allPoints[0];
        $last = $allPoints[count($allPoints) - 1];
        $directDistance = flightDistanceNm(
            (float)$first['latitude'], (float)$first['longitude'],
            (float)$last['latitude'], (float)$last['longitude']
        );
        $analysis = [
            'tracked_distance' => $trackedDistance,
            'direct_distance' => $directDistance,
            'efficiency' => $trackedDistance > 0
                ? min(100.0, $directDistance / $trackedDistance * 100) : 0.0,
            'max_altitude' => is_finite($maxAltitude) ? $maxAltitude : 0,
            'min_altitude' => is_finite($minAltitude) ? $minAltitude : 0,
            'average_speed' => $speedSamples > 0 ? $speedSum / $speedSamples : 0,
            'max_speed' => $maxSpeed,
            'max_climb' => $maxClimb,
            'max_descent' => $maxDescent,
            'phase_seconds' => $phaseSeconds,
            'points' => count($allPoints),
        ];
    }
}
?>
<!doctype html>
<html lang="<?php echo h($currentLanguage); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo h(t('flight_details_title')); ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        body{margin:0;background:#07141f;color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1300px,calc(100% - 36px));margin:28px auto}.card{background:#0d1d2a;border:1px solid #285475;border-radius:8px;padding:18px;margin-bottom:16px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.stat{background:#081925;border:1px solid #18384d;padding:14px;border-radius:6px}.stat span{display:block;color:#8ba7bf;font-size:12px;margin-bottom:5px}.stat strong{color:#55e9c1;font-size:18px}.route{font-size:25px;color:#7fc8ff}.analysis-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.chart{height:220px;background:#081925;border-radius:6px;padding:10px}.chart svg{width:100%;height:100%;overflow:visible}.chart-line{fill:none;stroke:#55e9c1;stroke-width:2}.chart-speed{stroke:#ff9a3c}.chart-grid{stroke:#24465c;stroke-width:1}.phase-bar{display:flex;height:18px;border-radius:10px;overflow:hidden;background:#142b39;margin:10px 0}.phase-climb{background:#4cff9b}.phase-level{background:#51a8ff}.phase-descent{background:#ff9a3c}.phase-labels{display:flex;justify-content:space-between;color:#9db8cc;font-size:12px}#flightMap{height:540px;border-radius:7px}a{color:#65bdff}@media(max-width:850px){.stats{grid-template-columns:1fr 1fr}.analysis-grid{grid-template-columns:1fr}#flightMap{height:400px}}@media(max-width:500px){.stats{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header.php'; ?>
<main class="shell">
<?php if (!$flight): ?>
    <section class="card"><h1><?php echo h(t('flight_not_found')); ?></h1></section>
<?php else: ?>
    <section class="card">
        <h1><?php echo h($flight['callsign']); ?> · <?php echo h($flight['aircraft_icao']); ?></h1>
        <p><a href="profile.php?id=<?php echo (int)$flight['user_id']; ?>"><?php echo h($flight['real_name'] ?: $flight['username']); ?></a></p>
        <div class="route"><?php echo h($flight['departure_airport'] ?: 'ZZZZ'); ?> → <?php echo h($flight['arrival_airport'] ?: 'ZZZZ'); ?></div>
    </section>
    <section class="stats">
        <div class="stat"><span><?php echo h(t('profile_flight_date')); ?></span><strong><?php echo h(date('d.m.Y H:i', strtotime($flight['started_at']))); ?></strong></div>
        <div class="stat"><span><?php echo h(t('profile_flight_duration')); ?></span><strong><?php echo h(flightDuration((int)$flight['duration_seconds'])); ?></strong></div>
        <div class="stat"><span><?php echo h(t('profile_flight_distance')); ?></span><strong><?php echo h(number_format((float)$flight['distance_nm'], 1, ',', '.')); ?> NM</strong></div>
        <div class="stat"><span><?php echo h(t('profile_flight_landing_rate')); ?></span><strong><?php echo $flight['landing_rate_fpm'] !== null ? h($flight['landing_rate_fpm']) . ' fpm' : '—'; ?></strong></div>
    </section>
    <?php if ($analysis): ?>
    <section class="card">
        <h2><?php echo h(t('flight_analysis_title')); ?></h2>
        <div class="stats">
            <div class="stat"><span><?php echo h(t('flight_analysis_max_altitude')); ?></span><strong><?php echo h(number_format($analysis['max_altitude'], 0, ',', '.')); ?> ft</strong></div>
            <div class="stat"><span><?php echo h(t('flight_analysis_average_speed')); ?></span><strong><?php echo h(number_format($analysis['average_speed'], 0, ',', '.')); ?> kt</strong></div>
            <div class="stat"><span><?php echo h(t('flight_analysis_max_speed')); ?></span><strong><?php echo h(number_format($analysis['max_speed'], 0, ',', '.')); ?> kt</strong></div>
            <div class="stat"><span><?php echo h(t('flight_analysis_efficiency')); ?></span><strong><?php echo h(number_format($analysis['efficiency'], 1, ',', '.')); ?> %</strong></div>
            <div class="stat"><span><?php echo h(t('flight_analysis_max_climb')); ?></span><strong><?php echo h(number_format($analysis['max_climb'], 0, ',', '.')); ?> fpm</strong></div>
            <div class="stat"><span><?php echo h(t('flight_analysis_max_descent')); ?></span><strong><?php echo h(number_format($analysis['max_descent'], 0, ',', '.')); ?> fpm</strong></div>
            <div class="stat"><span><?php echo h(t('flight_analysis_tracked_distance')); ?></span><strong><?php echo h(number_format($analysis['tracked_distance'], 1, ',', '.')); ?> NM</strong></div>
            <div class="stat"><span><?php echo h(t('flight_analysis_track_points')); ?></span><strong><?php echo h($analysis['points']); ?></strong></div>
        </div>
        <?php $phaseTotal = max(1, array_sum($analysis['phase_seconds'])); ?>
        <h3><?php echo h(t('flight_analysis_phases')); ?></h3>
        <div class="phase-bar">
            <span class="phase-climb" style="width:<?php echo 100*$analysis['phase_seconds']['climb']/$phaseTotal; ?>%"></span>
            <span class="phase-level" style="width:<?php echo 100*$analysis['phase_seconds']['level']/$phaseTotal; ?>%"></span>
            <span class="phase-descent" style="width:<?php echo 100*$analysis['phase_seconds']['descent']/$phaseTotal; ?>%"></span>
        </div>
        <div class="phase-labels"><span><?php echo h(t('flight_analysis_climb')); ?> <?php echo h(flightDuration($analysis['phase_seconds']['climb'])); ?></span><span><?php echo h(t('flight_analysis_level')); ?> <?php echo h(flightDuration($analysis['phase_seconds']['level'])); ?></span><span><?php echo h(t('flight_analysis_descent')); ?> <?php echo h(flightDuration($analysis['phase_seconds']['descent'])); ?></span></div>
        <div class="analysis-grid">
            <div><h3><?php echo h(t('flight_analysis_altitude_profile')); ?></h3><div class="chart" id="altitudeChart"></div></div>
            <div><h3><?php echo h(t('flight_analysis_speed_profile')); ?></h3><div class="chart" id="speedChart"></div></div>
        </div>
    </section>
    <?php endif; ?>
    <section class="card"><h2><?php echo h(t('flight_route_map')); ?></h2><?php if (!$mapPoints): ?><p><?php echo h(t('flight_route_unavailable')); ?></p><?php else: ?><div id="flightMap"></div><?php endif; ?></section>
<?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php if ($mapPoints): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const points=<?php echo json_encode($mapPoints, JSON_UNESCAPED_SLASHES); ?>;
const map=L.map('flightMap');L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map);
const line=L.polyline(points.map(p=>[p.lat,p.lon]),{color:'#168cff',weight:4}).addTo(map);
L.circleMarker([points[0].lat,points[0].lon],{color:'#4cff9b',radius:7}).addTo(map);
L.circleMarker([points.at(-1).lat,points.at(-1).lon],{color:'#ff6464',radius:7}).addTo(map);map.fitBounds(line.getBounds(),{padding:[25,25]});
</script>
<?php endif; ?>
<?php if ($chartPoints): ?>
<script>
const chartPoints=<?php echo json_encode($chartPoints, JSON_UNESCAPED_SLASHES); ?>;
function drawProfile(target,key,className){const el=document.getElementById(target);if(!el)return;const numeric=p=>p[key]===null?NaN:Number(p[key]);const values=chartPoints.map(numeric).filter(Number.isFinite);if(values.length<2)return;const min=Math.min(...values),max=Math.max(...values),range=Math.max(1,max-min);const plot=chartPoints.map((p,i)=>{const value=numeric(p);if(!Number.isFinite(value))return null;return `${(i/(chartPoints.length-1)*100).toFixed(2)},${(96-(value-min)/range*88).toFixed(2)}`}).filter(Boolean).join(' ');el.innerHTML=`<svg viewBox="0 0 100 100" preserveAspectRatio="none"><path class="chart-grid" d="M0 25H100M0 50H100M0 75H100"/><polyline class="chart-line ${className}" points="${plot}"/></svg>`;}
drawProfile('altitudeChart','altitude','');drawProfile('speedChart','speed','chart-speed');
</script>
<?php endif; ?>
</body></html>
