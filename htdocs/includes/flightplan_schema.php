<?php

function ensurePilotFlightplanCommunicationColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $marker = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'vfn-flightplan-schema-20260827.ready';
    if (is_file($marker)) {
        $checked = true;
        return;
    }
    $checked = true;
    $column = $pdo->query("SHOW COLUMNS FROM pilot_flightplans LIKE 'communication_mode'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec("ALTER TABLE pilot_flightplans ADD COLUMN communication_mode ENUM('VOICE','RECEIVE_ONLY','TEXT_ONLY') NOT NULL DEFAULT 'VOICE' AFTER flight_type");
    }
    @file_put_contents($marker, gmdate('c'));
}
