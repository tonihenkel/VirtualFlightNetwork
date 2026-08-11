<?php

declare(strict_types=1);

ini_set('memory_limit', '768M');

/**
 * Builds the global VFN airport-layout catalogue.
 *
 * Detailed geometry is read from an X-Plane/Gateway apt.dat when supplied.
 * Every remaining VFN airport receives a conservative runway-only fallback
 * based on the local OurAirports runway data. Taxiways, aprons and stands are
 * never invented.
 *
 * PHP 7.4 compatible.
 *
 * Usage:
 *   php generate_global_airport_layouts.php [--apt=C:\path\apt.dat]
 *       [--output=C:\path\airport_layouts] [--force] [--only=EDDP,DE-0789]
 */

$projectRoot = dirname(__DIR__);
$webRoot = $projectRoot . DIRECTORY_SEPARATOR . 'htdocs';
$defaults = [
    'output' => $webRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'airport_layouts',
    'runways' => $webRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'airports' . DIRECTORY_SEPARATOR . 'runways.csv',
    'apt' => '',
    'only' => '',
    'force' => false,
];

$options = getopt('', ['apt::', 'output::', 'runways::', 'only::', 'force']);
$config = array_merge($defaults, array_intersect_key($options, $defaults));
$config['force'] = array_key_exists('force', $options);
$outputDirectory = rtrim((string)$config['output'], "\\/");
$only = [];
foreach (explode(',', strtoupper((string)$config['only'])) as $identifier) {
    $identifier = trim($identifier);
    if ($identifier !== '') {
        $only[$identifier] = true;
    }
}

if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Cannot create output directory: ' . $outputDirectory);
}

function vfnSafeIdentifier(string $identifier): string
{
    $identifier = strtoupper(trim($identifier));
    return preg_match('/^[A-Z0-9][A-Z0-9_-]{0,31}$/', $identifier) ? $identifier : '';
}

function vfnPoint(float $latitude, float $longitude): array
{
    return [round($latitude, 8), round($longitude, 8)];
}

function vfnUpdateBounds(array &$bounds, array $point): void
{
    $bounds[0] = min($bounds[0], (float)$point[0]);
    $bounds[1] = min($bounds[1], (float)$point[1]);
    $bounds[2] = max($bounds[2], (float)$point[0]);
    $bounds[3] = max($bounds[3], (float)$point[1]);
}

function vfnBoundsValid(array $bounds): bool
{
    return count($bounds) === 4 && $bounds[0] <= $bounds[2] && $bounds[1] <= $bounds[3];
}

function vfnDestinationPoint(float $latitude, float $longitude, float $headingDegrees, float $distanceMetres): array
{
    $radius = 6371008.8;
    $angularDistance = $distanceMetres / $radius;
    $bearing = deg2rad($headingDegrees);
    $lat1 = deg2rad($latitude);
    $lon1 = deg2rad($longitude);
    $lat2 = asin(sin($lat1) * cos($angularDistance) + cos($lat1) * sin($angularDistance) * cos($bearing));
    $lon2 = $lon1 + atan2(sin($bearing) * sin($angularDistance) * cos($lat1), cos($angularDistance) - sin($lat1) * sin($lat2));
    return vfnPoint(rad2deg($lat2), rad2deg($lon2));
}

function vfnLayoutPath(string $directory, string $identifier): string
{
    return $directory . DIRECTORY_SEPARATOR . $identifier . '.json';
}

function vfnWriteJsonAtomic(string $path, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        throw new RuntimeException('JSON encoding failed for ' . $path);
    }
    $temporary = $path . '.tmp-' . getmypid();
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write ' . $temporary);
    }
    if (is_file($path) && !unlink($path)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot replace ' . $path);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot publish ' . $path);
    }
}

