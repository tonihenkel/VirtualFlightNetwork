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
    static $ensuredConnections = [];
    $connectionId = spl_object_id($pdo);
    if (isset($ensuredConnections[$connectionId])) return;
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
    $ensuredConnections[$connectionId] = true;
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
        // Comodoro Rivadavia FIR primary frequency. The remaining SAVF
        // frequencies are supplied by its CMN/CMO/CMS child sectors.
        ['SAVF_CTR', 'SAVF', 'CTR', '125.500'],
        // Antarctic and Southern Ocean parent facilities. Their source data
        // stores the frequency on a child sector or in the VATSpy facility
        // name, so the FIR-level controller otherwise appears without one.
        ['FAJO_CTR', 'FAJO', 'CTR', '120.850'],
        ['NZZO_CTR', 'NZZO', 'CTR', '129.000'],
        ['YIND_CTR', 'YIND', 'CTR', '129.250'],
        ['YINS_CTR', 'YINS', 'CTR', '123.200'],
        // Current national AIP assignments for FIRs whose VATGlasses records
        // contain geometry but no voice frequency.
        ['SLLF_CTR', 'SLLF', 'CTR', '128.200'],
        ['SPIM_CTR', 'SPIM', 'CTR', '128.100'],
        ['SPIM_CTR_2', 'SPIM', 'CTR', '128.500'],
        ['SPIM_CTR_3', 'SPIM', 'CTR', '128.800'],
        ['OERK_CTR', 'OERK', 'CTR', '120.000'],
        ['OERK_CTR_2', 'OERK', 'CTR', '128.500'],
        ['OERK_CTR_3', 'OERK', 'CTR', '124.100'],
        ['OERK_CTR_4', 'OERK', 'CTR', '126.000'],
        ['VABF_CTR', 'VABF', 'CTR', '132.700'],
        ['VABF_CTR_2', 'VABF', 'CTR', '125.350'],
        ['VABF_CTR_3', 'VABF', 'CTR', '133.850'],
        ['VABF_CTR_4', 'VABF', 'CTR', '133.300'],
        ['VABF_CTR_5', 'VABF', 'CTR', '120.500'],
        ['VABF_CTR_6', 'VABF', 'CTR', '133.425'],
        ['VABF_CTR_7', 'VABF', 'CTR', '133.925'],
        ['VABF_CTR_8', 'VABF', 'CTR', '127.150'],
        ['VABF_CTR_9', 'VABF', 'CTR', '135.750'],
        ['VIDF_CTR', 'VIDF', 'CTR', '119.500'],
        ['VIDF_CTR_2', 'VIDF', 'CTR', '120.900'],
        ['VIDF_CTR_3', 'VIDF', 'CTR', '124.550'],
        ['VIDF_CTR_4', 'VIDF', 'CTR', '125.700'],
        ['VIDF_CTR_5', 'VIDF', 'CTR', '125.950'],
        ['VIDF_CTR_6', 'VIDF', 'CTR', '132.150'],
        ['VIDF_CTR_7', 'VIDF', 'CTR', '132.850'],
        ['VIDF_CTR_8', 'VIDF', 'CTR', '132.975'],
        ['VIDF_CTR_9', 'VIDF', 'CTR', '133.900'],
        ['VIDF_CTR_10', 'VIDF', 'CTR', '134.075'],
        ['VIDF_CTR_11', 'VIDF', 'CTR', '134.500'],
        ['VECF_CTR', 'VECF', 'CTR', '132.450'],
        ['VECF_CTR_2', 'VECF', 'CTR', '120.700'],
        ['VECF_CTR_3', 'VECF', 'CTR', '120.100'],
        ['VECF_CTR_4', 'VECF', 'CTR', '126.100'],
        ['VECF_CTR_5', 'VECF', 'CTR', '125.900'],
        ['VOMF_CTR', 'VOMF', 'CTR', '118.900'],
        ['VOMF_CTR_2', 'VOMF', 'CTR', '125.700'],
        ['VOMF_CTR_3', 'VOMF', 'CTR', '126.150'],
        // VATRUS published Russian ACC and discrete sector assignments.
        ['UEEE_CTR', 'UEEE', 'CTR', '125.600'],
        ['UEEE_E_CTR', 'UEEE_E', 'CTR', '126.900'],
        ['UEEE_NE_CTR', 'UEEE_NE', 'CTR', '129.500'],
        ['UHHH_CTR', 'UHHH', 'CTR', '124.500'],
        ['UHHH_1_CTR', 'UHHH_1', 'CTR', '126.600'],
        ['UHHH_3_CTR', 'UHHH_3', 'CTR', '133.700'],
        ['USSV_CTR', 'USSV', 'CTR', '122.200'],
        ['USTV_CTR', 'USTV', 'CTR', '132.600'],
        ['UUWV_CTR', 'UUWV', 'CTR', '127.500'],
        ['UUWV_SE_CTR', 'UUWV_SE', 'CTR', '125.200'],
        ['UWWW_CTR', 'UWWW', 'CTR', '132.900'],
        ['UWWW_E_CTR', 'UWWW_E', 'CTR', '126.900'],
        ['UWWW_N_CTR', 'UWWW_N', 'CTR', '133.600'],
        ['UWWW_SE_CTR', 'UWWW_SE', 'CTR', '132.500'],
        ['UWWW_SG_CTR', 'UWWW_SG', 'CTR', '126.100'],
        ['UWWW_SW_CTR', 'UWWW_SW', 'CTR', '134.300'],
        ['UWWW_W_CTR', 'UWWW_W', 'CTR', '133.300'],
        // Gander/Shanwick OCA VHF aliases used by VATSIM to simulate HF.
        ['EGGX_CTR', 'EGGX', 'CTR', '131.800'],
        ['EGGX_A_CTR', 'EGGX_A', 'CTR', '131.450'],
        ['EGGX_B_CTR', 'EGGX_B', 'CTR', '131.550'],
        ['EGGX_C_CTR', 'EGGX_C', 'CTR', '131.650'],
        ['EGGX_D_CTR', 'EGGX_D', 'CTR', '131.750'],
        ['EGGX_F_CTR', 'EGGX_F', 'CTR', '131.850'],
        ['EGGX_S_CTR', 'EGGX_S', 'CTR', '131.800'],
        ['CZQO_CTR', 'CZQO', 'CTR', '131.700'],
        ['CZQO_A_CTR', 'CZQO_A', 'CTR', '131.575'],
        ['CZQO_B_CTR', 'CZQO_B', 'CTR', '131.675'],
        ['CZQO_C_CTR', 'CZQO_C', 'CTR', '131.775'],
        ['CZQO_D_CTR', 'CZQO_D', 'CTR', '131.875'],
        ['CZQO_F_CTR', 'CZQO_F', 'CTR', '131.975'],
        ['CZQO_G_CTR', 'CZQO_G', 'CTR', '131.700'],
        ['NAT_FSS_CTR', 'NAT', 'CTR', '131.900'],
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

