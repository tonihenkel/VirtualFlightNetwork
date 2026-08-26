<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$station = strtoupper(str_replace('-', '_', trim((string)($_GET['station'] ?? ''))));
if (!preg_match('/^[A-Z0-9_]{2,32}$/', $station)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'invalid_station']);
    exit;
}

try {
    require dirname(__DIR__) . '/execute/config.php';
    require_once dirname(__DIR__) . '/includes/atc_frequency_catalog.php';
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $resolved = findAtcFrequencies($pdo, $station, 'CTR');
    echo json_encode([
        'success' => true,
        'station' => $station,
        'frequencies' => array_values(array_map(
            static fn(array $entry): string => (string)$entry['frequency'],
            $resolved
        )),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
