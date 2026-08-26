<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/atc_frequency_catalog.php';
require_once __DIR__ . '/../includes/atc_schema.php';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    ensureAtcSchema($pdo);
    ensureAtcFrequencyCatalog($pdo);
    $autoAtis = [];
    try {
        $atisRows = $pdo->query(
            "SELECT b.airport_icao, b.frequency, b.info_letter, b.active_runway, 1 AS is_active,
                    b.updated_at, ap.name AS airport_name,
                    ap.latitude_deg AS latitude, ap.longitude_deg AS longitude
             FROM auto_atis_broadcasts b
             LEFT JOIN airports ap ON UPPER(ap.ident)=UPPER(b.airport_icao)
             WHERE b.is_active=1"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($atisRows as $atisRow) {
            $autoAtis[strtoupper((string)$atisRow['airport_icao'])] = $atisRow;
        }
    } catch (Throwable $ignored) {
        // The voice service creates this table when automatic ATIS starts.
    }
    try {
        $coverageRows = $pdo->query(
            "SELECT s.airport_icao, s.frequency AS atis_frequency,
                    s.airport_name, s.latitude, s.longitude,
                    a.callsign, a.station_code, a.position_code, a.is_gca,
                    a.frequency, a.radar_boundary_code,
                    COALESCE(NULLIF(TRIM(u.real_name),''),u.username) AS controller_name
             FROM atc_session_atis_airports s
             INNER JOIN atc_sessions a ON a.id=s.session_id
             INNER JOIN users u ON u.id=a.user_id
             WHERE a.is_active=1 AND a.is_ready=1 AND (a.is_spectator=0 OR a.is_trainer=1)
               AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
             ORDER BY a.position_code='CTR' DESC,a.callsign"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($coverageRows as $coverage) {
            $airportCode = strtoupper((string)$coverage['airport_icao']);
            if (!isset($autoAtis[$airportCode])) {
                $autoAtis[$airportCode] = [
                    'airport_icao'=>$airportCode,
                    'frequency'=>(string)$coverage['atis_frequency'],
                    'info_letter'=>'', 'active_runway'=>'', 'updated_at'=>'',
                    'airport_name'=>(string)$coverage['airport_name'],
                    'latitude'=>$coverage['latitude'], 'longitude'=>$coverage['longitude'],
                    'is_active'=>0,
                ];
            } else $autoAtis[$airportCode]['is_active'] = 1;
            $autoAtis[$airportCode]['controllers'] ??= [];
            $autoAtis[$airportCode]['controllers'][] = [
                'callsign'=>(string)$coverage['callsign'],
                'is_gca'=>(int)($coverage['is_gca'] ?? 0),
                'station_code'=>(string)$coverage['station_code'],
                'position_code'=>(string)$coverage['position_code'],
                'frequency'=>(string)$coverage['frequency'],
                'radar_boundary_code'=>(string)$coverage['radar_boundary_code'],
                'controller_name'=>(string)$coverage['controller_name'],
            ];
        }
    } catch (Throwable $ignored) {}
    $stmt = $pdo->query(
        "SELECT a.callsign, a.station_code, a.position_code, a.frequency, a.is_gca, a.is_trainer,
                a.radar_boundary_code,
                COALESCE(NULLIF(TRIM(u.real_name), ''), u.username) AS controller_name,
                ap.latitude_deg AS latitude, ap.longitude_deg AS longitude,
                ap.name AS airport_name
         FROM atc_sessions a
         INNER JOIN users u ON u.id = a.user_id
         LEFT JOIN airports ap ON ap.ident = a.station_code
         WHERE a.is_active = 1 AND a.is_ready=1 AND (a.is_spectator = 0 OR a.is_trainer = 1)
           AND a.is_invisible = 0
           AND a.last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
         ORDER BY a.station_code, a.position_code, a.callsign"
    );
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $atisFrequencies = [];
    $activeStations = [];
    foreach ($positions as $activePosition) {
        if (strtoupper((string)($activePosition['position_code'] ?? '')) === 'CTR') continue;
        $station = strtoupper(trim((string)($activePosition['station_code'] ?? '')));
        if ($station !== '') $activeStations[$station] = true;
    }
    foreach (array_keys($activeStations) as $station) {
        $knownAtis = findAtcFrequencies($pdo, $station, 'ATIS');
        if (!$knownAtis) continue;
        $atisFrequencies[$station] = (string)$knownAtis[0]['frequency'];
        unset($activeStations[$station]);
    }
    $frequencyPath = dirname(__DIR__) . '/data/airports/airport-frequencies.csv';
    $handle = $activeStations ? @fopen($frequencyPath, 'rb') : false;
    if ($handle !== false) {
        $header = fgetcsv($handle);
        $columns = is_array($header) ? array_flip($header) : [];
        while (($row = fgetcsv($handle)) !== false) {
            $station = strtoupper(trim((string)($row[$columns['airport_ident'] ?? 2] ?? '')));
            $type = strtoupper(trim((string)($row[$columns['type'] ?? 3] ?? '')));
            if (!isset($activeStations[$station]) || !in_array($type, ['ATIS', 'D-ATIS'], true)) continue;
            $frequency = normalizeAtcVoiceFrequency(
                (string)($row[$columns['frequency_mhz'] ?? 5] ?? '')
            );
            if ($frequency !== '' && !isset($atisFrequencies[$station])) {
                $atisFrequencies[$station] = $frequency;
                $storeAtis = $pdo->prepare(
                    "INSERT INTO atc_position_frequencies
                     (callsign, station_code, position_code, frequency, source_name,
                      source_url, first_seen_at, last_seen_at, is_active)
                     VALUES (:callsign, :station, 'ATIS', :frequency,
                             'Global airport frequency data', '', NOW(), NOW(), 1)
                     ON DUPLICATE KEY UPDATE station_code=VALUES(station_code),
                         position_code='ATIS', source_name=VALUES(source_name), is_active=1"
                );
                $storeAtis->execute([
                    'callsign' => $station . '_ATIS',
                    'station' => $station,
                    'frequency' => $frequency,
                ]);
                unset($activeStations[$station]);
                if (!$activeStations) break;
            }
        }
        fclose($handle);
    }
    foreach ($positions as &$position) {
        $position['is_gca'] = (int)($position['is_gca'] ?? 0) === 1;
        $position['is_trainer'] = (int)($position['is_trainer'] ?? 0) === 1;
        if (normalizeAtcVoiceFrequency((string)($position['frequency'] ?? '')) === '') {
            $frequencies = findAtcFrequencies(
                $pdo,
                (string)($position['station_code'] ?? ''),
                (string)($position['position_code'] ?? '')
            );
            if (!empty($frequencies)) {
                $position['frequency'] = (string)$frequencies[0]['frequency'];
            }
        }
        $station = strtoupper(trim((string)($position['station_code'] ?? '')));
        $position['atis_frequency'] = (string)($atisFrequencies[$station] ?? '');
        $position['atis_info_letter'] = (string)($autoAtis[$station]['info_letter'] ?? '');
        $position['atis_active_runway'] = (string)($autoAtis[$station]['active_runway'] ?? '');
        $position['atis_updated_at'] = (string)($autoAtis[$station]['updated_at'] ?? '');
        $position['atis_active'] = isset($autoAtis[$station]) ? 1 : 0;
    }
    unset($position);
    echo json_encode([
        'success' => true,
        'positions' => $positions,
        'atis_airports' => array_values($autoAtis),
        'count' => count(array_filter($positions, static fn(array $position): bool => empty($position['is_trainer']))),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
