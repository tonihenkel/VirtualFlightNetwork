<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';

const VFN_ROUTE_AIRAC_BASE_URL = 'https://airac.net/api/v1';
const VFN_ROUTE_CACHE_SECONDS = 86400;
const VFN_ROUTE_MAX_TOKENS = 30;

function routeJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function routeDistanceNm(array $a, array $b): float
{
    $lat1 = deg2rad((float)$a['latitude']);
    $lat2 = deg2rad((float)$b['latitude']);
    $deltaLat = $lat2 - $lat1;
    $deltaLon = deg2rad((float)$b['longitude'] - (float)$a['longitude']);
    $value = sin($deltaLat / 2) ** 2
        + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
    return 3440.065 * 2 * atan2(sqrt($value), sqrt(max(0.0, 1.0 - $value)));
}

function routeAirport(PDO $pdo, string $code): ?array
{
    if ($code === '' || $code === 'ZZZZ') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT ident, icao_code, gps_code, latitude_deg, longitude_deg
         FROM airports
         WHERE ident = :code OR icao_code = :code OR gps_code = :code
         LIMIT 1'
    );
    $stmt->execute(['code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_numeric($row['latitude_deg']) || !is_numeric($row['longitude_deg'])) {
        return null;
    }
    return [
        'identifier' => $code,
        'kind' => 'airport',
        'latitude' => (float)$row['latitude_deg'],
        'longitude' => (float)$row['longitude_deg'],
    ];
}

function routeCachedFetch(string $cacheKey, string $url): ?array
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'vfn_route_' . hash('sha256', $cacheKey) . '.json';
    if (is_file($path) && time() - (int)filemtime($path) < VFN_ROUTE_CACHE_SECONDS) {
        $cached = json_decode((string)@file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $context = stream_context_create(['http' => [
        'timeout' => 10,
        'header' => "Accept: application/json\r\nUser-Agent: VirtualFlightNetwork/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    @file_put_contents(
        $path,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $data;
}

function routeCandidates(string $identifier): array
{
    $url = VFN_ROUTE_AIRAC_BASE_URL . '/search?'
        . http_build_query(['q' => $identifier, 'limit' => 8])
        . '&types=waypoint&types=navaid';
    $response = routeCachedFetch('point_' . $identifier, $url);
    if (!is_array($response) || ($response['status'] ?? '') !== 'success') {
        return [];
    }
    $result = [];
    foreach (['waypoints' => 'waypoint', 'navaids' => 'navaid'] as $group => $kind) {
        foreach ((array)($response['data'][$group] ?? []) as $item) {
            if (strtoupper(trim((string)($item['identifier'] ?? ''))) !== $identifier) {
                continue;
            }
            $coordinates = (array)($item['coordinates'] ?? []);
            $latitude = $coordinates['lat'] ?? $item['latitude'] ?? null;
            $longitude = $coordinates['lon'] ?? $item['longitude'] ?? null;
            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            $result[] = [
                'identifier' => $identifier,
                'kind' => $kind,
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
            ];
        }
    }
    return $result;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        routeJson(['success' => false, 'message' => 'login_required'], 401);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $departure = strtoupper(trim((string)($_POST['departure'] ?? '')));
    $arrival = strtoupper(trim((string)($_POST['arrival'] ?? '')));
    $routeText = strtoupper(trim((string)($_POST['route'] ?? '')));
    if (!preg_match('/^[A-Z0-9-]{2,15}$/', $departure)
        || !preg_match('/^[A-Z0-9-]{2,15}$/', $arrival)) {
        routeJson(['success' => false, 'message' => 'invalid_airports'], 400);
    }

    $departurePoint = routeAirport($pdo, $departure);
    $arrivalPoint = routeAirport($pdo, $arrival);
    if (!$departurePoint || !$arrivalPoint) {
        routeJson(['success' => false, 'message' => 'airport_not_found'], 404);
    }

    $points = [$departurePoint];
    $resolvedIdentifiers = [];
    $directOnly = $routeText === '' || preg_match('/^DCT(?:\s+DCT)*$/', $routeText);

    if (!$directOnly) {
        $rawTokens = preg_split('/\s+/', mb_substr($routeText, 0, 1500)) ?: [];
        $tokens = [];
        foreach ($rawTokens as $rawToken) {
            $token = preg_replace('/\/.*$/', '', trim($rawToken));
            if ($token === '' || $token === 'DCT' || $token === $departure || $token === $arrival) {
                continue;
            }
            if (!preg_match('/^[A-Z0-9]{2,7}$/', $token)
                || preg_match('/^(?:N|K|M)\d{3,4}$/', $token)
                || preg_match('/^(?:F|A|S)\d{3,4}$/', $token)) {
                continue;
            }
            if (!in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
            if (count($tokens) >= VFN_ROUTE_MAX_TOKENS) {
                break;
            }
        }

        $previous = $departurePoint;
        foreach ($tokens as $token) {
            $candidates = routeCandidates($token);
            if (!$candidates) {
                continue; // Usually an airway, SID/STAR, or unsupported token.
            }
            usort($candidates, static function (array $a, array $b) use ($previous, $arrivalPoint): int {
                $scoreA = routeDistanceNm($previous, $a) + routeDistanceNm($a, $arrivalPoint);
                $scoreB = routeDistanceNm($previous, $b) + routeDistanceNm($b, $arrivalPoint);
                return $scoreA <=> $scoreB;
            });
            $selected = $candidates[0];
            if (routeDistanceNm($previous, $selected) > 1800) {
                continue;
            }
            $points[] = $selected;
            $resolvedIdentifiers[] = $token;
            $previous = $selected;
        }
    }

    $points[] = $arrivalPoint;
    $distanceNm = 0.0;
    for ($index = 1, $count = count($points); $index < $count; $index++) {
        $distanceNm += routeDistanceNm($points[$index - 1], $points[$index]);
    }

    $directDistanceNm = routeDistanceNm($departurePoint, $arrivalPoint);
    // Short waypoint identifiers are reused throughout the world. On long
    // routes an ambiguous AIRAC search result can therefore produce a huge
    // zig-zag route even though every individual lookup succeeded. Reject a
    // route whose detour is implausible and use the reliable great-circle
    // route instead. A 35% margin still permits normal airway/SID/STAR detours.
    if ($directDistanceNm > 0.0 && $distanceNm > $directDistanceNm * 1.35) {
        $points = [$departurePoint, $arrivalPoint];
        $resolvedIdentifiers = [];
        $distanceNm = $directDistanceNm;
    }

    routeJson([
        'success' => true,
        'mode' => $resolvedIdentifiers ? 'waypoints' : 'direct',
        'distance_nm' => round($distanceNm, 1),
        'resolved_waypoints' => $resolvedIdentifiers,
        'points' => $points,
    ]);
} catch (Throwable $error) {
    error_log('Flight route estimate failed: ' . $error->getMessage());
    routeJson(['success' => false, 'message' => 'route_estimate_failed'], 500);
}