/**
 * VATSpy FIR identifiers and VATGlasses sector station keys occasionally use
 * different abbreviations for the same airspace. Keep only reviewed aliases;
 * a geographic or fuzzy match could attach a valid frequency to the wrong
 * controller position.
 */
function getCompiledAtcSectorAliases(string $station): array
{
    $aliases = [
        // Arabian Peninsula subdivisions.
        'OBBB_C' => ['OBBB_BBCH', 'OBBB_BBCL'],
        'OBBB_E' => ['OBBB_BBE'],
        'OBBB_N' => ['OBBB_BBN'],
        'OBBB_S' => ['OBBB_BBE', 'OBBB_BB2'],
        'OEJD_C' => ['OEJD_JCC'],
        'OEJD_E' => ['OEJD_JCE'],
        'OEJD_N' => ['OEJD_JCN'],
        'OEJD_NE' => ['OEJD_JCNE'],
        'OEJD_S' => ['OEJD_JCS'],
        'OEJD_SE' => ['OEJD_JCSE'],
        'OEJD_W' => ['OEJD_JCW', 'OEJD_JCW1'],
        'OERD_E' => ['OERD_RCE'],
        'OERD_N' => ['OERD_RCN'],
        'OERD_NE' => ['OERD_RCNE'],
        'OTDF_N' => ['OTDF_DCN'],
        'OTDF_S' => ['OTDF_DCS'],

        // Chinese FIR parents and their published ACC sectors.
        'ZBPE' => ['ZBAA', 'ZBHH'],
        'ZGZU' => ['ZGGG', 'ZGHA', 'ZGNN'],
        'ZHWH' => ['ZHCC', 'ZHHH'],
        'ZJSA' => ['ZJSY'],
        'ZLHW' => ['ZLLL', 'ZLXY'],
        'ZPKM' => ['ZPPP', 'ZUGY', 'ZULS', 'ZUUU'],
        'ZSHA' => ['ZSAM', 'ZSCN', 'ZSJN', 'ZSOF', 'ZSQD', 'ZSSS'],
        'ZWUQ' => ['ZWWW'],
        'ZYSH' => ['ZYHB', 'ZYTL', 'ZYTX'],
        'ZBAA_E' => ['ZBAA_BJE'],
        'ZBAA_N' => ['ZBAA_BJN'],
        'ZBAA_S' => ['ZBAA_BJS'],
        'ZBAA_SW' => ['ZBAA_BJSW'],
        'ZBAA_W' => ['ZBAA_BJW'],
        'ZJSY_L' => ['ZJSY_SYL'],
        'ZJSY_O' => ['ZJSY_SYO'],
        'ZSSS_C' => ['ZSSS_SHC'],
        'ZSSS_N' => ['ZSSS_SHN'],
        'ZSSS_S' => ['ZSSS_SHS'],
        'ZSSS_W' => ['ZSSS_SHW'],
        'ZWWW_N' => ['ZWWW_WUN'],
        'ZWWW_S' => ['ZWWW_WUS'],
    ];
    return $aliases[normalizeAtcStationCode($station)] ?? [];
}

