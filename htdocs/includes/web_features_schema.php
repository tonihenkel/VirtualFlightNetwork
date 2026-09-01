<?php

function ensureWebFeatureSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $marker = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'vfn-web-features-schema-20260827.ready';
    if (is_file($marker)) {
        $checked = true;
        return;
    }
    $checked = true;

    require_once __DIR__ . '/language_preferences.php';
    vfnEnsurePreferredLanguageColumn($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_user_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            author_user_id INT NOT NULL,
            note_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_staff_notes_user_created (user_id, created_at),
            KEY idx_staff_notes_author_created (author_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS web_flightplans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            callsign VARCHAR(20) NOT NULL,
            flight_rules ENUM('I','V','Y','Z') NOT NULL DEFAULT 'I',
            flight_type ENUM('S','N','G','M','X') NOT NULL DEFAULT 'G',
            communication_mode ENUM('VOICE','RECEIVE_ONLY','TEXT_ONLY') NOT NULL DEFAULT 'VOICE',
            departure_time VARCHAR(20) NOT NULL DEFAULT '',
            departure_airport VARCHAR(10) NOT NULL DEFAULT 'ZZZZ',
            arrival_airport VARCHAR(10) NOT NULL DEFAULT 'ZZZZ',
            alternate1_airport VARCHAR(10) NOT NULL DEFAULT 'ZZZZ',
            alternate2_airport VARCHAR(10) NOT NULL DEFAULT 'ZZZZ',
            route_text TEXT NOT NULL,
            cruising_level VARCHAR(20) NOT NULL DEFAULT '',
            cruising_speed VARCHAR(20) NOT NULL DEFAULT '',
            remarks TEXT NOT NULL,
            status ENUM('draft','filed','archived') NOT NULL DEFAULT 'draft',
            plugin_selected TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_web_flightplans_user_updated (user_id, updated_at),
            KEY idx_web_flightplans_status_updated (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $column = $pdo->query("SHOW COLUMNS FROM web_flightplans LIKE 'plugin_selected'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec("ALTER TABLE web_flightplans ADD COLUMN plugin_selected TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    $column = $pdo->query("SHOW COLUMNS FROM web_flightplans LIKE 'communication_mode'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec("ALTER TABLE web_flightplans ADD COLUMN communication_mode ENUM('VOICE','RECEIVE_ONLY','TEXT_ONLY') NOT NULL DEFAULT 'VOICE' AFTER flight_type");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS web_notification_state (
            user_id INT NOT NULL,
            last_private_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_job_status (
            job_key VARCHAR(80) NOT NULL,
            last_started_at DATETIME NULL,
            last_success_at DATETIME NULL,
            last_error_at DATETIME NULL,
            last_error TEXT NULL,
            details_json TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (job_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    @file_put_contents($marker, gmdate('c'));
}
