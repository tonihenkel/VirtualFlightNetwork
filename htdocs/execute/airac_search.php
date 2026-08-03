<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/job_status.php';

$query = strtoupper(trim((string)($_GET['q'] ?? '')));
if (
    mb_strlen($query) < 2
    || mb_strlen($query) > 50
    || !preg_match('/^[A-Z0-9 .\-]+$/', $query)
) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

const VFN_AIRAC_CACHE_SECONDS = 86400;
const VFN_AIRAC_BASE_URL = 'https://airac.net/api/v1';

function vfnAiracFetchJson(string $url): ?array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header' =>
                "Accept: application/json\r\n"
                . "User-Agent: VirtualFlightNetwork/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || trim($body) === '') {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function vfnAiracCachedJson(
    string $cacheKey,
    string $url,
    int $seconds = VFN_AIRAC_CACHE_SECONDS,
    &$wasRefreshed = null
): ?array {
    $wasRefreshed = false;
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'vfn_airac_' . preg_replace('/[^a-z0-9_-]/i', '', $cacheKey) . '.json';
    if (
        is_file($cachePath)
        && time() - (int)filemtime($cachePath) < $seconds
    ) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $data = vfnAiracFetchJson($url);
    if ($data !== null) {
        $wasRefreshed = true;
        @file_put_contents(
            $cachePath,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
    return $data;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $cycleRefreshed = false;
    $cycleResponse = vfnAiracCachedJson(
        'current_cycle',
        VFN_AIRAC_BASE_URL . '/airac/current',
        VFN_AIRAC_CACHE_SECONDS,
        $cycleRefreshed
    );
    $cycle = trim((string)($cycleResponse['data']['cycle'] ?? 'unknown'));
    if (!is_array($cycleResponse) || $cycle === '' || $cycle === 'unknown') {
        throw new RuntimeException('AIRAC cycle unavailable');
    }
    vfnRecordJobStatus(
        $pdo,
        'airac_refresh',
        true,
        'AIRAC ' . $cycle . ($cycleRefreshed ? ' · network refresh' : ' · cache valid')
    );
    $searchUrl = VFN_AIRAC_BASE_URL . '/search?'
        . http_build_query([
            'q' => $query,
            'limit' => 8,
        ])
        . '&types=waypoint&types=navaid';
    $searchResponse = vfnAiracCachedJson(
        'search_' . $cycle . '_' . hash('sha256', $query),
        $searchUrl
    );

    if (
        !is_array($searchResponse)
        || ($searchResponse['status'] ?? '') !== 'success'
    ) {
        throw new RuntimeException('AIRAC search unavailable');
    }

    $groups = (array)($searchResponse['data'] ?? []);
    $results = [];
    foreach (['waypoints' => 'waypoint', 'navaids' => 'navaid'] as $group => $kind) {
        foreach ((array)($groups[$group] ?? []) as $item) {
            $coordinates = (array)($item['coordinates'] ?? []);
            $latitude = $coordinates['lat'] ?? $item['latitude'] ?? null;
            $longitude = $coordinates['lon'] ?? $item['longitude'] ?? null;
            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            if ((float)$latitude === 0.0 && (float)$longitude === 0.0) {
                continue;
            }
            $type = $item['type'] ?? '';
            if (is_array($type)) {
                $type = $type['code'] ?? $type['description'] ?? '';
            }
            $results[] = [
                'kind' => $kind,
                'identifier' => (string)($item['identifier'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'type' => (string)$type,
                'region' => (string)($item['region'] ?? ''),
                'frequency' => isset($item['frequency'])
                    ? (string)$item['frequency']
                    : '',
                'frequency_unit' => (string)($item['frequency_unit'] ?? ''),
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'cycle' => $cycle,
        'effective_date' =>
            (string)($cycleResponse['data']['effective_date'] ?? ''),
        'expiration_date' =>
            (string)($cycleResponse['data']['expiration_date'] ?? ''),
        'results' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO) {
        vfnRecordJobStatus($pdo, 'airac_refresh', false, $error->getMessage());
    }
    error_log('AIRAC search failed: ' . $error->getMessage());
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'airac_unavailable',
        'results' => [],
    ]);
}
