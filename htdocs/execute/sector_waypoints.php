<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// AIRAC pages are cached server-side below. Do not cache the assembled sector
// response in browsers or proxies, otherwise geometry/query fixes remain stale.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
require_once __DIR__ . '/../includes/atc_atis_scope.php';

function sectorWaypointJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sectorWaypointCoordinates($value, array &$latitudes, array &$longitudes): void
{
    if (!is_array($value)) return;
    if (count($value) >= 2 && is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null)) {
        $longitudes[] = (float)$value[0];
        $latitudes[] = (float)$value[1];
        return;
    }
    foreach ($value as $child) sectorWaypointCoordinates($child, $latitudes, $longitudes);
}

function sectorWaypointFetch(float $latitude, float $longitude, float $radius, int $page): array
{
    $url = 'https://airac.net/api/v1/waypoints/nearby?' . http_build_query([
        'latitude' => round($latitude, 6), 'longitude' => round($longitude, 6),
        'radius' => round($radius, 1), 'per_page' => 100, 'page' => $page,
    ]);
    $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vfn_sector_waypoints_'
        . hash('sha256', $url) . '.json';
    if (is_file($cachePath) && time() - (int)filemtime($cachePath) < 21600) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached)) return $cached;
    }
    $context = stream_context_create(['http' => [
        'timeout' => 15,
        'header' => "Accept: application/json\r\nUser-Agent: VirtualFlightNetwork-Map/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $context);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
        return ['rows' => [], 'has_more' => false, 'failed' => true];
    }
    $data = $decoded['data'] ?? [];
    $isList = is_array($data) && ($data === [] || array_keys($data) === range(0, count($data) - 1));
    $pagination = is_array($decoded['pagination'] ?? null) ? $decoded['pagination'] : [];
    $result = [
        'rows' => is_array($data) ? ($isList ? $data : [$data]) : [],
        'has_more' => (bool)($pagination['has_more'] ?? (is_array($data) && count($data) >= 100)),
    ];
    @file_put_contents($cachePath, json_encode($result), LOCK_EX);
    return $result;
}

function sectorWaypointFetchTiles(array $tiles): array
{
    $results = [];
    foreach (array_chunk($tiles, 12, true) as $batch) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($batch as $key => $tile) {
            $url = 'https://airac.net/api/v1/waypoints/nearby?' . http_build_query([
                'latitude' => round($tile['latitude'], 6),
                'longitude' => round($tile['longitude'], 6),
                'radius' => round($tile['radius'], 1),
            ]);
            $cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'vfn_sector_waypoints_' . hash('sha256', $url) . '.json';
            if (is_file($cachePath) && time() - (int)filemtime($cachePath) < 21600) {
                $cached = json_decode((string)@file_get_contents($cachePath), true);
                if (is_array($cached)) {
                    $results[$key] = $cached;
                    continue;
                }
            }
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'VirtualFlightNetwork-Map/1.0',
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = [$handle, $cachePath];
        }
        if ($handles) {
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) curl_multi_select($multi, 1.0);
            } while ($running && $status === CURLM_OK);
            foreach ($handles as $key => [$handle, $cachePath]) {
                $body = curl_multi_getcontent($handle);
                $decoded = is_string($body) ? json_decode($body, true) : null;
                if (is_array($decoded) && ($decoded['status'] ?? '') === 'success') {
                    $data = $decoded['data'] ?? [];
                    $isList = is_array($data)
                        && ($data === [] || array_keys($data) === range(0, count($data) - 1));
                    $result = ['rows' => is_array($data) ? ($isList ? $data : [$data]) : []];
                    $results[$key] = $result;
                    @file_put_contents($cachePath, json_encode($result), LOCK_EX);
                } else {
                    $results[$key] = ['rows' => [], 'failed' => true];
                }
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
        }
        curl_multi_close($multi);
    }
    return $results;
}

$station = strtoupper(str_replace('-', '_', trim((string)($_GET['station'] ?? ''))));
if (!preg_match('/^[A-Z0-9_]{2,32}$/', $station)) {
    sectorWaypointJson(['success' => false, 'message' => 'invalid_station'], 422);
}

