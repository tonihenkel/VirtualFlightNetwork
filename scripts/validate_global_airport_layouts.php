<?php

declare(strict_types=1);

ini_set('memory_limit', '768M');

$directory = $argv[1] ?? dirname(__DIR__) . '/htdocs/data/airport_layouts';
$directory = rtrim((string)$directory, "\\/");
$indexPath = $directory . DIRECTORY_SEPARATOR . 'index.json';
$index = json_decode((string)file_get_contents($indexPath), true);
if (!is_array($index) || !isset($index['airports']) || !is_array($index['airports'])) {
    throw new RuntimeException('Invalid layout index: ' . $indexPath);
}

$errors = [];
$warnings = [];
$seen = [];
$checked = 0;
$pointValid = static function ($point): bool {
    return is_array($point) && count($point) >= 2 && is_numeric($point[0]) && is_numeric($point[1])
        && (float)$point[0] >= -90.0 && (float)$point[0] <= 90.0
        && (float)$point[1] >= -180.0 && (float)$point[1] <= 180.0;
};

foreach ($index['airports'] as $entry) {
    $identifier = strtoupper(trim((string)($entry['icao'] ?? '')));
    if ($identifier === '' || isset($seen[$identifier])) {
        $errors[] = $identifier === '' ? 'Index contains an empty identifier' : 'Duplicate index identifier: ' . $identifier;
        continue;
    }
    $seen[$identifier] = true;
    $path = $directory . DIRECTORY_SEPARATOR . $identifier . '.json';
    if (!is_file($path)) {
        $errors[] = 'Missing layout file: ' . $identifier;
        continue;
    }
    $layout = json_decode((string)file_get_contents($path), true);
    if (!is_array($layout)) {
        $errors[] = 'Malformed JSON: ' . $identifier;
        continue;
    }
    if (strtoupper((string)($layout['icao'] ?? '')) !== $identifier) {
        $errors[] = 'Identifier mismatch: ' . $identifier;
    }
    if (!$pointValid($layout['center'] ?? null)) {
        $errors[] = 'Invalid center: ' . $identifier;
    }
    $bounds = $layout['bounds'] ?? null;
    if (!is_array($bounds) || count($bounds) !== 4 || !is_numeric($bounds[0]) || !is_numeric($bounds[1]) || !is_numeric($bounds[2]) || !is_numeric($bounds[3])
        || (float)$bounds[0] > (float)$bounds[2] || (float)$bounds[1] > (float)$bounds[3]
        || (float)$bounds[0] < -90.0 || (float)$bounds[2] > 90.0 || (float)$bounds[1] < -180.0 || (float)$bounds[3] > 180.0) {
        $errors[] = 'Invalid bounds: ' . $identifier;
    }
    foreach (['runways', 'water_runways'] as $collection) {
        foreach (($layout[$collection] ?? []) as $runway) {
            $ends = $runway['ends'] ?? [];
            if (count($ends) !== 2 || !$pointValid($ends[0]['point'] ?? null) || !$pointValid($ends[1]['point'] ?? null)) {
                $errors[] = 'Invalid ' . $collection . ' geometry: ' . $identifier;
                break;
            }
        }
    }
    foreach (($layout['helipads'] ?? []) as $helipad) {
        if (!$pointValid($helipad['point'] ?? null)) {
            $errors[] = 'Invalid helipad geometry: ' . $identifier;
            break;
        }
    }
    foreach (['runways', 'water_runways', 'helipads', 'pavements', 'taxiways', 'stands'] as $collection) {
        $expected = count($layout[$collection] ?? []);
        if ((int)($layout['counts'][$collection] ?? -1) !== $expected) {
            $errors[] = 'Count mismatch for ' . $collection . ': ' . $identifier;
        }
    }
    if (($layout['quality'] ?? '') === 'detailed' && (int)($layout['counts']['taxiways'] ?? 0) === 0) {
        $warnings[] = 'Detailed layout has no taxi routes: ' . $identifier;
    }
    $checked++;
    if ($checked % 10000 === 0) {
        fwrite(STDOUT, sprintf("Validated %d layouts ...\n", $checked));
    }
}

$report = [
    'schema' => 1,
    'validated_at' => gmdate('c'),
    'index_entries' => count($index['airports']),
    'checked_files' => $checked,
    'error_count' => count($errors),
    'warning_count' => count($warnings),
    'errors' => array_slice($errors, 0, 1000),
    'warnings' => array_slice($warnings, 0, 1000),
];
$reportPath = $directory . DIRECTORY_SEPARATOR . 'quality-control-report.json';
file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit($errors ? 1 : 0);

