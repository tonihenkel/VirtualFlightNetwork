<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';

function metarWatchCachePath(string $name): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vfn_datis_'
        . preg_replace('/[^A-Za-z0-9_-]/', '', $name) . '.txt';
}

function metarWatchFetchBulk(string $url, int $maxAge): ?string
{
    $path = metarWatchCachePath('metars_bulk_xml');
    if (is_file($path) && time() - (int)filemtime($path) < $maxAge) {
        $cached = @file_get_contents($path);
        if (is_string($cached) && trim($cached) !== '') return $cached;
    }
    $context = stream_context_create(['http' => [
        'timeout' => 10,
        'header' => "User-Agent: VFN-ATC-METAR/1.0\r\n",
    ]]);
    $compressed = @file_get_contents($url, false, $context);
    if (!is_string($compressed) || $compressed === '') {
        $stale = is_file($path) ? @file_get_contents($path) : false;
        return is_string($stale) && trim($stale) !== '' ? $stale : null;
    }
    $xml = @gzdecode($compressed);
    if (!is_string($xml) || trim($xml) === '') {
        $stale = is_file($path) ? @file_get_contents($path) : false;
        return is_string($stale) && trim($stale) !== '' ? $stale : null;
    }
    @file_put_contents($path, $xml);
    return $xml;
}

function metarWatchValue(SimpleXMLElement $node, string $name): string
{
    return trim((string)($node->{$name} ?? ''));
}

