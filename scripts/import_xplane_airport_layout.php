<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "Usage: php import_xplane_airport_layout.php <apt.dat> <ICAO> <output.json>\n");
    exit(1);
}

[$script, $sourcePath, $wantedIcao, $outputPath] = $argv;
$wantedIcao = strtoupper(trim($wantedIcao));
$lines = @file($sourcePath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    throw new RuntimeException('Unable to read apt.dat: ' . $sourcePath);
}

$layout = [
    'schema' => 1,
    'icao' => $wantedIcao,
    'name' => $wantedIcao,
    'source' => 'X-Plane Scenery Gateway apt.dat',
    'runways' => [],
    'pavements' => [],
    'taxi_nodes' => [],
    'taxiways' => [],
    'stands' => []
];
$insideAirport = false;
$polygon = null;
$nodes = [];
$bounds = [90.0, 180.0, -90.0, -180.0];

$addPoint = static function (float $lat, float $lon) use (&$bounds): array {
    $bounds[0] = min($bounds[0], $lat);
    $bounds[1] = min($bounds[1], $lon);
    $bounds[2] = max($bounds[2], $lat);
    $bounds[3] = max($bounds[3], $lon);
    return [round($lat, 8), round($lon, 8)];
};

$finishPolygon = static function () use (&$polygon, &$layout): void {
    if ($polygon !== null && count($polygon['points']) >= 3) {
        $layout['pavements'][] = $polygon;
    }
    $polygon = null;
};

foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '') {
        continue;
    }
    $parts = preg_split('/\s+/', $trimmed);
    $code = (int)($parts[0] ?? 0);

    if (in_array($code, [1, 16, 17], true)) {
        $finishPolygon();
        $icao = strtoupper((string)($parts[4] ?? ''));
        $insideAirport = $icao === $wantedIcao;
        if ($insideAirport) {
            $layout['name'] = implode(' ', array_slice($parts, 5));
        }
        continue;
    }
    if (!$insideAirport) {
        continue;
    }
    if ($code === 99 || (in_array($code, [1, 16, 17], true) && !$insideAirport)) {
        break;
    }

    if ($code === 100 && count($parts) >= 20) {
        $layout['runways'][] = [
            'width_m' => (float)$parts[1],
            'surface' => (int)$parts[2],
            'ends' => [
                ['ident' => $parts[8], 'point' => $addPoint((float)$parts[9], (float)$parts[10])],
                ['ident' => $parts[17], 'point' => $addPoint((float)$parts[18], (float)$parts[19])]
            ]
        ];
        continue;
    }
    if ($code === 110) {
        $finishPolygon();
        $polygon = [
            'name' => implode(' ', array_slice($parts, 4)),
            'surface' => (int)($parts[1] ?? 0),
            'points' => []
        ];
        continue;
    }
    if ($polygon !== null && in_array($code, [111, 112, 113, 114], true)) {
        $polygon['points'][] = $addPoint((float)$parts[1], (float)$parts[2]);
        if (in_array($code, [113, 114], true)) {
            $finishPolygon();
        }
        continue;
    }
    if ($code === 120) {
        $finishPolygon();
        continue;
    }
    if ($code === 1201 && count($parts) >= 5) {
        $id = (string)$parts[4];
        $nodes[$id] = [
            'point' => $addPoint((float)$parts[1], (float)$parts[2]),
            'name' => implode(' ', array_slice($parts, 5))
        ];
        continue;
    }
    if ($code === 1202 && count($parts) >= 5) {
        $layout['taxiways'][] = [
            'from' => (string)$parts[1],
            'to' => (string)$parts[2],
            'direction' => (string)$parts[3],
            'class' => (string)$parts[4],
            'name' => implode(' ', array_slice($parts, 5))
        ];
        continue;
    }
    if ($code === 1300 && count($parts) >= 7) {
        $layout['stands'][] = [
            'point' => $addPoint((float)$parts[1], (float)$parts[2]),
            'heading' => (float)$parts[3],
            'type' => (string)$parts[4],
            'aircraft' => (string)$parts[5],
            'name' => implode(' ', array_slice($parts, 6))
        ];
    }
}
$finishPolygon();

foreach ($nodes as $id => $node) {
    $layout['taxi_nodes'][$id] = $node;
}
$layout['bounds'] = $bounds;
$layout['center'] = [
    round(($bounds[0] + $bounds[2]) / 2.0, 8),
    round(($bounds[1] + $bounds[3]) / 2.0, 8)
];
$layout['counts'] = [
    'runways' => count($layout['runways']),
    'pavements' => count($layout['pavements']),
    'taxiways' => count($layout['taxiways']),
    'stands' => count($layout['stands'])
];

$directory = dirname($outputPath);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create output directory: ' . $directory);
}
$json = json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false || file_put_contents($outputPath, $json) === false) {
    throw new RuntimeException('Unable to write layout: ' . $outputPath);
}
fwrite(STDOUT, sprintf(
    "%s: %d runways, %d pavements, %d taxiways, %d stands\n",
    $wantedIcao,
    $layout['counts']['runways'],
    $layout['counts']['pavements'],
    $layout['counts']['taxiways'],
    $layout['counts']['stands']
));