function getAtcFrequencyParentFallback(string $station): string
{
    $station = normalizeAtcStationCode($station);
    if (preg_match('/^VA/', $station)) return 'VABF';
    if (preg_match('/^VE/', $station)) return 'VECF';
    if (preg_match('/^VI/', $station)) return 'VIDF';
    if (preg_match('/^VO/', $station)) return 'VOMF';
    return '';
}

/** Frequencies explicitly embedded in VATSpy FIR/UIR labels (notably Australia). */
function findVatSpyLabelFrequencies(string $station): array
{
    static $frequencies = null;
    if ($frequencies === null) {
        $frequencies = [];
        $path = dirname(__DIR__) . '/data/atc/VATSpy.dat';
        $section = '';
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (preg_match('/^\[([A-Za-z]+)\]$/', $line, $match)) {
                    $section = strtoupper($match[1]);
                    continue;
                }
                if (!in_array($section, ['FIRS', 'UIRS'], true)) continue;
                $columns = explode('|', $line);
                $code = normalizeAtcStationCode((string)($columns[0] ?? ''));
                $label = (string)($columns[1] ?? '');
                if ($code === '' || !preg_match_all('/\b1[123]\d(?:\.\d{1,3})?\b/', $label, $matches)) continue;
                foreach ($matches[0] as $candidate) {
                    $frequency = normalizeAtcVoiceFrequency($candidate);
                    if ($frequency !== '') $frequencies[$code][$frequency] = true;
                }
            }
            fclose($handle);
        }
    }
    return array_keys($frequencies[normalizeAtcStationCode($station)] ?? []);
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
    $sectorPrefix = $station . '\_%';
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
        'sector_prefix' => $sectorPrefix, 'position' => $position,
        'exact2' => $exactCallsign, 'station2' => $station,
        'sector_prefix2' => $sectorPrefix,
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
        $seen[$compiledFrequency] = true;
    }
    // Parent FIR identifiers such as SAVF do not necessarily have their own
    // VATGlasses station record. Collect the frequencies of every compiled
    // child sector assigned to that FIR group instead of returning an empty
    // frequency list for the parent controller position.
    if ($position === 'CTR') {
        $compiledPrefixes = array_merge(
            [$station],
            getCompiledAtcSectorAliases($station)
        );
        foreach (getCompiledAtcSectors() as $compiledStation => $sector) {
            $compiledStation = normalizeAtcStationCode((string)$compiledStation);
            // The station prefix is the reliable parent relation. Several
            // datasets use generic group labels such as FIR or O instead of
            // repeating SBAO, SBRE or FAJO in the group field.
            $matchesStation = false;
            foreach ($compiledPrefixes as $prefix) {
                $prefix = normalizeAtcStationCode((string)$prefix);
                if (
                    $compiledStation === $prefix
                    || strpos($compiledStation, $prefix . '_') === 0
                ) {
                    $matchesStation = true;
                    break;
                }
            }
            if (!$matchesStation) continue;
            $frequency = normalizeAtcVoiceFrequency((string)($sector['frequency'] ?? ''));
            if ($frequency === '' || isset($seen[$frequency])) continue;
            $seen[$frequency] = true;
            $result[] = [
                'callsign' => $compiledStation . '_CTR',
                'frequency' => $frequency,
                'source' => 'VATGlasses sector data',
            ];
        }
    }
    if ($position === 'CTR') {
        foreach (findVatSpyLabelFrequencies($station) as $frequency) {
            if (isset($seen[$frequency])) continue;
            $seen[$frequency] = true;
            $result[] = [
                'callsign' => $station . '_CTR',
                'frequency' => $frequency,
                'source' => 'VATSpy facility data',
            ];
        }
    }
    // Some compiled records use a display key (for example LIM_SPIM) while
    // position_key contains the actual FIR identifier used by the frequency
    // catalogue. Follow that explicit source relation before broader parent
    // fallbacks are attempted.
    if ($position === 'CTR' && $result === [] && is_array($compiled)) {
        $positionStation = normalizeAtcStationCode(
            (string)($compiled['position_key'] ?? '')
        );
        if ($positionStation !== '' && $positionStation !== $station) {
            $positionFrequencies = findAtcFrequencies(
                $pdo,
                $positionStation,
                $position
            );
            if ($positionFrequencies) return $positionFrequencies;
        }
    }
    // VATSpy may abbreviate a directional/numbered sector (ZMUB_M), while
    // VATGlasses prefixes the local sector designator (ZMUB_ULM). Match the
    // explicit suffix inside the same parent group before inheriting every
    // frequency of the parent FIR.
    if ($position === 'CTR' && $result === [] && strpos($station, '_') !== false) {
        [$sectorParent, $sectorSuffix] = explode('_', $station, 2);
        $suffixMatches = [];
        foreach (getCompiledAtcSectors() as $candidateStation => $candidate) {
            $candidateStation = normalizeAtcStationCode((string)$candidateStation);
            $candidateGroup = normalizeAtcStationCode((string)($candidate['group'] ?? ''));
            $candidatePosition = normalizeAtcStationCode((string)($candidate['position_key'] ?? ''));
            if (
                strpos($candidateStation, $sectorParent . '_') !== 0
                || $candidateGroup !== $sectorParent
                || $sectorSuffix === ''
                || substr($candidatePosition, -strlen($sectorSuffix)) !== $sectorSuffix
            ) continue;
            $frequency = normalizeAtcVoiceFrequency((string)($candidate['frequency'] ?? ''));
            if ($frequency === '' || isset($suffixMatches[$frequency])) continue;
            $suffixMatches[$frequency] = [
                'callsign' => $candidateStation . '_CTR',
                'frequency' => $frequency,
                'source' => 'VATGlasses sector data',
            ];
        }
        if ($suffixMatches) return array_values($suffixMatches);
    }
    if ($position === 'CTR' && $result === []) {
        $fallbackStation = getAtcFrequencyParentFallback($station);
        if ($fallbackStation !== '' && $fallbackStation !== $station) {
            return findAtcFrequencies($pdo, $fallbackStation, $position);
        }
    }
    // Combined and directional VATSpy identifiers normally inherit the
    // available frequencies of their parent facility when no discrete value
    // exists (for example SBAZ_CE, KZJX_A or VTBB_NE).
    if ($position === 'CTR' && $result === [] && strpos($station, '_') !== false) {
        $parentStation = explode('_', $station, 2)[0];
        if ($parentStation !== '' && $parentStation !== $station) {
            return findAtcFrequencies($pdo, $parentStation, $position);
        }
    }
    return $result;
}
