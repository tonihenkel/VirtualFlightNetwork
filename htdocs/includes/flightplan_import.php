<?php

function vfnFlightplanImportDefaults(): array
{
    return [
        'id' => 0, 'callsign' => '', 'flight_rules' => 'I', 'flight_type' => 'G', 'communication_mode' => 'VOICE',
        'departure_time' => '', 'departure_airport' => '', 'arrival_airport' => '',
        'alternate1_airport' => '', 'alternate2_airport' => '', 'route_text' => '',
        'cruising_level' => '', 'cruising_speed' => '', 'remarks' => '', 'status' => 'draft'
    ];
}

function vfnXmlFirst(SimpleXMLElement $xml, array $paths): string
{
    foreach ($paths as $path) {
        $nodes = $xml->xpath($path);
        if ($nodes && trim((string)$nodes[0]) !== '') return trim((string)$nodes[0]);
    }
    return '';
}

function vfnParseFlightplanUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('flightplan_import_upload_error');
    }
    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('flightplan_import_too_large');
    }
    $raw = file_get_contents((string)$file['tmp_name']);
    if ($raw === false || trim($raw) === '') throw new RuntimeException('flightplan_import_empty');

    $plan = vfnFlightplanImportDefaults();
    $name = strtolower((string)($file['name'] ?? ''));
    // str_starts_with() requires PHP 8. The production server still supports
    // PHP 7.4, so keep this import path compatible with both versions.
    $looksXml = strncmp(ltrim($raw), '<', 1) === 0 || preg_match('/\.(xml|fpl|pln)$/', $name);
    if ($looksXml) {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if ($xml instanceof SimpleXMLElement) {
            $plan['callsign'] = vfnXmlFirst($xml, ['//atc/callsign','//general/callsign','//callsign']);
            if ($plan['callsign'] === '') {
                $plan['callsign'] = vfnXmlFirst($xml, ['//general/icao_airline']) . vfnXmlFirst($xml, ['//general/flight_number']);
            }
            $plan['departure_airport'] = vfnXmlFirst($xml, ['//origin/icao_code','//departure-code','//departure/icao_code','//departure','//DepartureID']);
            $plan['arrival_airport'] = vfnXmlFirst($xml, ['//destination/icao_code','//destination-code','//arrival/icao_code','//destination','//DestinationID']);
            $plan['alternate1_airport'] = vfnXmlFirst($xml, ['//alternate/icao_code','//alternate-code']);
            $plan['route_text'] = vfnXmlFirst($xml, ['//general/route','//route-string','//route_text']);
            $plan['cruising_level'] = vfnXmlFirst($xml, ['//general/initial_altitude','//altitude','//cruising_level','//CruisingAlt']);
            $plan['cruising_speed'] = vfnXmlFirst($xml, ['//general/cruise_tas','//cruising_speed']);
            $plan['departure_time'] = vfnXmlFirst($xml, ['//times/sched_out','//departure_time']);
            if ($plan['route_text'] === '') {
                $points = [];
                foreach ($xml->xpath('//route/route-point/waypoint-identifier | //route-point/waypoint-identifier | //waypoint/identifier | //ATCWaypoint/ICAO/ICAOIdent') ?: [] as $node) {
                    $point = strtoupper(trim((string)$node));
                    if ($point !== '' && !in_array($point, $points, true)) $points[] = $point;
                }
                $plan['route_text'] = implode(' ', $points);
            }
            $plan['departure_airport'] = strtoupper(trim($plan['departure_airport']));
            $plan['arrival_airport'] = strtoupper(trim($plan['arrival_airport']));
            $plan['alternate1_airport'] = strtoupper(trim($plan['alternate1_airport']));
            if ($plan['route_text'] !== '') {
                $departure = $plan['departure_airport'];
                $arrival = $plan['arrival_airport'];
                $routePoints = preg_split('/\s+/', strtoupper(trim($plan['route_text']))) ?: [];
                $routePoints = array_values(array_filter(
                    $routePoints,
                    static function (string $point) use ($departure, $arrival): bool {
                        return $point !== '' && $point !== $departure && $point !== $arrival;
                    }
                ));
                $plan['route_text'] = implode(' ', $routePoints);
            }
            return $plan;
        }
    }

    $lines = preg_split('/\R/', $raw) ?: [];
    $points = [];
    foreach ($lines as $line) {
        $line = trim($line); if ($line === '') continue;
        if (preg_match('/^(ADEP|DEP|DEPARTURE)\s*[:= ]\s*([A-Z0-9-]{3,10})/i', $line, $m)) $plan['departure_airport'] = $m[2];
        elseif (preg_match('/^(ADES|DEST|DESTINATION|ARRIVAL)\s*[:= ]\s*([A-Z0-9-]{3,10})/i', $line, $m)) $plan['arrival_airport'] = $m[2];
        elseif (preg_match('/^(ALTN|ALTERNATE)\s*[:= ]\s*([A-Z0-9-]{3,10})/i', $line, $m)) $plan['alternate1_airport'] = $m[2];
        elseif (preg_match('/^ROUTE\s*[:=]\s*(.+)$/i', $line, $m)) $plan['route_text'] = trim($m[1]);
        elseif (preg_match('/^(FL|LEVEL|ALTITUDE)\s*[:= ]\s*([A-Z0-9]+)$/i', $line, $m)) $plan['cruising_level'] = $m[2];
        elseif (preg_match('/^(CALLSIGN)\s*[:= ]\s*([A-Z0-9_-]+)/i', $line, $m)) $plan['callsign'] = $m[2];
        elseif (preg_match('/^(\d+)\s+([A-Z0-9_-]{2,12})\s+[-+]?\d/', $line, $m)) $points[] = strtoupper($m[2]);
    }
    if ($plan['route_text'] === '' && $points) {
        $dep = strtoupper($plan['departure_airport']); $arr = strtoupper($plan['arrival_airport']);
        $points = array_values(array_filter($points, static fn($p) => $p !== $dep && $p !== $arr));
        $plan['route_text'] = implode(' ', array_values(array_unique($points)));
    }
    if ($plan['departure_airport'] === '' && $plan['arrival_airport'] === '' && $plan['route_text'] === '') {
        throw new RuntimeException('flightplan_import_unsupported');
    }
    return $plan;
}
