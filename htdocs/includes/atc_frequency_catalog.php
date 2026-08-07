<?php
declare(strict_types=1);

/**
 * Persistent ATC frequency catalogue.
 *
 * Radar facilities usually have several sector frequencies.  Therefore the
 * callsign is the key; station-only matches are deliberately returned as a
 * list and not collapsed into one supposedly universal FIR frequency.
 */
function ensureAtcFrequencyCatalog(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_position_frequencies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            callsign VARCHAR(48) NOT NULL,
            station_code VARCHAR(24) NOT NULL DEFAULT '',
            position_code VARCHAR(12) NOT NULL DEFAULT '',
            frequency VARCHAR(12) NOT NULL,
            source_name VARCHAR(48) NOT NULL,
            source_url VARCHAR(255) NOT NULL DEFAULT '',
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_atc_frequency (callsign, frequency),
            KEY idx_atc_station (station_code, position_code, is_active),
            KEY idx_atc_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    seedAtcFrequencyOverrides($pdo);
}

/**
 * Stable VFN assignments for facilities which are too rarely staffed to be
 * learned reliably from the live network feed.  The live importer continues
 * to build the worldwide catalogue around these reviewed entries.
 */
function seedAtcFrequencyOverrides(PDO $pdo): void
{
    $overrides = [
        // VFN assignment requested for Ezeiza Control (Argentina).
        ['SAEF_CTR', 'SAEF', 'CTR', '135.500'],
        // VFN assignment for Jeddah Control (Saudi Arabia).
        ['OEJD_CTR', 'OEJD', 'CTR', '134.300'],
        // VATSIM Germany EDMM EBG West elemental sectors.
        ['EDMM_ALB_CTR', 'EDMM_ALB', 'CTR', '129.100'],
        ['EDMM_EGG_CTR', 'EDMM_EGG', 'CTR', '129.555'],
        ['EDMM_FUE_CTR', 'EDMM_FUE', 'CTR', '133.550'],
        ['EDMM_NDG_CTR', 'EDMM_NDG', 'CTR', '125.140'],
        ['EDMM_RDG_CTR', 'EDMM_RDG', 'CTR', '132.555'],
        ['EDMM_STA_CTR', 'EDMM_STA', 'CTR', '132.455'],
        ['EDMM_TEG_CTR', 'EDMM_TEG', 'CTR', '133.680'],
        ['EDMM_TRU_CTR', 'EDMM_TRU', 'CTR', '132.635'],
        ['EDMM_WLD_CTR', 'EDMM_WLD', 'CTR', '136.230'],
        ['EDMM_ZUG_CTR', 'EDMM_ZUG', 'CTR', '134.150'],
    ];
    $statement = $pdo->prepare(
        "INSERT INTO atc_position_frequencies
         (callsign, station_code, position_code, frequency, source_name,
          source_url, first_seen_at, last_seen_at, is_active)
         VALUES (:callsign, :station, :position, :frequency,
                 'VFN reviewed override', '', NOW(), NOW(), 1)
         ON DUPLICATE KEY UPDATE station_code=VALUES(station_code),
             position_code=VALUES(position_code), source_name=VALUES(source_name),
             is_active=1"
    );
    foreach ($overrides as [$callsign, $station, $position, $frequency]) {
        $statement->execute(compact('callsign', 'station', 'position', 'frequency'));
    }
}

function normalizeAtcStationCode(string $value): string
{
    return trim(strtoupper(str_replace('-', '_', $value)), '_');
}

function normalizeAtcVoiceFrequency(string $value): string
{
    $value = str_replace(',', '.', trim($value));
    if (!is_numeric($value)) return '';
    $number = (float)$value;
    if ($number < 118.000 || $number > 136.995) return '';
    return number_format($number, 3, '.', '');
}

/** Return a reviewed VATGlasses sector entry without loading the 5.8 MB data file. */
function findCompiledAtcSector(string $station): ?array
{
    static $index = null;
    if ($index === null) {
        $path = dirname(__DIR__) . '/data/atc/sector-boundaries.index.json';
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        $index = is_array($decoded) ? $decoded : [];
    }
    $key = normalizeAtcStationCode($station);
    $entry = $index['stations'][$key] ?? null;
    return is_array($entry) ? $entry + ['station_code' => $key] : null;
}

