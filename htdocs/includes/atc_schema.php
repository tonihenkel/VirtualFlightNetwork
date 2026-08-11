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
            radar_boundary_code VARCHAR(24) NOT NULL DEFAULT '',
            frequency VARCHAR(12) NOT NULL DEFAULT '',
            atis_scope_ready TINYINT(1) NOT NULL DEFAULT 0,
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_session_atis_airports (
            session_id BIGINT UNSIGNED NOT NULL,
            airport_icao VARCHAR(12) NOT NULL,
            frequency VARCHAR(12) NOT NULL,
            airport_name VARCHAR(180) NOT NULL DEFAULT '',
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            PRIMARY KEY (session_id, airport_icao),
            KEY idx_atc_atis_airport (airport_icao),
            CONSTRAINT fk_atc_atis_session FOREIGN KEY (session_id)
                REFERENCES atc_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_assumed_aircraft (
            pilot_session_token VARCHAR(128) NOT NULL,
            pilot_callsign VARCHAR(40) NOT NULL,
            atc_session_id BIGINT UNSIGNED NOT NULL,
            atc_user_id INT NOT NULL,
            atc_callsign VARCHAR(40) NOT NULL,
            assumed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (pilot_session_token),
            KEY idx_atc_assumed_session (atc_session_id),
            KEY idx_atc_assumed_callsign (pilot_callsign),
            CONSTRAINT fk_atc_assumed_session FOREIGN KEY (atc_session_id)
                REFERENCES atc_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_aircraft_clearances (
            pilot_session_token VARCHAR(128) NOT NULL,
            pilot_callsign VARCHAR(40) NOT NULL,
            clearance_type VARCHAR(12) NOT NULL DEFAULT 'DIRECT',
            clearance_value VARCHAR(80) NOT NULL DEFAULT '',
            cleared_altitude VARCHAR(20) NOT NULL DEFAULT '',
            issued_by_user_id INT NOT NULL,
            issued_by_callsign VARCHAR(40) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (pilot_session_token),
            KEY idx_atc_clearance_callsign (pilot_callsign)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    foreach ([
        'cleared_departure_runway' => "VARCHAR(10) NOT NULL DEFAULT ''",
        'cleared_sid' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'cleared_direct' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'cleared_star' => "VARCHAR(80) NOT NULL DEFAULT ''",
    ] as $column => $definition) {
        $exists = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'atc_aircraft_clearances'
               AND COLUMN_NAME = " . $pdo->quote($column)
        )->fetchColumn();
        if ($exists === 0) {
            $pdo->exec("ALTER TABLE atc_aircraft_clearances ADD COLUMN {$column} {$definition} AFTER clearance_value");
            if ($column === 'cleared_departure_runway') {
                continue;
            }
            $legacyType = $column === 'cleared_sid'
                ? 'SID'
                : ($column === 'cleared_direct' ? 'DIRECT' : 'STAR');
            $pdo->exec(
                "UPDATE atc_aircraft_clearances
                 SET {$column} = clearance_value
                 WHERE clearance_type = " . $pdo->quote($legacyType) . " AND clearance_value <> ''"
            );
        }
    }

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

    $frequencyColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atc_sessions'
           AND COLUMN_NAME = 'frequency'"
    )->fetchColumn();
    if ((int)$frequencyColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_sessions
             ADD COLUMN frequency VARCHAR(12) NOT NULL DEFAULT '' AFTER map_profile"
        );
    }

    $radarBoundaryColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atc_sessions'
           AND COLUMN_NAME = 'radar_boundary_code'"
    )->fetchColumn();
    if ((int)$radarBoundaryColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_sessions
             ADD COLUMN radar_boundary_code VARCHAR(24) NOT NULL DEFAULT '' AFTER map_profile"
        );
    }
    $atisScopeColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atc_sessions'
           AND COLUMN_NAME = 'atis_scope_ready'"
    )->fetchColumn();
    if ((int)$atisScopeColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_sessions ADD COLUMN atis_scope_ready TINYINT(1) NOT NULL DEFAULT 0 AFTER frequency"
        );
    }
}
