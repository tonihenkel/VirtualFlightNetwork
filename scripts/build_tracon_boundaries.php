<?php
declare(strict_types=1);

/**
 * Compile the CC BY-SA 4.0 SimAware TRACON repository into one browser-ready
 * GeoJSON file. Usage:
 * php scripts/build_tracon_boundaries.php <repo>/Boundaries <output.geojson> [commit]
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php build_tracon_boundaries.php <source-dir> <output> [commit]\n");
    exit(2);
}

$source = realpath($argv[1]);
$output = $argv[2];
$commit = trim((string)($argv[3] ?? ''));
if ($source === false || !is_dir($source)) {
    fwrite(STDERR, "Source directory not found.\n");
    exit(2);
}

function normalizeCoordinates($value)
{
    if (is_string($value)) {
        $parts = preg_split('/\s+/', trim($value));
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return [(float)$parts[0], (float)$parts[1]];
        }
        return $value;
    }
    if (!is_array($value)) return $value;
    return array_map('normalizeCoordinates', $value);
}

$features = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') continue;
    $payload = json_decode((string)file_get_contents($file->getPathname()), true);
    if (!is_array($payload)) continue;
    $candidates = ($payload['type'] ?? '') === 'FeatureCollection'
        ? ($payload['features'] ?? []) : [$payload];
    foreach ($candidates as $feature) {
        if (!is_array($feature) || ($feature['type'] ?? '') !== 'Feature'
            || !isset($feature['geometry']['type'], $feature['geometry']['coordinates'])) continue;
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $id = strtoupper(trim((string)($properties['id'] ?? '')));
        if ($id === '') continue;
        $properties['id'] = $id;
        $properties['source'] = 'VATSIM SimAware TRACON Project';
        $properties['source_url'] = 'https://github.com/vatsimnetwork/simaware-tracon-project';
        $properties['license'] = 'CC-BY-SA-4.0';
        $properties['quality'] = 'published';
        if ($commit !== '') $properties['source_commit'] = $commit;
        $feature['properties'] = $properties;
        $feature['geometry']['coordinates'] = normalizeCoordinates($feature['geometry']['coordinates']);
        $features[] = $feature;
    }
}

usort($features, static fn(array $a, array $b): int =>
    strcmp((string)$a['properties']['id'], (string)$b['properties']['id'])
);
$document = [
    'type' => 'FeatureCollection',
    'metadata' => [
        'source' => 'VATSIM SimAware TRACON Project',
        'source_url' => 'https://github.com/vatsimnetwork/simaware-tracon-project',
        'license' => 'CC-BY-SA-4.0',
        'source_commit' => $commit,
        'generated_at' => gmdate('c'),
        'feature_count' => count($features),
    ],
    'features' => $features,
];
$json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false || file_put_contents($output, $json) === false) {
    fwrite(STDERR, "Unable to write output.\n");
    exit(1);
}
fwrite(STDOUT, 'Compiled ' . count($features) . " TRACON features.\n");
