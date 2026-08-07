<?php

// Manually reviewed deviations from the crowdsourced frequency dump.
// Keep the reason visible so an override can be reviewed when source data
// changes. RMZ and AFIS do not constitute aerodrome control.
$overrides = [
    'EDDP' => [
        'controlled' => true,
        'positions' => ['INFO', 'DEL', 'GND', 'TWR', 'APP', 'DEP'],
        'operation' => 'controlled',
        'frequencies' => ['DEL' => '121.680'],
        'note' => 'Leipzig Delivery is operational on 121.680; missing from the imported frequency dump.',
    ],
    'EDQB' => [
        'controlled' => false,
        'positions' => ['INFO'],
        'operation' => 'uncontrolled',
        'note' => 'Uncontrolled aerodrome; local information service only.',
    ],
    'EDQM' => [
        'controlled' => false,
        'positions' => ['INFO'],
        'operation' => 'afis_rmz',
        'note' => 'AFIS aerodrome within an RMZ; no aerodrome control service.',
    ],
];

// Official BAF map "AFIS-Dienste - Unkontrollierte Flugplaetze mit
// IFR-Verkehr und/oder RMZ (zivil)", publication file version 4.
// These aerodromes remain INFO-only even if a third-party frequency dump
// happens to contain a legacy or differently classified TWR frequency.
$bafAfisRmzAerodromes = [
    'EDAB', 'EDAC', 'EDAY', 'EDAZ', 'EDBC', 'EDBM', 'EDBN',
    'EDFE', 'EDFQ', 'EDGS', 'EDHK', 'EDME', 'EDMS', 'EDPR',
    'EDQA', 'EDQC', 'EDQD', 'EDQG', 'EDRY', 'EDRZ', 'EDTD',
    'EDTM', 'EDTY', 'EDWE', 'EDWI',
];

foreach ($bafAfisRmzAerodromes as $bafIdent) {
    $overrides[$bafIdent] = [
        'controlled' => false,
        'positions' => ['INFO'],
        'operation' => 'afis_rmz',
        'note' => 'Official BAF AFIS/RMZ map: uncontrolled aerodrome with IFR traffic and/or RMZ.',
    ];
}

return $overrides;
