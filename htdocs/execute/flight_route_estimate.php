<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';

const VFN_ROUTE_AIRAC_BASE_URL = 'https://airac.net/api/v1';
const VFN_ROUTE_CACHE_SECONDS = 86400;
const VFN_ROUTE_MAX_TOKENS = 30;

function routeJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function routeDistanceNm(array $a, array $b): float
{
    $lat1 = deg2rad((float)$a['latitude']);
    $lat2 = deg2rad((float)$b['latitude']);
    $deltaLat = $lat2 - $lat1;
    $deltaLon = deg2rad((float)$b['longitude'] - (float)$a['longitude']);
    $value = sin($deltaLat / 2) ** 2
        + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
    return 3440.065 * 2 * atan2(sqrt($value), sqrt(max(0.0, 1.0 - $value)));
}

function routeAirport(PDO $pdo, string $code): ?array
{
    if ($code === '' || $code === 'ZZZZ') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT ident, icao_code, gps_code, latitude_deg, longitude_deg
         FROM airports
         WHERE ident = :code OR icao_code = :code OR gps_code = :code
         LIMIT 1'
    );
    $stmt->execute(['code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_numeric($row['latitude_deg']) || !is_numeric($row['longitude_deg'])) {
        return null;
    }
    return [
        'identifier' => $code,
        'kind' => 'airport',
        'latitude' => (float)$row['latitude_deg'],
        'longitude' => (float)$row['longitude_deg'],
    ];
}

function routeRunwayThreshold(string $airport, string $runway): ?array
{
    $airport = strtoupper(trim($airport));
    $runway = strtoupper(trim($runway));
    if (!preg_match('/^[A-Z0-9-]{2,15}$/', $airport) || !preg_match('/^\d{2}[LCR]?$/', $runway)) {
        return null;
    }
    $path = __DIR__ . '/../data/airport_layouts/' . $airport . '.json';
    if (!is_file($path)) {
        return null;
    }
    $layout = json_decode((string)file_get_contents($path), true);
    foreach ((array)($layout['runways'] ?? []) as $item) {
        foreach ((array)($item['ends'] ?? []) as $end) {
            $point = $end['point'] ?? null;
            if (strtoupper(trim((string)($end['ident'] ?? ''))) !== $runway
                || !is_array($point) || count($point) < 2
                || !is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }
            return [
                'identifier' => 'RWY ' . $runway,
                'kind' => 'runway',
                'latitude' => (float)$point[0],
                'longitude' => (float)$point[1],
            ];
        }
    }
    return null;
}

function routeSameIdentifier(array $point, string $identifier): bool
{
    return strtoupper(trim((string)($point['identifier'] ?? ''))) === strtoupper(trim($identifier));
}

