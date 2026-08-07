<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$station = strtoupper(trim((string)($_GET['station'] ?? '')));
$station = str_replace('-', '_', $station);
if (!preg_match('/^[A-Z0-9_]{2,32}$/', $station)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'invalid_station']);
    exit;
}

$dataDir = dirname(__DIR__) . '/data/atc';
$indexPath = $dataDir . '/sector-boundaries.index.json';
$dataPath = $dataDir . '/sector-boundaries.ndjson';
if (!is_file($indexPath) || !is_file($dataPath)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'sector_dataset_missing']);
    exit;
}

$index = json_decode((string)file_get_contents($indexPath), true);
$entry = is_array($index) ? ($index['stations'][$station] ?? null) : null;
if (!is_array($entry)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'sector_not_found']);
    exit;
}

$offset = (int)($entry['offset'] ?? -1);
$length = (int)($entry['length'] ?? 0);
$handle = fopen($dataPath, 'rb');
if ($handle === false || $offset < 0 || $length < 2 || fseek($handle, $offset) !== 0) {
    if (is_resource($handle)) fclose($handle);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'sector_dataset_read_failed']);
    exit;
}
$payload = fread($handle, $length);
fclose($handle);
$record = json_decode(rtrim((string)$payload), true);
if (!is_array($record) || strtoupper((string)($record['station_code'] ?? '')) !== $station) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'sector_dataset_corrupt']);
    exit;
}

$etag = '"' . sha1($station . '|' . filemtime($dataPath) . '|' . $offset . '|' . $length) . '"';
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

echo json_encode([
    'success' => true,
    'station' => [
        'code' => $record['station_code'] ?? $station,
        'position_key' => $record['position_key'] ?? '',
        'type' => $record['type'] ?? 'CTR',
        'frequency' => $record['frequency'] ?? '',
        'callsign' => $record['callsign'] ?? '',
        'group' => $record['group'] ?? '',
    ],
    'geojson' => [
        'type' => 'FeatureCollection',
        'features' => $record['features'] ?? [],
    ],
    'source' => $index['source'] ?? '',
    'source_commit' => $index['source_commit'] ?? '',
    'license' => $index['license'] ?? 'CC-BY-NC-SA-4.0',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

