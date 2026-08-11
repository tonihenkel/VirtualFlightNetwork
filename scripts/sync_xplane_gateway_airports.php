<?php
declare(strict_types=1);

/**
 * Resumable downloader for recommended X-Plane Scenery Gateway airport data.
 *
 * Only the apt.dat payload required by VFN is retained. 3-D scenery assets are
 * discarded. Existing cache files are never downloaded twice unless --force
 * is supplied.
 *
 * Usage:
 *   php -d memory_limit=768M sync_xplane_gateway_airports.php
 *       [--manifest=path/airports.json] [--cache=path/gateway-apt]
 *       [--only=EDDM,EDDF] [--limit=100] [--delay-ms=200]
 *       [--shard-index=0 --shard-count=4] [--force]
 */

$root = dirname(__DIR__);
$options = getopt('', ['manifest::', 'cache::', 'only::', 'limit::', 'delay-ms::', 'shard-index::', 'shard-count::', 'force']);
$manifest = (string)($options['manifest'] ?? ($root . '/data-source-test/airports.json'));
$cache = rtrim((string)($options['cache'] ?? ($root . '/data-sources/xplane-gateway/apt')), "\\/");
$limit = max(0, (int)($options['limit'] ?? 0));
$delayMs = max(100, (int)($options['delay-ms'] ?? 250));
$shardCount = max(1, (int)($options['shard-count'] ?? 1));
$shardIndex = max(0, (int)($options['shard-index'] ?? 0));
if ($shardIndex >= $shardCount) { throw new InvalidArgumentException('shard-index must be lower than shard-count.'); }
$force = array_key_exists('force', $options);
$only = [];
foreach (explode(',', strtoupper((string)($options['only'] ?? ''))) as $code) {
    $code = preg_replace('/[^A-Z0-9_-]/', '', trim($code));
    if ($code !== '') { $only[$code] = true; }
}

if (!is_file($manifest)) {
    throw new RuntimeException('Gateway airport manifest not found: ' . $manifest);
}
if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
    throw new RuntimeException('Cannot create Gateway cache: ' . $cache);
}

$decoded = json_decode((string)file_get_contents($manifest), true);
if (!is_array($decoded) || !isset($decoded['airports']) || !is_array($decoded['airports'])) {
    throw new RuntimeException('Invalid Gateway airport manifest.');
}

function gatewayRequest(string $url): array
{
    $caBundle = null;
    foreach ([
        'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
        'C:/php/extras/ssl/cacert.pem',
    ] as $candidate) {
        if (is_file($candidate)) { $caBundle = $candidate; break; }
    }
    if ($caBundle === null) {
        throw new RuntimeException('No trusted CA bundle is available for Gateway TLS verification.');
    }
    $lastError = '';
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'VirtualFlightNetwork-AirportLayoutSync/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CAINFO => $caBundle,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $lastError = (string)curl_error($curl);
        curl_close($curl);
        if (is_string($body) && $status >= 200 && $status < 300) {
            $json = json_decode($body, true);
            if (is_array($json)) { return $json; }
            $lastError = 'invalid JSON response';
        } else {
            $lastError = 'HTTP ' . $status . ($lastError !== '' ? ': ' . $lastError : '');
        }
        usleep(500000 * $attempt);
    }
    throw new RuntimeException($lastError);
}

function extractAptPayload(string $zipBytes, string $airportCode): string
{
    $temporary = tempnam(sys_get_temp_dir(), 'vfn-gateway-');
    if ($temporary === false) { throw new RuntimeException('Cannot create temporary ZIP.'); }
    file_put_contents($temporary, $zipBytes);
    $zip = new ZipArchive();
    if ($zip->open($temporary) !== true) {
        @unlink($temporary);
        throw new RuntimeException('Cannot open Gateway master ZIP.');
    }
    $preferred = strtoupper($airportCode) . '.DAT';
    $fallback = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $base = strtoupper(basename(str_replace('\\', '/', $name)));
        if ($base === $preferred) { $fallback = $i; break; }
        if ($fallback === null && substr($base, -4) === '.DAT') { $fallback = $i; }
    }
    if ($fallback === null) {
        $zip->close(); @unlink($temporary);
        throw new RuntimeException('No apt.dat payload in Gateway package.');
    }
    $payload = $zip->getFromIndex($fallback);
    $zip->close();
    @unlink($temporary);
    if (!is_string($payload) || trim($payload) === '') {
        throw new RuntimeException('Empty apt.dat payload.');
    }
    return $payload;
}

$processed = 0;
$downloaded = 0;
$cached = 0;
$failed = 0;
$failures = [];
$started = gmdate('c');
$eligibleIndex = 0;

foreach ($decoded['airports'] as $airport) {
    $code = strtoupper((string)($airport['AirportCode'] ?? ''));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    $sceneryId = (int)($airport['RecommendedSceneryId'] ?? 0);
    if ($code === '' || $sceneryId <= 0 || ($only && !isset($only[$code]))) { continue; }
    $assignedShard = $eligibleIndex % $shardCount;
    $eligibleIndex++;
    if ($assignedShard !== $shardIndex) { continue; }
    if ($limit > 0 && $processed >= $limit) { break; }
    $processed++;
    $destination = $cache . DIRECTORY_SEPARATOR . $code . '.dat';
    if (!$force && is_file($destination) && filesize($destination) > 32) {
        $cached++;
        continue;
    }
    try {
        $response = gatewayRequest('https://gateway.x-plane.com/apiv1/scenery/' . $sceneryId);
        $blob = (string)($response['scenery']['masterZipBlob'] ?? '');
        $zipBytes = base64_decode($blob, true);
        if (!is_string($zipBytes) || $zipBytes === '') {
            throw new RuntimeException('Missing masterZipBlob.');
        }
        $payload = extractAptPayload($zipBytes, $code);
        $temporary = $destination . '.tmp-' . getmypid();
        file_put_contents($temporary, $payload, LOCK_EX);
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot publish cached apt.dat.');
        }
        $downloaded++;
    } catch (Throwable $error) {
        $failed++;
        $failures[] = ['airport' => $code, 'scenery_id' => $sceneryId, 'error' => $error->getMessage()];
    }
    if ($processed % 25 === 0 || $only) {
        fwrite(STDOUT, sprintf("Gateway %d: downloaded=%d cached=%d failed=%d\n", $processed, $downloaded, $cached, $failed));
    }
    usleep($delayMs * 1000);
}

$state = [
    'schema' => 1,
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'processed' => $processed,
    'downloaded' => $downloaded,
    'cached' => $cached,
    'failed' => $failed,
    'shard_index' => $shardIndex,
    'shard_count' => $shardCount,
    'failures' => $failures,
];
file_put_contents(dirname($cache) . DIRECTORY_SEPARATOR . 'sync-state-' . $shardIndex . '-of-' . $shardCount . '.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDOUT, json_encode($state, JSON_UNESCAPED_SLASHES) . PHP_EOL);