function vfnNewLayout(string $identifier, string $name, string $kind, string $source): array
{
    return [
        'schema' => 2,
        'icao' => $identifier,
        'name' => $name !== '' ? $name : $identifier,
        'airport_kind' => $kind,
        'source' => $source,
        'quality' => 'detailed',
        'runways' => [],
        'water_runways' => [],
        'helipads' => [],
        'pavements' => [],
        'taxi_nodes' => [],
        'taxiways' => [],
        'stands' => [],
        'metadata' => [],
        '_bounds' => [90.0, 180.0, -90.0, -180.0],
    ];
}

function vfnFinishLayout(array $layout): array
{
    $bounds = $layout['_bounds'];
    unset($layout['_bounds']);
    if (!vfnBoundsValid($bounds)) {
        $center = isset($layout['center']) ? $layout['center'] : [0.0, 0.0];
        $bounds = [(float)$center[0] - 0.002, (float)$center[1] - 0.002, (float)$center[0] + 0.002, (float)$center[1] + 0.002];
    }
    $layout['bounds'] = array_map(function ($value) { return round((float)$value, 8); }, $bounds);
    $layout['center'] = [round(($bounds[0] + $bounds[2]) / 2.0, 8), round(($bounds[1] + $bounds[3]) / 2.0, 8)];
    $layout['counts'] = [
        'runways' => count($layout['runways']),
        'water_runways' => count($layout['water_runways']),
        'helipads' => count($layout['helipads']),
        'pavements' => count($layout['pavements']),
        'taxiways' => count($layout['taxiways']),
        'stands' => count($layout['stands']),
    ];
    $layout['generated_at'] = gmdate('c');
    return $layout;
}

