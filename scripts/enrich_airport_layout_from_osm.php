<?php
declare(strict_types=1);

/**
 * Add verifiable OpenStreetMap taxiway geometry/names and parking positions to
 * an existing VFN airport layout. Existing Gateway data is kept intact.
 *
 * Usage: php enrich_airport_layout_from_osm.php <map.osm> <ICAO> <layout.json>
 */

if ($argc < 4) {
    fwrite(STDERR, "Usage: php enrich_airport_layout_from_osm.php <map.osm> <ICAO> <layout.json>\n");
    exit(1);
}

[$script, $osmPath, $icao, $layoutPath] = $argv;
$icao = strtoupper(trim($icao));
if (!is_file($osmPath) || !is_file($layoutPath)) {
    throw new RuntimeException('OSM input or layout JSON does not exist.');
}

$layout = json_decode((string)file_get_contents($layoutPath), true, 512, JSON_THROW_ON_ERROR);
$xml = simplexml_load_file($osmPath, SimpleXMLElement::class, LIBXML_COMPACT | LIBXML_PARSEHUGE);
if (!$xml) {
    throw new RuntimeException('Invalid OSM XML.');
}

$nodePoints = [];
foreach ($xml->node as $node) {
    $nodePoints[(string)$node['id']] = [(float)$node['lat'], (float)$node['lon']];
}

$tagsOf = static function (SimpleXMLElement $element): array {
    $tags = [];
    foreach ($element->tag as $tag) {
        $tags[(string)$tag['k']] = (string)$tag['v'];
    }
    return $tags;
};

$layout['taxi_nodes'] = is_array($layout['taxi_nodes'] ?? null) ? $layout['taxi_nodes'] : [];
$layout['taxiways'] = is_array($layout['taxiways'] ?? null) ? $layout['taxiways'] : [];
$layout['stands'] = is_array($layout['stands'] ?? null) ? $layout['stands'] : [];
$edgeKeys = [];
foreach ($layout['taxiways'] as $edge) {
    $edgeKeys[(string)($edge['from'] ?? '') . ':' . (string)($edge['to'] ?? '') . ':' . (string)($edge['name'] ?? '')] = true;
}
$standKeys = [];
foreach ($layout['stands'] as $stand) {
    if (!isset($stand['point'][0], $stand['point'][1])) { continue; }
    $standKeys[sprintf('%.6f:%.6f', $stand['point'][0], $stand['point'][1])] = true;
}

$addedEdges = 0;
$namedEdges = 0;
$addedStands = 0;
foreach ($xml->way as $way) {
    $tags = $tagsOf($way);
    $aeroway = strtolower($tags['aeroway'] ?? '');
    if ($aeroway !== 'taxiway' && $aeroway !== 'parking_position') { continue; }
    $refs = [];
    foreach ($way->nd as $nd) {
        $ref = (string)$nd['ref'];
        if (isset($nodePoints[$ref])) { $refs[] = $ref; }
    }
    if ($aeroway === 'taxiway') {
        $name = trim($tags['ref'] ?? ($tags['name'] ?? ''));
        for ($i = 1, $count = count($refs); $i < $count; $i++) {
            $from = 'osm_' . $refs[$i - 1];
            $to = 'osm_' . $refs[$i];
            $layout['taxi_nodes'][$from] = ['point' => $nodePoints[$refs[$i - 1]], 'usage' => 'both', 'name' => ''];
            $layout['taxi_nodes'][$to] = ['point' => $nodePoints[$refs[$i]], 'usage' => 'both', 'name' => ''];
            $key = $from . ':' . $to . ':' . $name;
            if (isset($edgeKeys[$key])) { continue; }
            $layout['taxiways'][] = ['from' => $from, 'to' => $to, 'direction' => 'twoway', 'class' => 'taxiway', 'name' => $name, 'source' => 'OpenStreetMap'];
            $edgeKeys[$key] = true;
            $addedEdges++;
            if ($name !== '') { $namedEdges++; }
        }
    } elseif ($refs) {
        $points = array_map(static fn(string $ref): array => $nodePoints[$ref], $refs);
        $lat = array_sum(array_column($points, 0)) / count($points);
        $lon = array_sum(array_column($points, 1)) / count($points);
        $key = sprintf('%.6f:%.6f', $lat, $lon);
        if (!isset($standKeys[$key])) {
            $layout['stands'][] = ['point' => [$lat, $lon], 'heading' => 0, 'type' => 'misc', 'aircraft' => '', 'name' => trim($tags['ref'] ?? ($tags['name'] ?? '')), 'source' => 'OpenStreetMap'];
            $standKeys[$key] = true;
            $addedStands++;
        }
    }
}

foreach ($xml->node as $node) {
    $tags = $tagsOf($node);
    if (strtolower($tags['aeroway'] ?? '') !== 'parking_position') { continue; }
    $point = [(float)$node['lat'], (float)$node['lon']];
    $key = sprintf('%.6f:%.6f', $point[0], $point[1]);
    if (isset($standKeys[$key])) { continue; }
    $layout['stands'][] = ['point' => $point, 'heading' => 0, 'type' => 'misc', 'aircraft' => '', 'name' => trim($tags['ref'] ?? ($tags['name'] ?? '')), 'source' => 'OpenStreetMap'];
    $standKeys[$key] = true;
    $addedStands++;
}

$layout['counts']['taxiways'] = count($layout['taxiways']);
$layout['counts']['stands'] = count($layout['stands']);
$layout['quality'] = 'detailed_enriched';
$layout['sources'] = array_values(array_unique(array_merge((array)($layout['sources'] ?? [$layout['source'] ?? '']), ['OpenStreetMap'])));
$osmEdges = array_values(array_filter($layout['taxiways'], static fn(array $edge): bool => ($edge['source'] ?? '') === 'OpenStreetMap'));
$osmStands = array_values(array_filter($layout['stands'], static fn(array $stand): bool => ($stand['source'] ?? '') === 'OpenStreetMap'));
$layout['enrichment'] = [
    'source' => 'OpenStreetMap',
    'taxiway_segments' => count($osmEdges),
    'named_segments' => count(array_filter($osmEdges, static fn(array $edge): bool => trim((string)($edge['name'] ?? '')) !== '')),
    'stands' => count($osmStands),
];

file_put_contents($layoutPath, json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
printf("%s: +%d taxiway segments (%d named), +%d stands\n", $icao, $addedEdges, $namedEdges, $addedStands);
