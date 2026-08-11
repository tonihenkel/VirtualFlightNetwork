<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';

function navigationJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function navigationCoordinates($value, array &$latitudes, array &$longitudes): void
{
    if (!is_array($value)) return;
    if (count($value) >= 2 && is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null)) {
        $longitudes[] = (float)$value[0];
        $latitudes[] = (float)$value[1];
        return;
    }
    foreach ($value as $child) navigationCoordinates($child, $latitudes, $longitudes);
}

function navigationFetch(string $kind, float $latitude, float $longitude, float $radius, int $page): array
{
    $parameters = [
        'latitude' => round($latitude, 6),
        'longitude' => round($longitude, 6),
        'radius' => round($radius, 1),
        'per_page' => 100,
        'page' => $page,
    ];
    $url = 'https://airac.net/api/v1/' . $kind . '/nearby?' . http_build_query($parameters);
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vfn_atc_nav_'
        . hash('sha256', $url) . '.json';
    if (is_file($cachePath) && time() - (int)filemtime($cachePath) < 21600) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached) && isset($cached['rows'], $cached['has_more'])) return $cached;
    }
    $context = stream_context_create(['http' => [
        'timeout' => 15,
        'header' => "Accept: application/json\r\nUser-Agent: VirtualFlightNetwork-ATC/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $context);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
        return ['rows' => [], 'has_more' => false, 'failed' => true];
    }
    $data = $decoded['data'] ?? [];
    // IIS may still run PHP 8.0, where array_is_list() is unavailable.
    // Nearby endpoints normally return a numeric list; retain support for a
    // single associative result without requiring PHP 8.1.
    $isList = is_array($data)
        && ($data === [] || array_keys($data) === range(0, count($data) - 1));
    $rows = is_array($data) ? ($isList ? $data : [$data]) : [];
    $pagination = is_array($decoded['pagination'] ?? null) ? $decoded['pagination'] : [];
    $result = ['rows' => $rows, 'has_more' => (bool)($pagination['has_more'] ?? (count($rows) >= 100))];
    @file_put_contents(
        $cachePath,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $result;
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        navigationJson(['success' => false, 'message' => 'login_required'], 401);
    }
    ensureAtcSchema($pdo);
    $stmt = $pdo->prepare(
        "SELECT a.* FROM atc_sessions a
         WHERE a.user_id=:user AND a.session_token=:token AND a.is_active=1
           AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $stmt->execute([
        'user' => (int)$_SESSION['web_user_id'],
        'token' => (string)($_SESSION['atc_session_token'] ?? ''),
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) navigationJson(['success' => false, 'message' => 'atc_session_inactive'], 409);
    // Release the PHP session lock before the external AIRAC requests. Otherwise
    // the navigation loader can delay the ATC heartbeat while loading pages.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $features = readAtisScopeFeatures($session);
    $latitudes = [];
    $longitudes = [];
    foreach ($features as $feature) {
        navigationCoordinates($feature['geometry']['coordinates'] ?? [], $latitudes, $longitudes);
    }
    if ($latitudes && $longitudes) {
        $latitude = (min($latitudes) + max($latitudes)) / 2;
        $longitude = (min($longitudes) + max($longitudes)) / 2;
        $radius = max(20.0, min(600.0, atisDistanceNm(
            $latitude, $longitude, min($latitudes), min($longitudes)
        ) * 1.15));
    } else {
        $airport = $pdo->prepare(
            "SELECT latitude_deg,longitude_deg FROM airports
             WHERE UPPER(ident)=:code OR UPPER(icao_code)=:code OR UPPER(gps_code)=:code LIMIT 1"
        );
        $airport->execute(['code' => normalizeAtcStationCode((string)$session['station_code'])]);
        $row = $airport->fetch(PDO::FETCH_ASSOC);
        if (!$row) navigationJson(['success' => true, 'points' => []]);
        $latitude = (float)$row['latitude_deg'];
        $longitude = (float)$row['longitude_deg'];
        $radius = in_array(strtoupper((string)$session['position_code']), ['APP', 'DEP'], true) ? 150.0 : 35.0;
    }

    $page = max(1, min(100, (int)($_GET['page'] ?? 1)));
    $points = [];
    $hasMore = false;
    $sourceAvailable = false;
    foreach (['waypoints' => 'waypoint', 'navaids' => 'navaid'] as $endpoint => $kind) {
        $source = navigationFetch($endpoint, $latitude, $longitude, $radius, $page);
        $hasMore = $hasMore || (bool)($source['has_more'] ?? false);
        $sourceAvailable = $sourceAvailable || empty($source['failed']);
        foreach ((array)($source['rows'] ?? []) as $item) {
            $coordinates = (array)($item['coordinates'] ?? []);
            $lat = $coordinates['lat'] ?? $item['latitude'] ?? null;
            $lon = $coordinates['lon'] ?? $item['longitude'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) continue;
            if ($features) {
                $inside = false;
                foreach ($features as $feature) {
                    if (pointInAtisGeometry((float)$lon, (float)$lat, $feature['geometry'] ?? [])) {
                        $inside = true;
                        break;
                    }
                }
                if (!$inside) continue;
            }
            $identifier = strtoupper(trim((string)($item['identifier'] ?? '')));
            if ($identifier === '') continue;
            $key = $kind . ':' . $identifier . ':' . round((float)$lat, 5) . ':' . round((float)$lon, 5);
            $points[$key] = [
                'identifier' => $identifier,
                'kind' => $kind,
                'latitude' => (float)$lat,
                'longitude' => (float)$lon,
                'frequency' => $item['frequency'] ?? null,
            ];
        }
    }
    if (!$sourceAvailable) navigationJson(['success' => false, 'message' => 'airac_unavailable'], 502);
    navigationJson(['success' => true, 'page' => $page, 'has_more' => $hasMore, 'points' => array_values($points)]);
} catch (Throwable $error) {
    error_log('ATC navigation failed: ' . $error->getMessage());
    navigationJson(['success' => false, 'message' => 'navigation_unavailable'], 500);
}