try {
    // The map search displays the VATSpy/FIR feature, which can differ from
    // the operational VATGlasses sector geometry. Use that same visible
    // feature so the waypoint filter covers exactly the blue polygon.
    $features = [];
    $firPath = dirname(__DIR__) . '/data/atc/fir-boundaries.geojson';
    $firPayload = is_file($firPath)
        ? json_decode((string)file_get_contents($firPath), true)
        : null;
    $wantedFirId = str_replace('_', '-', $station);
    foreach ((array)($firPayload['features'] ?? []) as $feature) {
        $featureId = strtoupper(trim((string)($feature['properties']['id'] ?? '')));
        if ($featureId === $wantedFirId) {
            $features[] = $feature;
        }
    }
    // Retain the compiled-sector fallback for identifiers that are not part
    // of the searchable FIR/VATSpy dataset.
    if (!$features) $features = readCompiledAtisSector($station);
    if (!$features) sectorWaypointJson(['success' => false, 'message' => 'sector_not_found'], 404);
    $latitudes = []; $longitudes = [];
    foreach ($features as $feature) {
        sectorWaypointCoordinates($feature['geometry']['coordinates'] ?? [], $latitudes, $longitudes);
    }
    if (!$latitudes || !$longitudes) {
        sectorWaypointJson(['success' => false, 'message' => 'sector_geometry_invalid'], 500);
    }
    $minLatitude = min($latitudes);
    $maxLatitude = max($latitudes);
    // A sector crossing the antimeridian contains values near both -180 and
    // +180. Shift its western coordinates into 0..360 for a compact query
    // extent, then normalize each generated centre back for the API.
    if (max($longitudes) - min($longitudes) > 180.0) {
        $longitudes = array_map(static function (float $longitude): float {
            return $longitude < 0.0 ? $longitude + 360.0 : $longitude;
        }, $longitudes);
    }
    $minLongitude = min($longitudes);
    $maxLongitude = max($longitudes);
    // Nearby results are ordered by their distance from the requested centre.
    // A single large-radius request can therefore exhaust the pagination limit
    // before reaching fixes near a sector edge. Cover the bounding box with
    // overlapping local circles instead and merge their results below. The
    // nearby contract does not guarantee pagination, so keep each circle small
    // enough that its nearest-result cap cannot create one central cluster.
    $middleLatitude = ($minLatitude + $maxLatitude) / 2;
    $latitudeSpan = max(0.01, $maxLatitude - $minLatitude);
    $longitudeSpan = max(0.01, $maxLongitude - $minLongitude);
    $latitudeNm = $latitudeSpan * 60.0;
    $longitudeNm = $longitudeSpan * 60.0
        * max(0.2, cos(deg2rad($middleLatitude)));
    $maximumTiles = 48;
    $latitudeTiles = max(1, (int)round(sqrt(
        $maximumTiles * $latitudeNm / max(1.0, $longitudeNm)
    )));
    $longitudeTiles = max(1, (int)floor($maximumTiles / $latitudeTiles));
    $tileLatitudeStep = $latitudeSpan / $latitudeTiles;
    $tileLongitudeStep = $longitudeSpan / $longitudeTiles;
    // AIRAC validates nearby radii to a maximum of 500 NM.
    $tileRadius = max(20.0, min(500.0, 0.58 * sqrt(
        ($tileLatitudeStep * 60.0) ** 2
        + ($tileLongitudeStep * 60.0 * max(0.2, cos(deg2rad($middleLatitude)))) ** 2
    )));
    $tiles = [];
    for ($latitudeIndex = 0; $latitudeIndex < $latitudeTiles; ++$latitudeIndex) {
        for ($longitudeIndex = 0; $longitudeIndex < $longitudeTiles; ++$longitudeIndex) {
            $tileLongitude = $minLongitude
                + ($longitudeIndex + 0.5) * $tileLongitudeStep;
            if ($tileLongitude > 180.0) $tileLongitude -= 360.0;
            $tiles[] = [
                'latitude' => $minLatitude + ($latitudeIndex + 0.5) * $tileLatitudeStep,
                'longitude' => $tileLongitude,
                'radius' => $tileRadius,
            ];
        }
    }
    $points = []; $sourceAvailable = false;
    foreach (sectorWaypointFetchTiles($tiles) as $source) {
        if (empty($source['failed'])) {
            $sourceAvailable = true;
                foreach ((array)($source['rows'] ?? []) as $item) {
                    $coordinates = (array)($item['coordinates'] ?? []);
                    $lat = $coordinates['lat'] ?? $item['latitude'] ?? null;
                    $lon = $coordinates['lon'] ?? $item['longitude'] ?? null;
                    if (!is_numeric($lat) || !is_numeric($lon)) continue;
                    $inside = false;
                    foreach ($features as $feature) {
                        if (pointInAtisGeometry(
                            (float)$lon, (float)$lat, $feature['geometry'] ?? []
                        )) {
                            $inside = true;
                            break;
                        }
                    }
                    $identifier = strtoupper(trim((string)($item['identifier'] ?? '')));
                    if (!$inside || $identifier === '') continue;
                    $key = $identifier . ':' . round((float)$lat, 5) . ':' . round((float)$lon, 5);
                    $points[$key] = ['identifier' => $identifier,
                        'latitude' => (float)$lat, 'longitude' => (float)$lon];
                }
        }
    }
    if (!$sourceAvailable) sectorWaypointJson(['success' => false, 'message' => 'airac_unavailable'], 502);
    sectorWaypointJson(['success' => true, 'station' => $station, 'points' => array_values($points)]);
} catch (Throwable $error) {
    error_log('Sector waypoint loading failed: ' . $error->getMessage());
    sectorWaypointJson(['success' => false, 'message' => 'waypoints_unavailable'], 500);
}
