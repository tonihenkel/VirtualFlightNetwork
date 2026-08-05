<?php

/**
 * VFN ATC Rating System v2.2.
 *
 * TC1/TC2 may only occupy TWR. TWR starts the independent airport
 * sub-positions, PAT starts supervised APP/DEP and RAT starts supervised
 * radar. Any special rating bypasses position and supervision restrictions.
 */
function getAtcPositionPermissions(int $rating, int $specialRating = 0): array
{
    $specialist = $specialRating > 0;
    $definitions = [
        ['code' => 'INFO', 'name_key' => 'atc_position_info', 'allowed' => $rating >= 3],
        ['code' => 'DEL', 'name_key' => 'atc_position_delivery', 'allowed' => $rating >= 3],
        ['code' => 'GND', 'name_key' => 'atc_position_ground', 'allowed' => $rating >= 3],
        ['code' => 'TWR', 'name_key' => 'atc_position_tower', 'allowed' => $rating >= 1],
        ['code' => 'APP', 'name_key' => 'atc_position_approach', 'allowed' => $rating >= 4],
        ['code' => 'DEP', 'name_key' => 'atc_position_departure', 'allowed' => $rating >= 4],
        ['code' => 'CTR', 'name_key' => 'atc_position_center', 'allowed' => $rating >= 6],
    ];

    foreach ($definitions as &$position) {
        $position['allowed'] = $specialist || $position['allowed'];
        $position['supervision_required'] = !$specialist && (
            ($position['code'] === 'TWR' && $rating === 1)
            || (in_array($position['code'], ['APP', 'DEP'], true) && $rating === 4)
            || ($position['code'] === 'CTR' && $rating === 6)
        );
        $position['radar_scope'] = $position['code'] !== 'CTR'
            ? null
            : ($rating >= 8 || $specialist ? 'large' : 'small');
    }
    unset($position);

    return $definitions;
}

function canUseAtcClient(int $rating, int $specialRating = 0): bool
{
    return $specialRating > 0 || $rating >= 1;
}

function getAtcPositionPermission(
    int $rating,
    string $positionCode,
    int $specialRating = 0
): ?array {
    $positionCode = strtoupper(trim($positionCode));
    foreach (getAtcPositionPermissions($rating, $specialRating) as $position) {
        if ($position['code'] === $positionCode) return $position;
    }
    return null;
}

function canOccupyAtcPosition(
    int $rating,
    string $positionCode,
    int $specialRating = 0
): bool {
    $permission = getAtcPositionPermission($rating, $positionCode, $specialRating);
    return $permission !== null && (bool)$permission['allowed'];
}

function getDivisionAtcPrefixes(string $divisionCode): array
{
    $prefixes = [
        'DE' => ['ED', 'ET'],
        'GB' => ['EG'],
        'ES' => ['LE', 'GC'],
        'US' => ['K', 'PA', 'PH', 'TJ'],
    ];
    return $prefixes[strtoupper(trim($divisionCode))] ?? [];
}

function hasAtcPositionIntersection(array $stationPositions, array $permissions): bool
{
    foreach ($permissions as $permission) {
        if ($permission['allowed']
            && in_array($permission['code'], $stationPositions, true)) return true;
    }
    return false;
}
