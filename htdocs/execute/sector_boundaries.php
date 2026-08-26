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
$stations = is_array($index['stations'] ?? null) ? $index['stations'] : [];
$entries = [];
if (is_array($stations[$station] ?? null)) {
    $entries[$station] = $stations[$station];
} else {
    foreach ($stations as $stationKey => $candidate) {
        if (preg_match('/^' . preg_quote($station, '/') . '[0-9]+$/', (string)$stationKey)
            && is_array($candidate)) {
            $entries[(string)$stationKey] = $candidate;
        }
    }
    uksort($entries, 'strnatcasecmp');
}
if (!$entries) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'sector_not_found']);
    exit;
}

$handle = fopen($dataPath, 'rb');
if ($handle === false) {
    if (is_resource($handle)) fclose($handle);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'sector_dataset_read_failed']);
    exit;
}
$features = [];
$records = [];
foreach ($entries as $entryStation => $entry) {
    $offset = (int)($entry['offset'] ?? -1);
    $length = (int)($entry['length'] ?? 0);
    if ($offset < 0 || $length < 2 || fseek($handle, $offset) !== 0) continue;
    $record = json_decode(rtrim((string)fread($handle, $length)), true);
    if (!is_array($record)
        || strtoupper((string)($record['station_code'] ?? '')) !== strtoupper($entryStation)) continue;
    $records[] = $record;
    if (is_array($record['features'] ?? null)) array_push($features, ...$record['features']);
}
fclose($handle);
if (!$records || !$features) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'sector_dataset_corrupt']);
    exit;
}

$record = $records[0];
$etag = '"' . sha1($station . '|' . filemtime($dataPath) . '|' . json_encode(array_keys($entries))) . '"';
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
        'features' => $features,
    ],
    'source' => $index['source'] ?? '',
    'source_commit' => $index['source_commit'] ?? '',
    'license' => $index['license'] ?? 'CC-BY-NC-SA-4.0',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