function metarWatchReport(SimpleXMLElement $node): array
{
    $station = strtoupper(metarWatchValue($node, 'station_id'));
    $observed = metarWatchValue($node, 'observation_time');
    $timestamp = strtotime($observed);
    $age = $timestamp === false ? null : max(0, (int)floor((time() - $timestamp) / 60));
    $direction = metarWatchValue($node, 'wind_dir_degrees');
    $speed = metarWatchValue($node, 'wind_speed_kt');
    $gust = metarWatchValue($node, 'wind_gust_kt');
    $wind = $speed === '' ? '-' : (($direction === '' || strtoupper($direction) === 'VRB')
        ? 'VRB' : sprintf('%03d', (int)$direction)) . '/' . (int)$speed
        . ($gust === '' ? '' : 'G' . (int)$gust) . 'KT';
    $visibility = metarWatchValue($node, 'visibility_statute_mi');
    $pressure = metarWatchValue($node, 'sea_level_pressure_mb');
    if ($pressure === '') {
        $altimeter = metarWatchValue($node, 'altim_in_hg');
        $pressure = $altimeter === '' ? '' : (string)round((float)$altimeter * 33.8639);
    }
    $clouds = [];
    foreach ($node->sky_condition ?? [] as $sky) {
        $cover = trim((string)($sky['sky_cover'] ?? ''));
        $base = trim((string)($sky['cloud_base_ft_agl'] ?? ''));
        if ($cover !== '') $clouds[] = $cover . ($base === '' ? '' : sprintf('%03d', (int)round((float)$base / 100)));
    }
    return [
        'icao' => $station,
        'observed_at' => $timestamp === false ? $observed : gmdate('H:i', $timestamp) . 'Z',
        'age_minutes' => $age,
        'wind' => $wind,
        'wind_direction' => $direction === '' ? '-' : strtoupper($direction),
        'wind_speed' => $speed === '' ? '-' : (string)(int)$speed . 'KT',
        'wind_gust' => $gust === '' ? '-' : (string)(int)$gust . 'KT',
        'visibility' => $visibility === '' ? '-' : $visibility . 'SM',
        'weather' => metarWatchValue($node, 'wx_string') ?: '-',
        'clouds' => $clouds ? implode(' ', $clouds) : '-',
        'temperature' => metarWatchValue($node, 'temp_c'),
        'dewpoint' => metarWatchValue($node, 'dewpoint_c'),
        'qnh' => $pressure === '' ? '-' : 'Q' . str_pad((string)round((float)$pressure), 4, '0', STR_PAD_LEFT),
        'raw' => metarWatchValue($node, 'raw_text'),
        'latitude' => metarWatchValue($node, 'latitude'),
        'longitude' => metarWatchValue($node, 'longitude'),
    ];
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        http_response_code(401); throw new RuntimeException('login_required');
    }
    ensureAtcSchema($pdo);
    $stmt = $pdo->prepare(
        "SELECT station_code,position_code,radar_boundary_code,is_spectator,can_control,user_id
         FROM atc_sessions WHERE user_id=:user AND session_token=:token AND is_active=1
           AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $stmt->execute(['user' => (int)$_SESSION['web_user_id'], 'token' => (string)($_SESSION['atc_session_token'] ?? '')]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) { http_response_code(409); throw new RuntimeException('atc_session_inactive'); }

    $xmlText = metarWatchFetchBulk(
        trim((string)($aviationWeatherMetarCacheUrl ?? '')),
        max(60, (int)($metarCacheSeconds ?? 1800))
    );
    if ($xmlText === null) throw new RuntimeException('metar_unavailable');
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlText);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    if ($xml === false) throw new RuntimeException('metar_unavailable');

    $station = normalizeAtcStationCode((string)$session['station_code']);
    $position = strtoupper((string)$session['position_code']);
    $sectorPosition = in_array($position, ['APP', 'DEP', 'CTR'], true);
    $features = $sectorPosition ? readAtisScopeFeatures($session) : [];
    $fallbackCenter = null;
    if (!$features && in_array($position, ['APP', 'DEP'], true)) {
        $centerStmt = $pdo->prepare(
            "SELECT latitude_deg,longitude_deg FROM airports
             WHERE UPPER(ident)=:station OR UPPER(icao_code)=:station OR UPPER(gps_code)=:station LIMIT 1"
        );
        $centerStmt->execute(['station' => $station]);
        $fallbackCenter = $centerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $reports = [];
    foreach ($xml->xpath('//METAR') ?: [] as $node) {
        $icao = strtoupper(metarWatchValue($node, 'station_id'));
        if ($icao === '') continue;
        if (!$sectorPosition) {
            if ($icao !== $station) continue;
        } elseif ($features) {
            $latitude = metarWatchValue($node, 'latitude');
            $longitude = metarWatchValue($node, 'longitude');
            if ($latitude === '' || $longitude === '') continue;
            $inside = false;
            foreach ($features as $feature) {
                if (pointInAtisGeometry((float)$longitude, (float)$latitude, $feature['geometry'] ?? [])) {
                    $inside = true; break;
                }
            }
            if (!$inside) continue;
        } elseif ($fallbackCenter) {
            $latitude = metarWatchValue($node, 'latitude');
            $longitude = metarWatchValue($node, 'longitude');
            if ($latitude === '' || $longitude === '' || atisDistanceNm(
                (float)$fallbackCenter['latitude_deg'], (float)$fallbackCenter['longitude_deg'],
                (float)$latitude, (float)$longitude
            ) > 150.0) continue;
        } else {
            continue;
        }
        $reports[] = metarWatchReport($node);
    }
    // APP/DEP must always see the METAR of their own airport. Sector polygons
    // can be incomplete, overlap or use a regional station name and must not
    // make the primary airport disappear from the watch list.
    if (in_array($position, ['APP', 'DEP'], true) && preg_match('/^[A-Z0-9]{4}$/', $station)) {
        $hasPrimary = false;
        foreach ($reports as $report) {
            if (($report['icao'] ?? '') === $station) { $hasPrimary = true; break; }
        }
        if (!$hasPrimary) {
            foreach ($xml->xpath('//METAR') ?: [] as $node) {
                if (strtoupper(metarWatchValue($node, 'station_id')) === $station) {
                    $reports[] = metarWatchReport($node);
                    break;
                }
            }
        }
    }
    if ($reports) {
        $runwayMap = [];
        try {
            $placeholders = implode(',', array_fill(0, count($reports), '?'));
            $runwayStmt = $pdo->prepare(
                "SELECT b.airport_icao,b.active_runway,
                        o.arrival_runways,o.departure_runways,o.is_active AS override_active
                 FROM auto_atis_broadcasts b
                 LEFT JOIN atc_atis_overrides o ON o.airport_icao=b.airport_icao
                 WHERE b.airport_icao IN ($placeholders)"
            );
            $runwayStmt->execute(array_column($reports, 'icao'));
            foreach ($runwayStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $automatic = trim((string)($row['active_runway'] ?? ''));
                $manual = (int)($row['override_active'] ?? 0) === 1;
                $arrival = $manual ? trim((string)($row['arrival_runways'] ?? '')) : $automatic;
                $departure = $manual ? trim((string)($row['departure_runways'] ?? '')) : $automatic;
                $runwayMap[strtoupper((string)$row['airport_icao'])] = [
                    'arrival_runways' => $arrival !== '' ? $arrival : $automatic,
                    'departure_runways' => $departure !== '' ? $departure : $automatic,
                ];
            }
        } catch (Throwable $ignored) {
            $runwayMap = [];
        }
        foreach ($reports as &$report) {
            $runways = $runwayMap[$report['icao']] ?? [];
            $report['arrival_runways'] = (string)($runways['arrival_runways'] ?? '');
            $report['departure_runways'] = (string)($runways['departure_runways'] ?? '');
        }
        unset($report);
    }
    usort($reports, static fn(array $a, array $b): int => $a['icao'] <=> $b['icao']);
    echo json_encode([
        'success' => true,
        'reports' => $reports,
        'updated_at' => gmdate('H:i:s') . 'Z',
        'scope' => [
            'station' => $station,
            'position' => $position,
            'features' => count($features),
            'reports' => count($reports),
        ],
    ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