function vfnCanonicalAirportCode(array $airport): string
{
    // `ident` is the primary key used by VFN/OurAirports and is globally
    // unique even where a GPS/ICAO alias is shared by multiple records.
    foreach (['ident', 'icao_code', 'gps_code'] as $field) {
        $candidate = vfnSafeIdentifier((string)($airport[$field] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function vfnIndexEntry(string $identifier, array $layout): array
{
    $quality = (string)($layout['quality'] ?? ((int)($layout['counts']['taxiways'] ?? 0) > 0 ? 'detailed' : 'runway_fallback'));
    return [
        'icao' => $identifier,
        'name' => (string)($layout['name'] ?? $identifier),
        'center' => $layout['center'] ?? [0.0, 0.0],
        'class' => (string)($layout['metadata']['airport_type'] ?? $layout['airport_kind'] ?? ''),
        'quality' => $quality,
        'counts' => $layout['counts'] ?? [],
    ];
}

function vfnNormalizeLayoutSchema(array $layout): array
{
    $layout['schema'] = 2;
    foreach (['runways', 'water_runways', 'helipads', 'pavements', 'taxi_nodes', 'taxiways', 'stands'] as $collection) {
        if (!isset($layout[$collection]) || !is_array($layout[$collection])) {
            $layout[$collection] = [];
        }
    }
    if (!isset($layout['metadata']) || !is_array($layout['metadata'])) {
        $layout['metadata'] = [];
    }
    if (!isset($layout['quality'])) {
        $layout['quality'] = (int)($layout['counts']['taxiways'] ?? count($layout['taxiways'])) > 0 ? 'detailed' : 'runway_fallback';
    }
    $layout['counts'] = [
        'runways' => count($layout['runways']),
        'water_runways' => count($layout['water_runways']),
        'helipads' => count($layout['helipads']),
        'pavements' => count($layout['pavements']),
        'taxiways' => count($layout['taxiways']),
        'stands' => count($layout['stands']),
    ];
    return $layout;
}

function vfnLoadAirports(string $configPath): array
{
    require $configPath;
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo->query(
        "SELECT ident, icao_code, gps_code, name, type, latitude_deg, longitude_deg, elevation_ft, iso_country
         FROM airports ORDER BY ident"
    )->fetchAll();
}

function vfnLoadRunways(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot read runway source: ' . $path);
    }
    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        throw new RuntimeException('Runway CSV has no header: ' . $path);
    }
    $header = array_map(function ($value) { return trim((string)$value, "\xEF\xBB\xBF\" "); }, $header);
    $rows = [];
    while (($values = fgetcsv($handle)) !== false) {
        if (count($values) !== count($header)) {
            continue;
        }
        $row = array_combine($header, $values);
        $identifier = vfnSafeIdentifier((string)($row['airport_ident'] ?? ''));
        if ($identifier !== '') {
            $rows[$identifier][] = $row;
        }
    }
    fclose($handle);
    return $rows;
}

function vfnCsvNumber(array $row, string $field): ?float
{
    $value = trim((string)($row[$field] ?? ''));
    return $value !== '' && is_numeric($value) ? (float)$value : null;
}

function vfnFallbackLayout(array $airport, array $runwayRows): array
{
    $identifier = vfnCanonicalAirportCode($airport);
    $type = strtolower((string)($airport['type'] ?? ''));
    $kind = strpos($type, 'heli') !== false ? 'heliport' : (strpos($type, 'seaplane') !== false ? 'seaplane_base' : 'land_airport');
    $layout = vfnNewLayout($identifier, (string)($airport['name'] ?? ''), $kind, 'OurAirports/VFN airport and runway data');
    $layout['quality'] = 'runway_fallback';
    $layout['metadata'] = ['airport_type' => $type, 'country' => (string)($airport['iso_country'] ?? ''), 'elevation_ft' => $airport['elevation_ft'] !== null ? (float)$airport['elevation_ft'] : null];
    $latitude = (float)($airport['latitude_deg'] ?? 0.0);
    $longitude = (float)($airport['longitude_deg'] ?? 0.0);
    $layout['center'] = vfnPoint($latitude, $longitude);

    foreach ($runwayRows as $row) {
        if ((string)($row['closed'] ?? '0') === '1') {
            continue;
        }
        $lengthMetres = max(1.0, (float)($row['length_ft'] ?? 0.0) * 0.3048);
        $widthMetres = max(1.0, (float)($row['width_ft'] ?? 0.0) * 0.3048);
        $heading = vfnCsvNumber($row, 'le_heading_degT');
        if ($heading === null) {
            $heading = 0.0;
        }
        $leLat = vfnCsvNumber($row, 'le_latitude_deg');
        $leLon = vfnCsvNumber($row, 'le_longitude_deg');
        $heLat = vfnCsvNumber($row, 'he_latitude_deg');
        $heLon = vfnCsvNumber($row, 'he_longitude_deg');
        if (($leLat !== null && abs($leLat) > 90.0) || ($heLat !== null && abs($heLat) > 90.0)
            || ($leLon !== null && abs($leLon) > 180.0) || ($heLon !== null && abs($heLon) > 180.0)) {
            $leLat = $leLon = $heLat = $heLon = null;
        }
        if ($leLat === null || $leLon === null || $heLat === null || $heLon === null) {
            $first = vfnDestinationPoint($latitude, $longitude, $heading + 180.0, $lengthMetres / 2.0);
            $second = vfnDestinationPoint($latitude, $longitude, $heading, $lengthMetres / 2.0);
        } else {
            $first = vfnPoint($leLat, $leLon);
            $second = vfnPoint($heLat, $heLon);
        }
        vfnUpdateBounds($layout['_bounds'], $first);
        vfnUpdateBounds($layout['_bounds'], $second);
        $surface = strtoupper((string)($row['surface'] ?? ''));
        $isHelipad = strpos(strtoupper((string)($row['le_ident'] ?? '')), 'H') === 0 || $kind === 'heliport';
        $isWater = $kind === 'seaplane_base' || in_array($surface, ['WATER', 'WTR'], true);
        $entry = [
            'width_m' => round($widthMetres, 2),
            'length_m' => round($lengthMetres, 2),
            'surface_name' => $surface,
            'lighted' => (int)($row['lighted'] ?? 0) === 1,
            'ends' => [
                ['ident' => (string)($row['le_ident'] ?? ''), 'point' => $first],
                ['ident' => (string)($row['he_ident'] ?? ''), 'point' => $second],
            ],
        ];
        if ($isHelipad) {
            $layout['helipads'][] = ['ident' => (string)($row['le_ident'] ?? 'H'), 'point' => vfnPoint($latitude, $longitude), 'heading' => $heading, 'length_m' => round($lengthMetres, 2), 'width_m' => round($widthMetres, 2), 'surface_name' => $surface];
        } elseif ($isWater) {
            $layout['water_runways'][] = $entry;
        } else {
            $layout['runways'][] = $entry;
        }
    }
    if (!vfnBoundsValid($layout['_bounds'])) {
        $radius = $kind === 'heliport' ? 0.0008 : 0.002;
        $layout['_bounds'] = [$latitude - $radius, $longitude - $radius, $latitude + $radius, $longitude + $radius];
        $layout['reference_point'] = vfnPoint($latitude, $longitude);
        if ($kind === 'heliport') {
            $layout['helipads'][] = ['ident' => 'H', 'point' => vfnPoint($latitude, $longitude), 'heading' => null, 'length_m' => null, 'width_m' => null, 'surface_name' => 'UNKNOWN'];
        }
        $layout['quality'] = 'location_only';
    }
    return vfnFinishLayout($layout);
}

function vfnPublishDetailedLayout(array $layout, string $outputDirectory, array &$published, bool $force): void
{
    $identifier = vfnSafeIdentifier((string)$layout['icao']);
    if ($identifier === '') {
        return;
    }
    $path = vfnLayoutPath($outputDirectory, $identifier);
    if (!$force && is_file($path)) {
        $existing = json_decode((string)file_get_contents($path), true);
        if (is_array($existing) && (int)($existing['counts']['taxiways'] ?? 0) > (int)($layout['counts']['taxiways'] ?? 0)) {
            $existing = vfnNormalizeLayoutSchema($existing);
            vfnWriteJsonAtomic($path, $existing);
            $published[$identifier] = vfnIndexEntry($identifier, $existing);
            return;
        }
    }
    vfnWriteJsonAtomic($path, $layout);
    $published[$identifier] = vfnIndexEntry($identifier, $layout);
}

function vfnImportAptDat(string $path, string $outputDirectory, array &$published, array $only, bool $force): void
{
    if ($path === '') {
        return;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot read apt.dat: ' . $path);
    }
    $layout = null;
    $polygon = null;
    $nodes = [];
    $finishPolygon = function () use (&$layout, &$polygon): void {
        if (is_array($layout) && is_array($polygon) && count($polygon['points']) >= 3) {
            $layout['pavements'][] = $polygon;
        }
        $polygon = null;
    };
    $finishAirport = function () use (&$layout, &$polygon, &$nodes, $finishPolygon, $outputDirectory, &$published, $force): void {
        if (!is_array($layout)) {
            return;
        }
        $finishPolygon();
        $layout['taxi_nodes'] = $nodes;
        vfnPublishDetailedLayout(vfnFinishLayout($layout), $outputDirectory, $published, $force);
        $layout = null;
        $nodes = [];
    };

    while (($line = fgets($handle)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '' || substr($trimmed, 0, 1) === '#') {
            continue;
        }
        $parts = preg_split('/\s+/', $trimmed);
        $code = (int)($parts[0] ?? 0);
        if (in_array($code, [1, 16, 17], true)) {
            $finishAirport();
            $identifier = vfnSafeIdentifier((string)($parts[4] ?? ''));
            if ($identifier === '' || ($only && !isset($only[$identifier]))) {
                $layout = null;
                continue;
            }
            $kind = $code === 17 ? 'heliport' : ($code === 16 ? 'seaplane_base' : 'land_airport');
            $layout = vfnNewLayout($identifier, implode(' ', array_slice($parts, 5)), $kind, 'X-Plane Scenery Gateway apt.dat');
            $layout['metadata']['elevation_ft'] = (float)($parts[1] ?? 0.0);
            continue;
        }
        if ($code === 99) {
            break;
        }
        if (!is_array($layout)) {
            continue;
        }
        if ($code === 100 && count($parts) >= 20) {
            $first = vfnPoint((float)$parts[9], (float)$parts[10]);
            $second = vfnPoint((float)$parts[18], (float)$parts[19]);
            vfnUpdateBounds($layout['_bounds'], $first); vfnUpdateBounds($layout['_bounds'], $second);
            $layout['runways'][] = ['width_m' => (float)$parts[1], 'surface' => (int)$parts[2], 'ends' => [['ident' => $parts[8], 'point' => $first], ['ident' => $parts[17], 'point' => $second]]];
        } elseif ($code === 101 && count($parts) >= 9) {
            $first = vfnPoint((float)$parts[4], (float)$parts[5]);
            $second = vfnPoint((float)$parts[7], (float)$parts[8]);
            vfnUpdateBounds($layout['_bounds'], $first); vfnUpdateBounds($layout['_bounds'], $second);
            $layout['water_runways'][] = ['width_m' => (float)$parts[1], 'ends' => [['ident' => $parts[3], 'point' => $first], ['ident' => $parts[6], 'point' => $second]]];
        } elseif ($code === 102 && count($parts) >= 8) {
            $point = vfnPoint((float)$parts[2], (float)$parts[3]);
            vfnUpdateBounds($layout['_bounds'], $point);
            $layout['helipads'][] = ['ident' => $parts[1], 'point' => $point, 'heading' => (float)$parts[4], 'length_m' => (float)$parts[5], 'width_m' => (float)$parts[6], 'surface' => (int)$parts[7]];
        } elseif ($code === 110) {
            $finishPolygon();
            $polygon = ['name' => implode(' ', array_slice($parts, 4)), 'surface' => (int)($parts[1] ?? 0), 'points' => [], 'segments' => []];
        } elseif (is_array($polygon) && in_array($code, [111, 112, 113, 114], true)) {
            $point = vfnPoint((float)$parts[1], (float)$parts[2]);
            vfnUpdateBounds($layout['_bounds'], $point);
            $polygon['points'][] = $point;
            $segment = ['point' => $point, 'close' => in_array($code, [113, 114], true)];
            if (in_array($code, [112, 114], true) && isset($parts[3], $parts[4])) {
                $segment['control'] = vfnPoint((float)$parts[3], (float)$parts[4]);
            }
            $polygon['segments'][] = $segment;
            if ($segment['close']) { $finishPolygon(); }
        } elseif ($code === 1201 && count($parts) >= 5) {
            $point = vfnPoint((float)$parts[1], (float)$parts[2]);
            vfnUpdateBounds($layout['_bounds'], $point);
            $nodes[(string)$parts[4]] = ['point' => $point, 'usage' => (string)$parts[3], 'name' => implode(' ', array_slice($parts, 5))];
        } elseif ($code === 1202 && count($parts) >= 5) {
            $layout['taxiways'][] = ['from' => (string)$parts[1], 'to' => (string)$parts[2], 'direction' => (string)$parts[3], 'class' => (string)$parts[4], 'name' => implode(' ', array_slice($parts, 5))];
        } elseif ($code === 1300 && count($parts) >= 7) {
            $point = vfnPoint((float)$parts[1], (float)$parts[2]);
            vfnUpdateBounds($layout['_bounds'], $point);
            $layout['stands'][] = ['point' => $point, 'heading' => (float)$parts[3], 'type' => (string)$parts[4], 'aircraft' => (string)$parts[5], 'name' => implode(' ', array_slice($parts, 6))];
        } elseif ($code === 1301 && $layout['stands']) {
            $last = count($layout['stands']) - 1;
            $layout['stands'][$last]['metadata'] = ['width' => (string)($parts[1] ?? ''), 'operation' => (string)($parts[2] ?? ''), 'airlines' => array_slice($parts, 3)];
        } elseif ($code === 1302 && isset($parts[1])) {
            $layout['metadata'][(string)$parts[1]] = implode(' ', array_slice($parts, 2));
        }
    }
    $finishAirport();
    fclose($handle);
}

$airports = vfnLoadAirports($webRoot . DIRECTORY_SEPARATOR . 'execute' . DIRECTORY_SEPARATOR . 'config.php');
$runwaysByAirport = vfnLoadRunways((string)$config['runways']);
$published = [];
vfnImportAptDat((string)$config['apt'], $outputDirectory, $published, $only, (bool)$config['force']);

$statistics = ['total' => 0, 'detailed' => 0, 'runway_fallback' => 0, 'location_only' => 0, 'skipped' => 0];
foreach ($airports as $airport) {
    $identifier = vfnCanonicalAirportCode($airport);
    if ($identifier === '' || ($only && !isset($only[$identifier]))) {
        $statistics['skipped']++;
        continue;
    }
    if (isset($published[$identifier])) {
        continue;
    }
    $path = vfnLayoutPath($outputDirectory, $identifier);
    if (!(bool)$config['force'] && is_file($path)) {
        $existing = json_decode((string)file_get_contents($path), true);
        if (is_array($existing) && (int)($existing['counts']['taxiways'] ?? 0) > 0) {
            $existing = vfnNormalizeLayoutSchema($existing);
            vfnWriteJsonAtomic($path, $existing);
            $published[$identifier] = vfnIndexEntry($identifier, $existing);
            continue;
        }
    }
    $rows = $runwaysByAirport[vfnSafeIdentifier((string)($airport['ident'] ?? ''))] ?? [];
    $layout = vfnFallbackLayout($airport, $rows);
    vfnWriteJsonAtomic($path, $layout);
    $published[$identifier] = vfnIndexEntry($identifier, $layout);
    if (count($published) % 1000 === 0) {
        fwrite(STDOUT, sprintf("Published %d layouts ...\n", count($published)));
    }
}

$indexEntries = $published;
if ($only) {
    $existingIndexPath = $outputDirectory . DIRECTORY_SEPARATOR . 'index.json';
    if (is_file($existingIndexPath)) {
        $existingIndex = json_decode((string)file_get_contents($existingIndexPath), true);
        foreach ((array)($existingIndex['airports'] ?? []) as $entry) {
            $identifier = vfnSafeIdentifier((string)($entry['icao'] ?? ''));
            if ($identifier !== '' && !isset($indexEntries[$identifier])) {
                $indexEntries[$identifier] = $entry;
            }
        }
    }
}

$statistics = ['total' => 0, 'detailed' => 0, 'runway_fallback' => 0, 'location_only' => 0, 'skipped' => 0];
$indexAirports = [];
ksort($indexEntries, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($indexEntries as $identifier => $entry) {
    $quality = (string)$entry['quality'];
    if (!isset($statistics[$quality])) { $statistics[$quality] = 0; }
    $statistics[$quality]++;
    $statistics['total']++;
    $indexAirports[] = $entry;
}
$index = ['schema' => 2, 'generated_at' => gmdate('c'), 'statistics' => $statistics, 'airports' => $indexAirports];
vfnWriteJsonAtomic($outputDirectory . DIRECTORY_SEPARATOR . 'index.json', $index);
vfnWriteJsonAtomic($outputDirectory . DIRECTORY_SEPARATOR . 'generation-report.json', [
    'schema' => 1,
    'generated_at' => gmdate('c'),
    'apt_source' => (string)$config['apt'],
    'runway_source' => (string)$config['runways'],
    'statistics' => $statistics,
]);

fwrite(STDOUT, json_encode($statistics, JSON_UNESCAPED_SLASHES) . PHP_EOL);
