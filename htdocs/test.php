<?php

declare(strict_types=1);

const METAR_URL = 'https://aviationweather.gov/data/cache/metars.cache.csv.gz';

$error = null;
$metars = [];
$requestedIds = [];

/**
 * CSV-Daten in ein Array umwandeln.
 */
function parseCsv(string $csv): array
{
    $handle = fopen('php://temp', 'r+');

    if ($handle === false) {
        throw new RuntimeException('Temporärer Speicher konnte nicht geöffnet werden.');
    }

    fwrite($handle, $csv);
    rewind($handle);

    $headers = null;
    $rows = [];

    while (($columns = fgetcsv($handle)) !== false) {
        if ($columns === [] || $columns === [null]) {
            continue;
        }

        if ($headers === null) {
            $columns[0] = preg_replace('/^\xEF\xBB\xBF/', '', $columns[0]);
            $headers = $columns;
            continue;
        }

        if (count($columns) !== count($headers)) {
            continue;
        }

        $row = array_combine($headers, $columns);

        if ($row !== false) {
            $rows[] = $row;
        }
    }

    fclose($handle);

    return $rows;
}

/**
 * ICAO-Code eines Datensatzes auslesen.
 */
function getStationId(array $row): string
{
    foreach (['station_id', 'icaoId', 'stationId', 'station'] as $field) {
        if (!empty($row[$field])) {
            return strtoupper(trim((string) $row[$field]));
        }
    }

    return '';
}

/**
 * Datei von Aviation Weather herunterladen.
 */
function downloadMetars(): string
{
    $curl = curl_init(METAR_URL);

    if ($curl === false) {
        throw new RuntimeException('cURL konnte nicht gestartet werden.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => 'identity',
        CURLOPT_USERAGENT => 'VFN-Network-METAR-Downloader/1.0',
    ]);

    $compressedData = curl_exec($curl);

    if ($compressedData === false) {
        $message = curl_error($curl);
        curl_close($curl);

        throw new RuntimeException('Download fehlgeschlagen: ' . $message);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($statusCode !== 200) {
        throw new RuntimeException(
            'Aviation Weather antwortete mit HTTP-Status ' . $statusCode
        );
    }

    $csv = gzdecode($compressedData);

    if ($csv === false) {
        throw new RuntimeException('Die GZIP-Datei konnte nicht entpackt werden.');
    }

    return $csv;
}

/**
 * ICAO-Eingabe aufbereiten.
 */
function parseRequestedIds(string $input): array
{
    $ids = preg_split(
        '/[\s,;]+/',
        strtoupper(trim($input))
    ) ?: [];

    $ids = array_filter(
        array_map('trim', $ids),
        static fn(string $id): bool =>
            preg_match('/^[A-Z0-9]{3,4}$/', $id) === 1
    );

    return array_values(array_unique($ids));
}

try {
    $requestedIds = parseRequestedIds(
        (string) ($_GET['ids'] ?? '')
    );

    $csv = downloadMetars();
    $metars = parseCsv($csv);

    if ($requestedIds !== []) {
        $lookup = array_fill_keys($requestedIds, true);

        $metars = array_values(
            array_filter(
                $metars,
                static function (array $metar) use ($lookup): bool {
                    $stationId = getStationId($metar);

                    return isset($lookup[$stationId]);
                }
            )
        );
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$result = [
    'success' => $error === null,
    'downloaded_at_utc' => gmdate('c'),
    'count' => count($metars),
    'filter' => $requestedIds,
    'metars' => $metars,
];

if ($error !== null) {
    $result['error'] = $error;
}

$jsonOutput = json_encode(
    $result,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>METAR Array</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, Helvetica, sans-serif;
            background: #111821;
            color: #edf3f8;
        }

        .container {
            width: min(1200px, 100%);
            margin: 0 auto;
        }

        h1 {
            margin-top: 0;
        }

        form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        input {
            flex: 1;
            padding: 12px;
            font: inherit;
            color: white;
            background: #1b2633;
            border: 1px solid #40546a;
            border-radius: 6px;
        }

        button {
            padding: 12px 18px;
            font: inherit;
            color: white;
            background: #246bac;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }

        .info {
            margin-bottom: 15px;
            padding: 12px;
            background: #1b2633;
            border-left: 4px solid #438bd0;
            border-radius: 5px;
        }

        .error {
            border-left-color: #d64b4b;
        }

        pre {
            max-height: 75vh;
            margin: 0;
            padding: 18px;
            overflow: auto;
            color: #dce9f5;
            background: #090e14;
            border: 1px solid #34485d;
            border-radius: 8px;
            font-family: Consolas, monospace;
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-word;
        }
    </style>
</head>

<body>
<div class="container">
    <h1>METAR Array</h1>

    <form method="get">
        <input
            type="text"
            name="ids"
            value="<?= htmlspecialchars(
                implode(', ', $requestedIds),
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            placeholder="ICAO-Codes, zum Beispiel EKCH, EDDF, EDDM"
        >

        <button type="submit">
            METARs laden
        </button>
    </form>

    <div class="info <?= $error !== null ? 'error' : '' ?>">
        <?php if ($error !== null): ?>
            Fehler:
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        <?php else: ?>
            <?= count($metars) ?> METAR-Datensätze geladen.

            <?php if ($requestedIds === []): ?>
                Es werden alle verfügbaren Stationen angezeigt.
            <?php else: ?>
                Filter:
                <?= htmlspecialchars(
                    implode(', ', $requestedIds),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <pre><?= htmlspecialchars(
        $jsonOutput ?: '{}',
        ENT_QUOTES,
        'UTF-8'
    ) ?></pre>
</div>

<script>
    // Seite alle fünf Minuten neu laden.
    const updateInterval = 5 * 60 * 1000;

    window.setTimeout(() => {
        window.location.reload();
    }, updateInterval);
</script>
</body>
</html>