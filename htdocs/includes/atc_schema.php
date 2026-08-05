<?php

function ensureAtcSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            session_token CHAR(64) NOT NULL,
            callsign VARCHAR(40) NOT NULL,
            station_code VARCHAR(12) NOT NULL,
            position_code VARCHAR(8) NOT NULL,
            is_spectator TINYINT(1) NOT NULL DEFAULT 0,
            can_control TINYINT(1) NOT NULL DEFAULT 1,
            can_transmit_voice TINYINT(1) NOT NULL DEFAULT 1,
            scope_positions VARCHAR(100) NOT NULL DEFAULT '',
            map_profile VARCHAR(32) NOT NULL DEFAULT 'airport_info',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            disconnected_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_atc_session_token (session_token),
            KEY idx_atc_active_station (is_active, station_code, position_code, is_spectator),
            KEY idx_atc_user_active (user_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $mapProfileColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'atc_sessions'
           AND COLUMN_NAME = 'map_profile'"
    )->fetchColumn();
    if ((int)$mapProfileColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_sessions
             ADD COLUMN map_profile VARCHAR(32) NOT NULL DEFAULT 'airport_info'
             AFTER scope_positions"
        );
    }
}
