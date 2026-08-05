<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/job_status.php';

$identifier = strtoupper(trim((string)($_GET['identifier'] ?? '')));
if (!preg_match('/^[A-Z][A-Z0-9]{0,7}$/', $identifier)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid_airway']);
    exit;
}

const VFN_AIRWAY_CACHE_SECONDS = 86400;
const VFN_AIRWAY_BASE_URL = 'https://airac.net/api/v1';
const VFN_AIRWAY_MAX_LEG_NM = 600.0;

function vfnAirwayFetchJson(string $url): ?array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header' => "Accept: application/json\r\nUser-Agent: VirtualFlightNetwork/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || trim($body) === '') {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function vfnAirwayDistanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusNm = 3440.065;
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($lonDelta / 2) ** 2;
    return 2 * $earthRadiusNm * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'vfn_airway_' . strtolower($identifier) . '.json';
    $response = null;
    $refreshed = false;
    if (is_file($cachePath) && time() - (int)filemtime($cachePath) < VFN_AIRWAY_CACHE_SECONDS) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached)) {
            $response = $cached;
        }
    }
    if ($response === null) {
        $response = vfnAirwayFetchJson(
            VFN_AIRWAY_BASE_URL . '/airways/' . rawurlencode($identifier)
        );
        if ($response !== null) {
            $refreshed = true;
            @file_put_contents(
                $cachePath,
                json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
        }
    }
    if (!is_array($response) || ($response['status'] ?? '') !== 'success') {
        throw new RuntimeException('AIRAC airway unavailable');
    }

    $data = (array)($response['data'] ?? []);
    $segments = [];
    $paths = [];
    $currentPath = [];
    $previous = null;
    foreach ((array)($data['segments'] ?? []) as $item) {
        $coordinates = (array)($item['fix_coordinates'] ?? []);
        $lat = $coordinates['lat'] ?? null;
        $lon = $coordinates['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            if (count($currentPath) >= 2) {
                $paths[] = $currentPath;
            }
            $currentPath = [];
            $previous = null;
            continue;
        }
        $point = [
            'identifier' => (string)($item['fix_identifier'] ?? ''),
            'name' => (string)($item['fix_name'] ?? ''),
            'latitude' => (float)$lat,
            'longitude' => (float)$lon,
            'sequence' => (int)($item['sequence'] ?? 0),
            'minimum_altitude_ft' => isset($item['minimum_altitude_ft'])
                ? (int)$item['minimum_altitude_ft'] : null,
            'maximum_altitude_ft' => isset($item['maximum_altitude_ft'])
                ? (int)$item['maximum_altitude_ft'] : null,
        ];
        if ($previous !== null && vfnAirwayDistanceNm(
            $previous['latitude'], $previous['longitude'],
            $point['latitude'], $point['longitude']
        ) > VFN_AIRWAY_MAX_LEG_NM) {
            if (count($currentPath) >= 2) {
                $paths[] = $currentPath;
            }
            $currentPath = [];
        }
        $currentPath[] = $point;
        $segments[] = $point;
        $previous = $point;
    }
    if (count($currentPath) >= 2) {
        $paths[] = $currentPath;
    }

    vfnRecordJobStatus(
        $pdo,
        'airac_refresh',
        true,
        'Airway ' . $identifier . ($refreshed ? ' network refresh' : ' cache valid')
    );
    echo json_encode([
        'success' => true,
        'airway' => [
            'identifier' => (string)($data['identifier'] ?? $identifier),
            'type' => (string)($data['type'] ?? ''),
            'segments' => $segments,
            'paths' => $paths,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO) {
        vfnRecordJobStatus($pdo, 'airac_refresh', false, $error->getMessage());
    }
    error_log('AIRAC airway detail failed: ' . $error->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'airac_unavailable']);
}
