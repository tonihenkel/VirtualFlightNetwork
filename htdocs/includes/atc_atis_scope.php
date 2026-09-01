<?php
declare(strict_types=1);

require_once __DIR__ . '/atc_frequency_catalog.php';

function loadGlobalAtisFrequencies(): array
{
    static $frequencies = null;
    if ($frequencies !== null) return $frequencies;
    $frequencies = [];
    $path = dirname(__DIR__) . '/data/airports/airport-frequencies.csv';
    $handle = @fopen($path, 'rb');
    if ($handle === false) return $frequencies;
    $header = fgetcsv($handle);
    $columns = is_array($header) ? array_flip($header) : [];
    while (($row = fgetcsv($handle)) !== false) {
        $icao = strtoupper(trim((string)($row[$columns['airport_ident'] ?? 2] ?? '')));
        $type = strtoupper(trim((string)($row[$columns['type'] ?? 3] ?? '')));
        if ($icao === '' || !in_array($type, ['ATIS', 'D-ATIS'], true)) continue;
        $frequency = normalizeAtcVoiceFrequency(
            (string)($row[$columns['frequency_mhz'] ?? 5] ?? '')
        );
        if ($frequency !== '' && !isset($frequencies[$icao])) $frequencies[$icao] = $frequency;
    }
    fclose($handle);
    return $frequencies;
}

function pointInAtisRing(float $longitude, float $latitude, array $ring): bool
{
    $inside = false;
    $count = count($ring);
    if ($count < 3) return false;
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $left = $ring[$i] ?? null;
        $right = $ring[$j] ?? null;
        if (!is_array($left) || !is_array($right)) continue;
        $xi = (float)($left[0] ?? 0); $yi = (float)($left[1] ?? 0);
        $xj = (float)($right[0] ?? 0); $yj = (float)($right[1] ?? 0);
        $intersects = (($yi > $latitude) !== ($yj > $latitude))
            && ($longitude < ($xj - $xi) * ($latitude - $yi)
                / (($yj - $yi) ?: 1.0e-12) + $xi);
        if ($intersects) $inside = !$inside;
    }
    return $inside;
}

function pointInAtisGeometry(float $longitude, float $latitude, array $geometry): bool
{
    $type = (string)($geometry['type'] ?? '');
    $coordinates = $geometry['coordinates'] ?? [];
    $polygons = $type === 'Polygon' ? [$coordinates]
        : ($type === 'MultiPolygon' ? $coordinates : []);
    foreach ($polygons as $polygon) {
        if (!is_array($polygon) || empty($polygon[0])
            || !pointInAtisRing($longitude, $latitude, $polygon[0])) continue;
        $inHole = false;
        for ($index = 1; $index < count($polygon); ++$index) {
            if (pointInAtisRing($longitude, $latitude, $polygon[$index])) {
                $inHole = true; break;
            }
        }
        if (!$inHole) return true;
    }
    return false;
}

function atisDistanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthNm = 3440.065;
    $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthNm * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}

function readCompiledAtisSector(string $station): array
{
    $indexPath = dirname(__DIR__) . '/data/atc/sector-boundaries.index.json';
    $dataPath = dirname(__DIR__) . '/data/atc/sector-boundaries.ndjson';
    $index = is_file($indexPath) ? json_decode((string)file_get_contents($indexPath), true) : null;
    $key = normalizeAtcStationCode($station);
    $stations = is_array($index['stations'] ?? null) ? $index['stations'] : [];
    $entries = [];
    if (is_array($stations[$key] ?? null)) {
        $entries[$key] = $stations[$key];
    } else {
        // Some selectable VATSpy positions are composites whose operational
        // VATGlasses records only exist as numbered sub-sectors. BIRD-S, for
        // example, is stored as BIRD_S1, BIRD_S2 and BIRD_S3. Treat those
        // records as one scope so airports such as BIRK do not disappear from
        // navigation, Airport ATIS, METAR watch and airport layouts.
        foreach ($stations as $stationKey => $candidate) {
            if (preg_match('/^' . preg_quote($key, '/') . '[0-9]+$/', (string)$stationKey)
                && is_array($candidate)) {
                $entries[(string)$stationKey] = $candidate;
            }
        }
        uksort($entries, 'strnatcasecmp');
    }
    if (!$entries || !is_file($dataPath)) return [];
    $handle = @fopen($dataPath, 'rb');
    if ($handle === false) return [];
    $features = [];
    foreach ($entries as $entry) {
        if (fseek($handle, (int)($entry['offset'] ?? -1)) !== 0) continue;
        $record = json_decode((string)fread($handle, (int)($entry['length'] ?? 0)), true);
        if (is_array($record['features'] ?? null)) {
            array_push($features, ...$record['features']);
        }
    }
    fclose($handle);
    return $features;
}

