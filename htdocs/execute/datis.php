<?php

header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';

$token =
    trim($_POST['token'] ?? '');

$airport =
    strtoupper(trim($_POST['airport'] ?? ''));

if ($token === '' || !preg_match('/^[A-Z0-9]{4}$/', $airport)) {
    echo json_encode([
        'success' => false,
        'message' => 'Token und Airport erforderlich.'
    ]);
    exit;
}

function datisEmptyValue(?string $value): string
{
    $value =
        trim((string)$value);

    return $value === '' ? '-' : $value;
}

function datisJsonResponse(array $data): void
{
    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function datisGetAirportCoordinates(PDO $pdo, string $airport): ?array
{
    $stmt =
        $pdo->prepare(
            "SELECT
                latitude_deg,
                longitude_deg
             FROM airports
             WHERE ident = :airport
                OR icao_code = :airport
                OR gps_code = :airport
             LIMIT 1"
        );

    $stmt->execute([
        'airport' => $airport
    ]);

    $row =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'latitude' => (float)$row['latitude_deg'],
        'longitude' => (float)$row['longitude_deg']
    ];
}

function datisGetCachePath(string $name): string
{
    return sys_get_temp_dir() .
        DIRECTORY_SEPARATOR .
        'vfn_datis_' .
        preg_replace('/[^A-Za-z0-9_-]/', '', $name) .
        '.json';
}

function datisGetTextCachePath(string $name): string
{
    return sys_get_temp_dir() .
        DIRECTORY_SEPARATOR .
        'vfn_datis_' .
        preg_replace('/[^A-Za-z0-9_-]/', '', $name) .
        '.txt';
}

function datisFetchUrlText(string $url, bool $gzip = false): ?string
{
    $context =
        stream_context_create([
            'http' => [
                'timeout' => 8,
                'header' => "User-Agent: VFN-DATIS/1.0 (toni.flightradar.plugin)\r\n"
            ]
        ]);

    $body =
        @file_get_contents($url, false, $context);

    if ($body === false || trim((string)$body) === '') {
        return null;
    }

    if ($gzip) {
        $decoded =
            @gzdecode($body);

        if ($decoded === false || trim((string)$decoded) === '') {
            return null;
        }

        return $decoded;
    }

    return (string)$body;
}

function datisFetchAviationWeatherMetarXml(
    string $cacheUrl,
    int $cacheSeconds
): ?string {
    $cachePath =
        datisGetTextCachePath('metars_bulk_xml');

    if (
        $cacheSeconds > 0 &&
        is_file($cachePath) &&
        (time() - filemtime($cachePath)) < $cacheSeconds
    ) {
        $cachedText =
            @file_get_contents($cachePath);

        if (is_string($cachedText) && trim($cachedText) !== '') {
            return $cachedText;
        }
    }

    $xml =
        datisFetchUrlText($cacheUrl, true);

    if ($xml === null) {
        return null;
    }

    @file_put_contents($cachePath, $xml);

    return $xml;
}

function datisFindMetarInAviationWeatherXml(
    string $xml,
    string $airport
): ?array {
    $previous =
        libxml_use_internal_errors(true);

    $document =
        simplexml_load_string($xml);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($document === false) {
        return null;
    }

    $matches =
        $document->xpath('//METAR[station_id="' . $airport . '"]');

    if (!is_array($matches) || !isset($matches[0])) {
        return null;
    }

    $metar =
        $matches[0];

    $result = [];

    foreach ($metar->children() as $key => $value) {
        if (!isset($result[$key])) {
            $result[$key] =
                trim((string)$value);
        }
    }

    return $result;
}

