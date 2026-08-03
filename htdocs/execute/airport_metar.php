<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/job_status.php';

$airport = strtoupper(trim((string)($_GET['airport'] ?? '')));
if (!preg_match('/^[A-Z0-9]{4}$/', $airport)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'invalid_airport']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $airportStmt = $pdo->prepare(
        "SELECT 1 FROM airports
         WHERE ident = :airport OR icao_code = :airport OR gps_code = :airport
         LIMIT 1"
    );
    $airportStmt->execute(['airport' => $airport]);
    if (!$airportStmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'airport_not_found']);
        exit;
    }

    $cacheSeconds = max(60, (int)($metarCacheSeconds ?? 1800));
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'vfn_map_metar_' . $airport . '.json';
    if (
        is_file($cachePath)
        && time() - (int)filemtime($cachePath) < $cacheSeconds
    ) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached)) {
            echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $url = rtrim((string)$noaaMetarStationBaseUrl, '/')
        . '/' . rawurlencode($airport) . '.TXT';
    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "User-Agent: VFN-Map/1.0 (virtualflightnetwork.com)\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $lines = $body === false
        ? []
        : array_values(array_filter(
            array_map('trim', preg_split('/\R/', $body) ?: []),
            static fn($line): bool => $line !== ''
        ));

    if (count($lines) < 2) {
        echo json_encode([
            'success' => false,
            'airport' => $airport,
            'message' => 'metar_unavailable',
        ]);
        exit;
    }

    $result = [
        'success' => true,
        'airport' => $airport,
        'observed_at' => $lines[0],
        'raw_text' => $lines[1],
    ];
    @file_put_contents(
        $cachePath,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    vfnRecordJobStatus($pdo, 'metar_refresh', true, $airport . ' ' . $lines[0]);
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO) vfnRecordJobStatus($pdo, 'metar_refresh', false, $error->getMessage());
    error_log('Map METAR lookup failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'metar_unavailable']);
}