function readAtisScopeFeatures(array $session): array
{
    $station = normalizeAtcStationCode((string)($session['station_code'] ?? ''));
    $position = strtoupper((string)($session['position_code'] ?? ''));
    $boundary = strtoupper((string)($session['radar_boundary_code'] ?? ''));
    static $requestCache = [];
    $cacheKey = $station . '|' . $position . '|' . $boundary;
    if (array_key_exists($cacheKey, $requestCache)) return $requestCache[$cacheKey];
    if ($position === 'CTR') {
        $features = readCompiledAtisSector($station);
        if ($features) return $requestCache[$cacheKey] = $features;
        $fallback = strtoupper((string)($session['radar_boundary_code'] ?? $station));
        $path = dirname(__DIR__) . '/data/atc/fir-boundaries.geojson';
        $geojson = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        foreach (is_array($geojson['features'] ?? null) ? $geojson['features'] : [] as $feature) {
            if (strtoupper((string)($feature['properties']['id'] ?? '')) === $fallback) return $requestCache[$cacheKey] = [$feature];
        }
        return $requestCache[$cacheKey] = [];
    }
    if (in_array($position, ['APP', 'DEP'], true)) {
        $path = dirname(__DIR__) . '/data/atc/tracon-boundaries.geojson';
        $geojson = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        $features = [];
        foreach (is_array($geojson['features'] ?? null) ? $geojson['features'] : [] as $feature) {
            $properties = $feature['properties'] ?? [];
            $prefixes = is_array($properties['prefix'] ?? null)
                ? $properties['prefix'] : [$properties['prefix'] ?? ''];
            $prefixes = array_map(static function ($value): string {
                return strtoupper(trim((string)$value));
            }, $prefixes);
            $suffix = strtoupper((string)($properties['suffix'] ?? 'APP'));
            if (in_array($station, $prefixes, true) && $suffix === $position) $features[] = $feature;
        }
        return $requestCache[$cacheKey] = $features;
    }
    return $requestCache[$cacheKey] = [];
}

