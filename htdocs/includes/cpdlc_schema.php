<?php
declare(strict_types=1);

function ensureCpdlcSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $marker = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'vfn-cpdlc-schema-20260827.ready';
    if (is_file($marker)) {
        $checked = true;
        return;
    }
    $checked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS cpdlc_connections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pilot_user_id INT NULL,
            pilot_session_token VARCHAR(191) NULL,
            pilot_callsign VARCHAR(32) NOT NULL,
            station_code VARCHAR(32) NOT NULL,
            controller_session_id BIGINT UNSIGNED NULL,
            controller_user_id INT NULL,
            state ENUM('requested','connected','rejected','closed') NOT NULL DEFAULT 'requested',
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            connected_at DATETIME NULL,
            closed_at DATETIME NULL,
            last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            transport VARCHAR(20) NOT NULL DEFAULT 'vfn',
            external_connection_key VARCHAR(191) NULL,
            PRIMARY KEY (id),
            KEY idx_cpdlc_pilot (pilot_user_id, state),
            KEY idx_cpdlc_station (station_code, state),
            KEY idx_cpdlc_controller (controller_session_id, state),
            KEY idx_cpdlc_activity (last_activity_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS cpdlc_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL,
            sender_role ENUM('pilot','atc','system') NOT NULL,
            sender_user_id INT NULL,
            message_type VARCHAR(40) NOT NULL DEFAULT 'free_text',
            message_text TEXT NOT NULL,
            response_options VARCHAR(255) NOT NULL DEFAULT '',
            reply_to_id BIGINT UNSIGNED NULL,
            status ENUM('sent','delivered','responded','closed') NOT NULL DEFAULT 'sent',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            delivered_at DATETIME NULL,
            responded_at DATETIME NULL,
            transport VARCHAR(20) NOT NULL DEFAULT 'vfn',
            external_message_key VARCHAR(191) NULL,
            external_packet TEXT NULL,
            gateway_sent_at DATETIME NULL,
            gateway_error VARCHAR(500) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_cpdlc_external_message (external_message_key),
            KEY idx_cpdlc_connection (connection_id, id),
            KEY idx_cpdlc_reply (reply_to_id),
            CONSTRAINT fk_cpdlc_connection FOREIGN KEY (connection_id)
                REFERENCES cpdlc_connections (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    vfnCpdlcAddColumn($pdo, 'cpdlc_connections', 'transport', "VARCHAR(20) NOT NULL DEFAULT 'vfn'");
    vfnCpdlcAddColumn($pdo, 'cpdlc_connections', 'external_connection_key', 'VARCHAR(191) NULL');
    vfnCpdlcMakeNullable($pdo, 'cpdlc_connections', 'pilot_user_id', 'INT NULL');
    vfnCpdlcMakeNullable($pdo, 'cpdlc_connections', 'pilot_session_token', 'VARCHAR(191) NULL');
    vfnCpdlcAddColumn($pdo, 'cpdlc_messages', 'transport', "VARCHAR(20) NOT NULL DEFAULT 'vfn'");
    vfnCpdlcAddColumn($pdo, 'cpdlc_messages', 'external_message_key', 'VARCHAR(191) NULL');
    vfnCpdlcAddColumn($pdo, 'cpdlc_messages', 'external_packet', 'TEXT NULL');
    vfnCpdlcAddColumn($pdo, 'cpdlc_messages', 'gateway_sent_at', 'DATETIME NULL');
    vfnCpdlcAddColumn($pdo, 'cpdlc_messages', 'gateway_error', 'VARCHAR(500) NULL');
    vfnCpdlcAddUniqueIndex($pdo, 'cpdlc_messages', 'uq_cpdlc_external_message', 'external_message_key');
    $pdo->exec("CREATE TABLE IF NOT EXISTS cpdlc_gateway_state (
        station_code VARCHAR(32) NOT NULL,
        next_min SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        last_poll_at DATETIME NULL,
        next_poll_at DATETIME NULL,
        last_success_at DATETIME NULL,
        last_error VARCHAR(500) NULL,
        PRIMARY KEY (station_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acars_gateway_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        station_code VARCHAR(32) NOT NULL,
        remote_callsign VARCHAR(32) NOT NULL,
        direction ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
        message_type VARCHAR(24) NOT NULL,
        message_text TEXT NOT NULL,
        external_packet TEXT NULL,
        external_message_key VARCHAR(191) NULL,
        state ENUM('received','handled','failed') NOT NULL DEFAULT 'received',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        handled_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_acars_external_message (external_message_key),
        KEY idx_acars_station_created (station_code, created_at),
        KEY idx_acars_remote_created (remote_callsign, created_at),
        KEY idx_acars_type_state (message_type, state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @file_put_contents($marker, gmdate('c'));
}

function vfnCpdlcAddUniqueIndex(PDO $pdo, string $table, string $index, string $column): void
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND INDEX_NAME=:i");
    $q->execute(['t' => $table, 'i' => $index]);
    if (!(int)$q->fetchColumn()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` (`{$column}`)");
    }
}

function vfnCpdlcAddColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(['t' => $table, 'c' => $column]);
    if (!(int)$q->fetchColumn()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function vfnCpdlcMakeNullable(PDO $pdo, string $table, string $column, string $definition): void
{
    $q = $pdo->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(['t' => $table, 'c' => $column]);
    if ($q->fetchColumn() === 'NO') {
        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
    }
}