function getCompiledAtcSectors(): array
{
    static $stations = null;
    if ($stations === null) {
        $path = dirname(__DIR__) . '/data/atc/sector-boundaries.index.json';
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        $stations = is_array($decoded['stations'] ?? null) ? $decoded['stations'] : [];
    }
    return $stations;
}

function splitAtcCallsign(string $callsign): array
{
    $callsign = normalizeAtcStationCode($callsign);
    if (!preg_match('/^(.+)_([A-Z]{2,4})$/', $callsign, $match)) {
        return [$callsign, ''];
    }
    return [$match[1], $match[2]];
}

function refreshAtcFrequencyCatalog(PDO $pdo): array
{
    ensureAtcFrequencyCatalog($pdo);
    $url = 'https://data.vatsim.net/v3/vatsim-data.json';
    $context = stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'VFN-ATC-Frequency-Sync/1.0'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) throw new RuntimeException('atc_frequency_source_unavailable');
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['controllers']) || !is_array($payload['controllers'])) {
        throw new RuntimeException('atc_frequency_source_invalid');
    }
    $upsert = $pdo->prepare(
        "INSERT INTO atc_position_frequencies
         (callsign, station_code, position_code, frequency, source_name, source_url,
          first_seen_at, last_seen_at, is_active)
         VALUES (:callsign, :station, :position, :frequency, 'VATSIM live data', :url,
                 NOW(), NOW(), 1)
         ON DUPLICATE KEY UPDATE station_code=VALUES(station_code),
             position_code=VALUES(position_code), source_name=VALUES(source_name),
             source_url=VALUES(source_url), last_seen_at=NOW(), is_active=1"
    );
    $count = 0;
    foreach ($payload['controllers'] as $controller) {
        $callsign = normalizeAtcStationCode((string)($controller['callsign'] ?? ''));
        $frequency = normalizeAtcVoiceFrequency((string)($controller['frequency'] ?? ''));
        if ($callsign === '' || $frequency === ''
            || !preg_match('/_(DEL|GND|TWR|APP|DEP|CTR|FSS)$/', $callsign)) continue;
        [$station, $position] = splitAtcCallsign($callsign);
        $upsert->execute([
            'callsign' => $callsign, 'station' => $station,
            'position' => $position, 'frequency' => $frequency, 'url' => $url,
        ]);
        ++$count;
    }
    return ['imported' => $count, 'source' => $url];
}

function findAtcFrequencies(PDO $pdo, string $station, string $position): array
{
    ensureAtcFrequencyCatalog($pdo);
    $station = normalizeAtcStationCode($station);
    $position = strtoupper(trim($position));
    $exactCallsign = $station . '_' . $position;
    $base = explode('_', $station, 2)[0];
    $stmt = $pdo->prepare(
        "SELECT callsign, frequency, source_name, last_seen_at,
                CASE
                    WHEN callsign = :exact THEN 0
                    WHEN station_code = :station THEN 1
                    WHEN station_code LIKE :sector_prefix THEN 2
                    ELSE 3
                END AS match_order
         FROM atc_position_frequencies
         WHERE is_active = 1 AND position_code = :position
           AND (callsign = :exact2 OR station_code = :station2
                OR station_code LIKE :sector_prefix2)
         ORDER BY match_order, last_seen_at DESC, callsign, frequency"
    );
    $stmt->execute([
        'exact' => $exactCallsign, 'station' => $station,
        'sector_prefix' => $base . '\_%', 'position' => $position,
        'exact2' => $exactCallsign, 'station2' => $station,
        'sector_prefix2' => $base . '\_%',
    ]);
    $seen = [];
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $frequency = normalizeAtcVoiceFrequency((string)$row['frequency']);
        if ($frequency === '' || isset($seen[$frequency])) continue;
        $seen[$frequency] = true;
        $result[] = [
            'callsign' => (string)$row['callsign'],
            'frequency' => $frequency,
            'source' => (string)$row['source_name'],
        ];
    }
    $compiled = findCompiledAtcSector($station);
    $compiledFrequency = normalizeAtcVoiceFrequency((string)($compiled['frequency'] ?? ''));
    if ($position === 'CTR' && $compiledFrequency !== '' && !isset($seen[$compiledFrequency])) {
        array_unshift($result, [
            'callsign' => $station . '_CTR',
            'frequency' => $compiledFrequency,
            'source' => 'VATGlasses sector data',
        ]);
    }
    return $result;
}
