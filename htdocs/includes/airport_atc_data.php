<?php

function loadAirportFrequencyTypes(string $csvPath): array
{
    static $cache = [];
    if (isset($cache[$csvPath])) return $cache[$csvPath];

    $indexPath = dirname($csvPath) . '/airport-frequency-types.json';
    if (is_file($indexPath) && is_readable($indexPath)) {
        $decoded = json_decode((string)file_get_contents($indexPath), true);
        if (is_array($decoded)) return $cache[$csvPath] = $decoded;
    }

    $types = [];
    $handle = @fopen($csvPath, 'rb');
    if ($handle === false) return $cache[$csvPath] = [];
    $header = fgetcsv($handle);
    $columns = is_array($header) ? array_flip($header) : [];
    $identColumn = $columns['airport_ident'] ?? 2;
    $typeColumn = $columns['type'] ?? 3;
    while (($row = fgetcsv($handle)) !== false) {
        $ident = strtoupper(trim((string)($row[$identColumn] ?? '')));
        $type = strtoupper(trim((string)($row[$typeColumn] ?? '')));
        if ($ident === '' || $type === '') continue;
        $types[$ident][$type] = true;
    }
    fclose($handle);
    foreach ($types as $ident => $typeSet) $types[$ident] = array_keys($typeSet);
    return $cache[$csvPath] = $types;
}

function getAirportAtcClassification(string $ident, string $csvPath): array
{
    static $overrides = null;
    if ($overrides === null) {
        $overrides = require __DIR__ . '/airport_atc_overrides.php';
    }
    $ident = strtoupper(trim($ident));
    $allTypes = loadAirportFrequencyTypes($csvPath);
    $frequencyTypes = $allTypes[$ident] ?? [];
    if (isset($overrides[$ident])) {
        return $overrides[$ident] + [
            'frequency_types' => $frequencyTypes,
            'source' => 'manual_override',
        ];
    }
    $hasTower = in_array('TWR', $frequencyTypes, true);

    // A local tower frequency is the decisive indication of a controlled
    // aerodrome. Area radar/ACC frequencies alone must not turn an AFIS field
    // such as EDBN into a controlled airport.
    if (!$hasTower) {
        return [
            'controlled' => false,
            'positions' => ['INFO'],
            'frequency_types' => $frequencyTypes,
            'source' => empty($frequencyTypes) ? 'fallback' : 'frequency_data',
        ];
    }

    $positions = ['INFO', 'TWR'];
    if (array_intersect($frequencyTypes, ['GND', 'RMP', 'APRON'])) $positions[] = 'GND';
    if (array_intersect($frequencyTypes, ['DEL', 'CLD', 'CLR'])) $positions[] = 'DEL';
    if (array_intersect($frequencyTypes, ['APP', 'ARR', 'DEP', 'A/D', 'RDR', 'DIR'])) {
        $positions[] = 'APP';
        $positions[] = 'DEP';
    }

    return [
        'controlled' => true,
        'positions' => array_values(array_unique($positions)),
        'frequency_types' => $frequencyTypes,
        'source' => 'frequency_data',
    ];
}

function getSpectatorAirportPositions(array $airport, array $classification): array
{
    if (!(bool)($classification['controlled'] ?? false)) {
        return ['INFO'];
    }

    $positions = (array)($classification['positions'] ?? []);
    $airportType = strtolower(trim((string)($airport['type'] ?? '')));
    if ($airportType === 'large_airport') {
        $positions = array_merge(
            $positions,
            ['INFO', 'DEL', 'GND', 'TWR', 'APP', 'DEP']
        );
    } elseif ($airportType === 'medium_airport') {
        $positions = array_merge($positions, ['INFO', 'GND', 'TWR']);
    }

    $order = ['INFO', 'DEL', 'GND', 'TWR', 'APP', 'DEP'];
    return array_values(array_filter(
        $order,
        static function (string $code) use ($positions): bool {
            return in_array($code, $positions, true);
        }
    ));
}