function datisFetchNoaaStationMetar(
    string $airport,
    string $baseUrl,
    int $cacheSeconds
): ?array {
    $cachePath =
        datisGetCachePath('station_' . $airport);

    if (
        $cacheSeconds > 0 &&
        is_file($cachePath) &&
        (time() - filemtime($cachePath)) < $cacheSeconds
    ) {
        $cachedBody =
            @file_get_contents($cachePath);

        $cachedData =
            json_decode((string)$cachedBody, true);

        if (is_array($cachedData)) {
            return $cachedData;
        }
    }

    $url =
        rtrim($baseUrl, '/') . '/' . rawurlencode($airport) . '.TXT';

    $body =
        datisFetchUrlText($url);

    if ($body === null) {
        return null;
    }

    $lines =
        array_values(
            array_filter(
                array_map('trim', preg_split('/\R/', $body) ?: []),
                static fn($line) => $line !== ''
            )
        );

    if (count($lines) < 2) {
        return null;
    }

    $data = [
        'station_id' => $airport,
        'observation_time' => $lines[0],
        'raw_text' => $lines[1]
    ];

    @file_put_contents(
        $cachePath,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    return $data;
}

function datisFormatMetarTime(array $metar): string
{
    $time =
        trim((string)($metar['observation_time'] ?? ''));

    if ($time === '') {
        return '-';
    }

    $timestamp =
        strtotime($time);

    if ($timestamp === false) {
        return $time;
    }

    return gmdate('H:i', $timestamp) . 'Z';
}

function datisFormatMetarWind(array $metar): string
{
    $direction =
        trim((string)($metar['wind_dir_degrees'] ?? ''));

    $speed =
        trim((string)($metar['wind_speed_kt'] ?? ''));

    $gust =
        trim((string)($metar['wind_gust_kt'] ?? ''));

    if ($speed === '') {
        return '-';
    }

    $directionText =
        $direction === '' || strtoupper($direction) === 'VRB'
            ? 'VRB'
            : sprintf('%03d', (int)$direction);

    $gustText =
        $gust === '' ? '' : ' G' . (string)(int)$gust;

    return $directionText . ' / ' . (string)(int)$speed . $gustText . ' kt';
}

function datisFormatMetarVisibility(array $metar): string
{
    $visibility =
        trim((string)($metar['visibility_statute_mi'] ?? ''));

    if ($visibility === '') {
        return '-';
    }

    return $visibility . ' SM';
}

function datisFormatMetarWeather(array $metar): string
{
    $weather =
        trim((string)($metar['wx_string'] ?? ''));

    $sky =
        trim((string)($metar['sky_cover'] ?? ''));

    if ($weather !== '' && $sky !== '') {
        return $weather . ' / ' . $sky;
    }

    if ($weather !== '') {
        return $weather;
    }

    return $sky === '' ? '-' : $sky;
}

function datisFormatMetarTempDew(array $metar): string
{
    $temp =
        trim((string)($metar['temp_c'] ?? ''));

    $dew =
        trim((string)($metar['dewpoint_c'] ?? ''));

    if ($temp === '') {
        return '-';
    }

    return (string)round((float)$temp) . ' C / ' .
        ($dew === '' ? '-' : (string)round((float)$dew) . ' C');
}

function datisFormatMetarQnh(array $metar): string
{
    $pressureMb =
        trim((string)($metar['sea_level_pressure_mb'] ?? ''));

    if ($pressureMb !== '') {
        return (string)round((float)$pressureMb) . ' hPa';
    }

    $altim =
        trim((string)($metar['altim_in_hg'] ?? ''));

    if ($altim === '') {
        return '-';
    }

    return (string)round((float)$altim * 33.8639) . ' hPa';
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $sessionStmt =
        $pdo->prepare(
            "SELECT user_id
             FROM user_sessions
             WHERE token = :token
               AND is_active = 1
             LIMIT 1"
        );

    $sessionStmt->execute([
        'token' => $token
    ]);

    if (!$sessionStmt->fetch(PDO::FETCH_ASSOC)) {
        datisJsonResponse([
            'success' => false,
            'message' => 'Ungueltige oder abgelaufene Session.'
        ]);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS controller_atis (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            airport_icao VARCHAR(4) NOT NULL,
            info_letter VARCHAR(8) DEFAULT NULL,
            atis_text TEXT DEFAULT NULL,
            wind VARCHAR(64) DEFAULT NULL,
            visibility VARCHAR(64) DEFAULT NULL,
            weather VARCHAR(128) DEFAULT NULL,
            temp_dew VARCHAR(64) DEFAULT NULL,
            qnh VARCHAR(64) DEFAULT NULL,
            runway VARCHAR(64) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_controller_atis_airport_active (airport_icao, is_active),
            KEY idx_controller_atis_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $atisStmt =
        $pdo->prepare(
            "SELECT
                ca.info_letter,
                ca.atis_text,
                ca.wind,
                ca.visibility,
                ca.weather,
                ca.temp_dew,
                ca.qnh,
                ca.runway,
                ca.updated_at
             FROM controller_atis ca
             INNER JOIN user_sessions s
                ON s.user_id = ca.user_id
               AND s.is_active = 1
             WHERE ca.airport_icao = :airport
               AND ca.is_active = 1
             ORDER BY ca.updated_at DESC
             LIMIT 1"
        );

    $atisStmt->execute([
        'airport' => $airport
    ]);

    $controllerAtis =
        $atisStmt->fetch(PDO::FETCH_ASSOC);

    if ($controllerAtis) {
        datisJsonResponse([
            'success' => true,
            'source' => 'atc',
            'airport' => $airport,
            'info' => datisEmptyValue($controllerAtis['info_letter'] ?? ''),
            'time' => datisEmptyValue(substr((string)$controllerAtis['updated_at'], 11, 5) . 'Z'),
            'wind' => datisEmptyValue($controllerAtis['wind'] ?? ''),
            'visibility' => datisEmptyValue($controllerAtis['visibility'] ?? ''),
            'weather' => datisEmptyValue($controllerAtis['weather'] ?? ''),
            'temp_dew' => datisEmptyValue($controllerAtis['temp_dew'] ?? ''),
            'qnh' => datisEmptyValue($controllerAtis['qnh'] ?? ''),
            'runway' => datisEmptyValue($controllerAtis['runway'] ?? ''),
            'message' => datisEmptyValue($controllerAtis['atis_text'] ?? '')
        ]);
    }

    $metarCacheSeconds =
        (int)($metarCacheSeconds ?? 1800);

    $metarXml =
        datisFetchAviationWeatherMetarXml(
            trim((string)($aviationWeatherMetarCacheUrl ?? '')),
            $metarCacheSeconds
        );

    $metar =
        $metarXml === null
            ? null
            : datisFindMetarInAviationWeatherXml($metarXml, $airport);

    if ($metar === null) {
        $metar =
            datisFetchNoaaStationMetar(
                $airport,
                trim((string)($noaaMetarStationBaseUrl ?? '')),
                $metarCacheSeconds
            );
    }

    if ($metar === null) {
        datisJsonResponse([
            'success' => false,
            'source' => 'none',
            'airport' => $airport,
            'message' => 'Keine METAR-Daten verfuegbar.'
        ]);
    }

    datisJsonResponse([
        'success' => true,
        'source' => 'metar',
        'airport' => $airport,
        'info' => 'AUTO',
        'time' => datisFormatMetarTime($metar),
        'wind' => datisFormatMetarWind($metar),
        'visibility' => datisFormatMetarVisibility($metar),
        'weather' => datisFormatMetarWeather($metar),
        'temp_dew' => datisFormatMetarTempDew($metar),
        'qnh' => datisFormatMetarQnh($metar),
        'runway' => '-',
        'message' => datisEmptyValue($metar['raw_text'] ?? '')
    ]);
} catch (Exception $e) {
    datisJsonResponse([
        'success' => false,
        'source' => 'none',
        'airport' => $airport,
        'message' => 'Serverfehler.'
    ]);
}