function routeCachedFetch(string $cacheKey, string $url): ?array
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'vfn_route_' . hash('sha256', $cacheKey) . '.json';
    if (is_file($path) && time() - (int)filemtime($path) < VFN_ROUTE_CACHE_SECONDS) {
        $cached = json_decode((string)@file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $context = stream_context_create(['http' => [
        'timeout' => 10,
        'header' => "Accept: application/json\r\nUser-Agent: VirtualFlightNetwork/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    @file_put_contents(
        $path,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $data;
}

function routeCandidates(string $identifier): array
{
    $url = VFN_ROUTE_AIRAC_BASE_URL . '/search?'
        . http_build_query(['q' => $identifier, 'limit' => 8])
        . '&types=waypoint&types=navaid';
    $response = routeCachedFetch('point_' . $identifier, $url);
    if (!is_array($response) || ($response['status'] ?? '') !== 'success') {
        return [];
    }
    $result = [];
    foreach (['waypoints' => 'waypoint', 'navaids' => 'navaid'] as $group => $kind) {
        foreach ((array)($response['data'][$group] ?? []) as $item) {
            if (strtoupper(trim((string)($item['identifier'] ?? ''))) !== $identifier) {
                continue;
            }
            $coordinates = (array)($item['coordinates'] ?? []);
            $latitude = $coordinates['lat'] ?? $item['latitude'] ?? null;
            $longitude = $coordinates['lon'] ?? $item['longitude'] ?? null;
            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            $result[] = [
                'identifier' => $identifier,
                'kind' => $kind,
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
            ];
        }
    }
    return $result;
}

function routeApiPoint($raw, string $fallbackKind = 'waypoint'): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $coordinates = is_array($raw['coordinates'] ?? null) ? $raw['coordinates'] : [];
    $latitude = $coordinates['lat'] ?? $raw['latitude'] ?? null;
    $longitude = $coordinates['lon'] ?? $raw['longitude'] ?? null;
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        return null;
    }
    $rawType = $raw['type'] ?? $fallbackKind;
    $kind = is_array($rawType) ? (string)($rawType['code'] ?? $fallbackKind) : (string)$rawType;
    return [
        'identifier' => strtoupper(trim((string)($raw['identifier'] ?? $raw['icao'] ?? ''))),
        'kind' => strtolower(trim($kind)) ?: $fallbackKind,
        'latitude' => (float)$latitude,
        'longitude' => (float)$longitude,
    ];
}

function routeProcedureAliasMatches(string $filed, string $airac): bool
{
    if ($filed === $airac) {
        return true;
    }
    $longer = strlen($filed) > strlen($airac) ? $filed : $airac;
    $shorter = $longer === $filed ? $airac : $filed;
    if (strlen($longer) !== strlen($shorter) + 1) {
        return false;
    }
    for ($index = 0, $length = strlen($longer); $index < $length; $index++) {
        if (substr($longer, 0, $index) . substr($longer, $index + 1) === $shorter) {
            return true;
        }
    }
    return false;
}

function routeAirportProcedures(string $airport, string $type): array
{
    $procedures = [];
    for ($page = 1; $page <= 10; $page++) {
        $url = VFN_ROUTE_AIRAC_BASE_URL . '/procedures?'
            . http_build_query([
                'airport' => $airport,
                'type' => $type,
                'per_page' => 100,
                'page' => $page,
            ]);
        $response = routeCachedFetch('procedures_' . $airport . '_' . $type . '_' . $page, $url);
        if (!is_array($response) || ($response['status'] ?? '') !== 'success') {
            break;
        }
        foreach ((array)($response['data'] ?? []) as $item) {
            if (is_array($item) && strtoupper((string)($item['type']['code'] ?? '')) === $type) {
                $identifier = strtoupper(trim((string)($item['identifier'] ?? '')));
                if ($identifier !== '') {
                    // Listings can contain one row per runway transition. For
                    // route matching this is still one procedure identifier.
                    $procedures[$identifier] = $item;
                }
            }
        }
        if (empty($response['pagination']['has_more'])) {
            break;
        }
    }
    return array_values($procedures);
}

/**
 * Let AIRAC.net expand airways as well as SID/STAR procedure legs. Procedure
 * aliases are resolved against the actual departure/arrival airport data.
 * Some providers omit one character from a seven-character operational name;
 * such a correction is accepted only when it yields one unique procedure.
 */
function routePrepareProcedures(
    string $departure,
    string $arrival,
    string $routeText,
    string $requestedDepartureRunway = ''
): array
{
    $tokens = preg_split('/\s+/', trim($routeText)) ?: [];
    $departureRunway = '';
    $arrivalRunway = '';
    $aliases = [];
    $runwayMismatch = null;
    $requestedDepartureRunway = strtoupper(trim($requestedDepartureRunway));
    $catalogues = [
        'SID' => routeAirportProcedures($departure, 'SID'),
        'STAR' => routeAirportProcedures($arrival, 'STAR'),
    ];
    foreach ($tokens as $index => $token) {
        $clean = strtoupper((string)preg_replace('/\/.*$/', '', trim($token)));
        if (!preg_match('/^[A-Z]{3,5}\d[A-Z]$/', $clean)) {
            continue;
        }
        foreach (['SID' => $departure, 'STAR' => $arrival] as $type => $airport) {
            $matches = array_values(array_filter($catalogues[$type], static function (array $procedure) use ($clean): bool {
                $identifier = strtoupper(trim((string)($procedure['identifier'] ?? '')));
                return $identifier !== '' && routeProcedureAliasMatches($clean, $identifier);
            }));
            if (count($matches) !== 1) {
                continue;
            }
            $candidate = strtoupper(trim((string)$matches[0]['identifier']));
            // The procedure listing already carries its runway transition. Use
            // it as an immediate fallback so a failed/stale detail request can
            // never silently accept a SID for the wrong runway.
            $listedTransition = preg_replace(
                '/^(?:RWY?|RUNWAY)\s*/',
                '',
                strtoupper(trim((string)($matches[0]['transition'] ?? '')))
            );
            if ($type === 'SID' && $requestedDepartureRunway !== ''
                && preg_match('/^\d{2}[LCR]?$/', $listedTransition)
                && $listedTransition !== $requestedDepartureRunway) {
                $runwayMismatch = [
                    'procedure' => $clean,
                    'airac_procedure' => $candidate,
                    'requested_runway' => $requestedDepartureRunway,
                    'available_runways' => [$listedTransition],
                ];
                continue 2;
            }
            $url = VFN_ROUTE_AIRAC_BASE_URL . '/procedures/'
                . rawurlencode($airport) . '/' . rawurlencode($candidate);
            $response = routeCachedFetch('procedure_' . $airport . '_' . $candidate, $url);
            if (!is_array($response) || ($response['status'] ?? '') !== 'success'
                || !is_array($response['data'] ?? null)) {
                continue;
            }
            $procedure = $response['data'];
            $runways = is_array($procedure['available_runways'] ?? null)
                ? $procedure['available_runways'] : [];
            $normalizedRunways = array_values(array_unique(array_filter(array_map(
                static fn($value): string => preg_replace(
                    '/^(?:RWY?|RUNWAY)\s*/',
                    '',
                    strtoupper(trim((string)$value))
                ),
                $runways
            ))));
            if ($type === 'SID' && $requestedDepartureRunway !== '' && $normalizedRunways
                && !in_array($requestedDepartureRunway, $normalizedRunways, true)) {
                $runwayMismatch = [
                    'procedure' => $clean,
                    'airac_procedure' => $candidate,
                    'requested_runway' => $requestedDepartureRunway,
                    'available_runways' => $normalizedRunways,
                ];
                continue 2;
            }
            $tokens[$index] = str_replace($clean, $candidate, strtoupper(trim($token)));
            $aliases[$candidate] = $clean;
            $runway = strtoupper(trim((string)($runways[0] ?? '')));
            if ($type === 'SID' && $departureRunway === '') {
                $departureRunway = $runway;
            } elseif ($type === 'STAR' && $arrivalRunway === '') {
                $arrivalRunway = $runway;
            }
            continue 2;
        }
    }
    return [
        'route' => implode(' ', $tokens),
        'departure_runway' => $departureRunway,
        'arrival_runway' => $arrivalRunway,
        'aliases' => $aliases,
        'runway_mismatch' => $runwayMismatch,
    ];
}

function routeParsedByAirac(string $departure, string $arrival, string $routeText, string $departureRunway = ''): ?array
{
    if ($routeText === '') {
        return null;
    }
    $prepared = routePrepareProcedures($departure, $arrival, $routeText, $departureRunway);
    if (is_array($prepared['runway_mismatch'] ?? null)) {
        return [
            'error' => 'sid_runway_mismatch',
            'details' => $prepared['runway_mismatch'],
        ];
    }
    if ($departureRunway !== '') {
        $prepared['departure_runway'] = $departureRunway;
    }
    $parameters = [
        'origin' => $departure,
        'destination' => $arrival,
        'route' => $prepared['route'],
    ];
    if ($prepared['departure_runway'] !== '') {
        $parameters['departure_runway'] = $prepared['departure_runway'];
    }
    if ($prepared['arrival_runway'] !== '') {
        $parameters['arrival_runway'] = $prepared['arrival_runway'];
    }
    $url = VFN_ROUTE_AIRAC_BASE_URL . '/routes/parse?' . http_build_query($parameters);
    $response = routeCachedFetch(
        'parsed_' . $departure . '_' . $arrival . '_' . $prepared['route']
            . '_' . $prepared['departure_runway'] . '_' . $prepared['arrival_runway'],
        $url
    );
    if (!is_array($response) || ($response['status'] ?? '') !== 'success') {
        return null;
    }
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];
    $points = [];
    foreach ($segments as $segment) {
        if (!is_array($segment)) {
            continue;
        }
        foreach (['from', 'to'] as $side) {
            $rawPoint = is_array($segment[$side] ?? null) ? $segment[$side] : [];
            $point = routeApiPoint($rawPoint);
            if (!$point) {
                continue;
            }
            $via = strtoupper(trim((string)($rawPoint['via'] ?? '')));
            if ($via !== '' && isset($prepared['aliases'][$via])) {
                $point['procedure'] = $prepared['aliases'][$via];
            }
            $last = $points[count($points) - 1] ?? null;
            if ($last
                && abs((float)$last['latitude'] - (float)$point['latitude']) < 0.000001
                && abs((float)$last['longitude'] - (float)$point['longitude']) < 0.000001) {
                continue;
            }
            $points[] = $point;
        }
    }
    if (count($points) < 2) {
        return null;
    }
    return [
        'points' => $points,
        'procedures' => array_values($prepared['aliases']),
        'distance_nm' => is_numeric($data['total_distance'] ?? null)
            ? (float)$data['total_distance']
            : null,
    ];
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        routeJson(['success' => false, 'message' => 'login_required'], 401);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $departure = strtoupper(trim((string)($_POST['departure'] ?? '')));
    $arrival = strtoupper(trim((string)($_POST['arrival'] ?? '')));
    $routeText = strtoupper(trim((string)($_POST['route'] ?? '')));
    $departureRunway = strtoupper(trim((string)($_POST['departure_runway'] ?? '')));
    $departureRunway = preg_replace('/^(?:RWY?|RUNWAY)\s*/', '', $departureRunway);
    if ($departureRunway !== '' && !preg_match('/^\d{2}[LCR]?$/', $departureRunway)) {
        routeJson(['success' => false, 'message' => 'invalid_departure_runway'], 400);
    }
    if (!preg_match('/^[A-Z0-9-]{2,15}$/', $departure)
        || !preg_match('/^[A-Z0-9-]{2,15}$/', $arrival)) {
        routeJson(['success' => false, 'message' => 'invalid_airports'], 400);
    }

    $departurePoint = routeAirport($pdo, $departure);
    $arrivalPoint = routeAirport($pdo, $arrival);
    if (!$departurePoint || !$arrivalPoint) {
        routeJson(['success' => false, 'message' => 'airport_not_found'], 404);
    }

    if ((string)($_POST['validate_procedures_only'] ?? '') === '1') {
        $prepared = routePrepareProcedures($departure, $arrival, $routeText, $departureRunway);
        if (is_array($prepared['runway_mismatch'] ?? null)) {
            routeJson([
                'success' => false,
                'message' => 'sid_runway_mismatch',
                'details' => $prepared['runway_mismatch'],
            ], 422);
        }
        routeJson(['success' => true, 'valid' => true]);
    }

    $routeStartPoint = routeRunwayThreshold($departure, $departureRunway) ?: $departurePoint;
    $points = [$routeStartPoint];
    $resolvedIdentifiers = [];
    $directOnly = $routeText === '' || preg_match('/^DCT(?:\s+DCT)*$/', $routeText);
    $parsedRoute = !$directOnly ? routeParsedByAirac($departure, $arrival, $routeText, $departureRunway) : null;

    if (is_array($parsedRoute) && ($parsedRoute['error'] ?? '') === 'sid_runway_mismatch') {
        routeJson([
            'success' => false,
            'message' => 'sid_runway_mismatch',
            'details' => $parsedRoute['details'] ?? [],
        ], 422);
    }

    if ($parsedRoute) {
        $points = $parsedRoute['points'];
        $points = array_values(array_filter($points, static function (array $point) use ($departure, $arrival): bool {
            return !routeSameIdentifier($point, $departure) && !routeSameIdentifier($point, $arrival);
        }));
        array_unshift($points, $routeStartPoint);
        $points[] = $arrivalPoint;
        $resolvedIdentifiers = array_values(array_filter(array_map(
            static fn(array $point): string => trim((string)($point['identifier'] ?? '')),
            array_slice($points, 1, -1)
        )));
    }

    if (!$directOnly && !$parsedRoute) {
        $rawTokens = preg_split('/\s+/', mb_substr($routeText, 0, 1500)) ?: [];
        $tokens = [];
        foreach ($rawTokens as $rawToken) {
            $token = preg_replace('/\/.*$/', '', trim($rawToken));
            if ($token === '' || $token === 'DCT' || $token === $departure || $token === $arrival) {
                continue;
            }
            if (!preg_match('/^[A-Z0-9]{2,7}$/', $token)
                || preg_match('/^(?:N|K|M)\d{3,4}$/', $token)
                || preg_match('/^(?:F|A|S)\d{3,4}$/', $token)) {
                continue;
            }
            if (!in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
            if (count($tokens) >= VFN_ROUTE_MAX_TOKENS) {
                break;
            }
        }

        $previous = $routeStartPoint;
        foreach ($tokens as $token) {
            $candidates = routeCandidates($token);
            if (!$candidates) {
                continue; // Usually an airway, SID/STAR, or unsupported token.
            }
            usort($candidates, static function (array $a, array $b) use ($previous, $arrivalPoint): int {
                $scoreA = routeDistanceNm($previous, $a) + routeDistanceNm($a, $arrivalPoint);
                $scoreB = routeDistanceNm($previous, $b) + routeDistanceNm($b, $arrivalPoint);
                return $scoreA <=> $scoreB;
            });
            $selected = $candidates[0];
            if (routeDistanceNm($previous, $selected) > 1800) {
                continue;
            }
            $points[] = $selected;
            $resolvedIdentifiers[] = $token;
            $previous = $selected;
        }
    }

    $lastPoint = $points[count($points) - 1] ?? null;
    if (!$lastPoint || (!routeSameIdentifier($lastPoint, $arrival) && routeDistanceNm($lastPoint, $arrivalPoint) > 0.1)) {
        $points[] = $arrivalPoint;
    }
    $distanceNm = 0.0;
    for ($index = 1, $count = count($points); $index < $count; $index++) {
        $distanceNm += routeDistanceNm($points[$index - 1], $points[$index]);
    }

    $directDistanceNm = routeDistanceNm($departurePoint, $arrivalPoint);
    // Short waypoint identifiers are reused throughout the world. On long
    // routes an ambiguous AIRAC search result can therefore produce a huge
    // zig-zag route even though every individual lookup succeeded. Reject a
    // route whose detour is implausible and use the reliable great-circle
    // route instead. A 35% margin still permits normal airway/SID/STAR detours.
    if (!$parsedRoute && $directDistanceNm > 0.0 && $distanceNm > $directDistanceNm * 1.35) {
        $points = [$routeStartPoint, $arrivalPoint];
        $resolvedIdentifiers = [];
        $distanceNm = $directDistanceNm;
    }

    routeJson([
        'success' => true,
        'mode' => $resolvedIdentifiers ? 'waypoints' : 'direct',
        'distance_nm' => round($distanceNm, 1),
        'resolved_waypoints' => $resolvedIdentifiers,
        'resolved_procedures' => $parsedRoute['procedures'] ?? [],
        'points' => $points,
    ]);
} catch (Throwable $error) {
    error_log('Flight route estimate failed: ' . $error->getMessage());
    routeJson(['success' => false, 'message' => 'route_estimate_failed'], 500);
}
