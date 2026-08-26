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
            is_gca TINYINT(1) NOT NULL DEFAULT 0,
            is_spectator TINYINT(1) NOT NULL DEFAULT 0,
            is_trainer TINYINT(1) NOT NULL DEFAULT 0,
            is_ready TINYINT(1) NOT NULL DEFAULT 1,
            is_invisible TINYINT(1) NOT NULL DEFAULT 0,
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
        "CREATE TABLE IF NOT EXISTS atc_session_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            atc_session_id BIGINT UNSIGNED NOT NULL,
            user_id INT NOT NULL,
            callsign VARCHAR(40) NOT NULL,
            station_code VARCHAR(12) NOT NULL,
            position_code VARCHAR(8) NOT NULL,
            is_trainer TINYINT(1) NOT NULL DEFAULT 0,
            connected_at DATETIME NOT NULL,
            disconnected_at DATETIME NOT NULL,
            duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY uq_atc_history_session (atc_session_id),
            KEY idx_atc_history_user (user_id, disconnected_at),
            KEY idx_atc_history_position (callsign, disconnected_at)
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
            cleared_landing_runway VARCHAR(24) NOT NULL DEFAULT '',
            cleared_gate VARCHAR(40) NOT NULL DEFAULT '',
            cleared_altitude VARCHAR(20) NOT NULL DEFAULT '',
            issued_by_user_id INT NOT NULL,
            issued_by_callsign VARCHAR(40) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (pilot_session_token),
            KEY idx_atc_clearance_callsign (pilot_callsign)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_operational_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            airport_icao VARCHAR(12) NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            old_value VARCHAR(160) NOT NULL DEFAULT '',
            new_value VARCHAR(160) NOT NULL DEFAULT '',
            created_by_user_id INT NULL,
            created_by_callsign VARCHAR(40) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_atc_event_airport_id (airport_icao, id),
            KEY idx_atc_event_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_handoff_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pilot_session_token VARCHAR(128) NOT NULL,
            pilot_callsign VARCHAR(40) NOT NULL,
            source_session_id BIGINT UNSIGNED NOT NULL,
            source_callsign VARCHAR(40) NOT NULL,
            target_session_id BIGINT UNSIGNED NOT NULL,
            target_callsign VARCHAR(40) NOT NULL,
            status ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_atc_handoff_target (target_session_id,status,id),
            KEY idx_atc_handoff_source (source_session_id,status,id),
            KEY idx_atc_handoff_pilot (pilot_session_token,status),
            CONSTRAINT fk_atc_handoff_source FOREIGN KEY (source_session_id)
                REFERENCES atc_sessions(id) ON DELETE CASCADE,
            CONSTRAINT fk_atc_handoff_target FOREIGN KEY (target_session_id)
                REFERENCES atc_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_coordination_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender_user_id INT NOT NULL,
            sender_session_id BIGINT UNSIGNED NOT NULL,
            sender_callsign VARCHAR(40) NOT NULL,
            sender_station VARCHAR(12) NOT NULL,
            message_text VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_atc_coordination_region (sender_station, id),
            KEY idx_atc_coordination_created (created_at),
            CONSTRAINT fk_atc_coordination_session FOREIGN KEY (sender_session_id)
                REFERENCES atc_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    foreach ([
        'cleared_departure_runway' => "VARCHAR(10) NOT NULL DEFAULT ''",
        'cleared_landing_runway' => "VARCHAR(24) NOT NULL DEFAULT ''",
        'cleared_gate' => "VARCHAR(40) NOT NULL DEFAULT ''",
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
            if (in_array($column, ['cleared_departure_runway', 'cleared_landing_runway', 'cleared_gate'], true)) {
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
    $invisibleColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atc_sessions'
           AND COLUMN_NAME = 'is_invisible'"
    )->fetchColumn();
    if ((int)$invisibleColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_sessions ADD COLUMN is_invisible TINYINT(1) NOT NULL DEFAULT 0 AFTER is_spectator"
        );
    }
    $trainerColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atc_sessions'
           AND COLUMN_NAME = 'is_trainer'"
    )->fetchColumn();
    if ((int)$trainerColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_sessions ADD COLUMN is_trainer TINYINT(1) NOT NULL DEFAULT 0 AFTER is_spectator"
        );
    }
    $readyColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atc_sessions'
           AND COLUMN_NAME = 'is_ready'"
    )->fetchColumn();
    if ((int)$readyColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_sessions ADD COLUMN is_ready TINYINT(1) NOT NULL DEFAULT 1 AFTER is_trainer"
        );
    }
    $historyTrainerColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atc_session_history'
           AND COLUMN_NAME = 'is_trainer'"
    )->fetchColumn();
    if ((int)$historyTrainerColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_session_history ADD COLUMN is_trainer TINYINT(1) NOT NULL DEFAULT 0 AFTER position_code"
        );
    }
    $gcaColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atc_sessions'
           AND COLUMN_NAME = 'is_gca'"
    )->fetchColumn();
    if ((int)$gcaColumn === 0) {
        $pdo->exec(
            "ALTER TABLE atc_sessions ADD COLUMN is_gca TINYINT(1) NOT NULL DEFAULT 0 AFTER position_code"
        );
    }
}

function archiveAtcSessions(PDO $pdo, string $condition='1=1', array $params=[]): void
{
    $sql="INSERT IGNORE INTO atc_session_history
          (atc_session_id,user_id,callsign,station_code,position_code,is_trainer,connected_at,disconnected_at,duration_seconds)
          SELECT a.id,a.user_id,a.callsign,a.station_code,a.position_code,a.is_trainer,a.connected_at,
                 COALESCE(a.disconnected_at,NOW()),
                 GREATEST(0,TIMESTAMPDIFF(SECOND,a.connected_at,COALESCE(a.disconnected_at,NOW())))
          FROM atc_sessions a
          WHERE (a.is_spectator=0 OR a.is_trainer=1)
            AND a.is_ready=1
            AND a.is_invisible=0 AND ($condition)";
    $stmt=$pdo->prepare($sql); $stmt->execute($params);
}
