<?php

function vfnMaintenanceModeIsActive($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (int)$value === 1;
    }

    return in_array(
        strtolower(trim((string)$value)),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

function vfnCanAccessNetworkDuringMaintenance(
    $maintenanceMode,
    $opPermission
): bool {
    return !vfnMaintenanceModeIsActive($maintenanceMode)
        || (int)$opPermission >= 5;
}