function getAtisAirportsForSession(PDO $pdo, array $session, bool $includeSmallAirports = false): array
{
    $station = normalizeAtcStationCode((string)($session['station_code'] ?? ''));
    $position = strtoupper((string)($session['position_code'] ?? ''));
    $frequencies = loadGlobalAtisFrequencies();
    if (!in_array($position, ['APP', 'DEP', 'CTR'], true)) {
        if (!isset($frequencies[$station]) && !$includeSmallAirports) return [];
        $wanted = [$station => $frequencies[$station] ?? ''];
    } else {
        $wanted = $frequencies;
    }
    $airports = [];
    foreach (array_chunk(array_keys($wanted), 500) as $codes) {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $pdo->prepare(
            "SELECT ident,icao_code,gps_code,name,municipality,latitude_deg,longitude_deg
             FROM airports WHERE UPPER(ident) IN ($placeholders)
                OR UPPER(icao_code) IN ($placeholders) OR UPPER(gps_code) IN ($placeholders)"
        );
        $stmt->execute(array_merge($codes, $codes, $codes));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Preserve the established compact ATIS list here. The canonical
            // `ident` expansion belongs only to the explicit small-airfield mode below.
            $code = strtoupper(trim((string)($row['icao_code'] ?: $row['gps_code'] ?: $row['ident'])));
            if (isset($wanted[$code])) $airports[$code] = $row + ['code' => $code];
        }
    }
    if ($includeSmallAirports && in_array($position, ['APP', 'DEP', 'CTR'], true)) {
        $features = readAtisScopeFeatures($session);
        $latitudes = []; $longitudes = [];
        $visit = static function ($value) use (&$visit, &$latitudes, &$longitudes): void {
            if (!is_array($value)) return;
            if (count($value) >= 2 && is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null)) {
                $longitudes[] = (float)$value[0]; $latitudes[] = (float)$value[1]; return;
            }
            foreach ($value as $child) $visit($child);
        };
        foreach ($features as $feature) $visit($feature['geometry']['coordinates'] ?? []);
        if ($latitudes && $longitudes && max($longitudes) - min($longitudes) <= 180.0) {
            $stmt = $pdo->prepare(
                "SELECT ident,icao_code,gps_code,name,municipality,latitude_deg,longitude_deg
                 FROM airports WHERE latitude_deg BETWEEN :min_lat AND :max_lat
                   AND longitude_deg BETWEEN :min_lon AND :max_lon
                   AND COALESCE(type,'') NOT IN ('closed_airport','heliport')"
            );
            $stmt->execute([
                'min_lat'=>min($latitudes), 'max_lat'=>max($latitudes),
                'min_lon'=>min($longitudes), 'max_lon'=>max($longitudes),
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                // `ident` is the canonical OurAirports identifier. Optional
                // ICAO/GPS columns are often blank or non-ICAO for small fields.
                $code = strtoupper(trim((string)($row['ident'] ?: $row['icao_code'] ?: $row['gps_code'])));
                if (!preg_match('/^[A-Z]{4}$/', $code)) continue;
                $airports[$code] = $row + ['code'=>$code];
                if (!isset($wanted[$code])) $wanted[$code] = '';
            }
        }
    }
    if (in_array($position, ['APP', 'DEP', 'CTR'], true)) {
        $features = readAtisScopeFeatures($session);
        if ($features) {
            $airports = array_filter($airports, static function (array $airport) use ($features): bool {
                $longitude = (float)$airport['longitude_deg'];
                $latitude = (float)$airport['latitude_deg'];
                foreach ($features as $feature) {
                    if (pointInAtisGeometry($longitude, $latitude, $feature['geometry'] ?? [])) return true;
                }
                return false;
            });
        } elseif (in_array($position, ['APP', 'DEP'], true)) {
            $centerStmt = $pdo->prepare(
                "SELECT latitude_deg,longitude_deg FROM airports
                 WHERE UPPER(ident)=:station OR UPPER(icao_code)=:station OR UPPER(gps_code)=:station LIMIT 1"
            );
            $centerStmt->execute(['station'=>$station]);
            $center = $centerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$center) return [];
            $airports = array_filter($airports, static function (array $airport) use ($center): bool {
                return atisDistanceNm(
                    (float)$center['latitude_deg'], (float)$center['longitude_deg'],
                    (float)$airport['latitude_deg'], (float)$airport['longitude_deg']
                ) <= 150.0;
            });
        } else return [];
    }
    $localStmt = $pdo->query(
        "SELECT a.station_code,a.position_code,a.user_id,
                COALESCE(NULLIF(TRIM(u.real_name),''),u.username) AS controller_name
         FROM atc_sessions a JOIN users u ON u.id=a.user_id
         WHERE a.is_active=1 AND a.is_spectator=0 AND a.position_code<>'CTR'
           AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)"
    );
    $local = [];
    foreach ($localStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $local[normalizeAtcStationCode((string)$row['station_code'])] = $row;
    }
    $broadcasts = [];
    try {
        foreach ($pdo->query("SELECT airport_icao,frequency,info_letter,active_runway,is_active,updated_at FROM auto_atis_broadcasts")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $broadcasts[strtoupper((string)$row['airport_icao'])] = $row;
        }
    } catch (Throwable $ignored) {}
    $result = [];
    foreach ($airports as $code => $airport) {
        $manager = $local[$code] ?? null;
        $managedByOther = $manager && (int)$manager['user_id'] !== (int)($session['user_id'] ?? 0)
            && $station !== $code;
        $broadcast = $broadcasts[$code] ?? null;
        $result[] = [
            'icao' => $code,
            'name' => (string)$airport['name'],
            'municipality' => (string)($airport['municipality'] ?? ''),
            'latitude' => (float)$airport['latitude_deg'],
            'longitude' => (float)$airport['longitude_deg'],
            'frequency' => $wanted[$code] ?? '',
            'managed_by' => $managedByOther ? (string)$manager['controller_name'] : '',
            'managed_position' => $managedByOther ? (string)$manager['position_code'] : '',
            'editable' => $managedByOther ? 0 : 1,
            'active' => (int)($broadcast['is_active'] ?? 0),
            'info_letter' => (string)($broadcast['info_letter'] ?? ''),
            'active_runway' => (string)($broadcast['active_runway'] ?? ''),
            'updated_at' => (string)($broadcast['updated_at'] ?? ''),
        ];
    }
    usort($result, static function (array $left, array $right): int {
        return [$right['active'], $left['icao']] <=> [$left['active'], $right['icao']];
    });
    return $result;
}
