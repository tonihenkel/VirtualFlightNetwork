<?php
declare(strict_types=1);

function ensureAtcAtisOverrideSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS atc_atis_overrides (
            airport_icao VARCHAR(12) NOT NULL,
            updated_by BIGINT UNSIGNED NOT NULL,
            arrival_runways VARCHAR(64) NOT NULL DEFAULT '',
            departure_runways VARCHAR(64) NOT NULL DEFAULT '',
            transition_level VARCHAR(16) NOT NULL DEFAULT '',
            transition_altitude VARCHAR(16) NOT NULL DEFAULT '',
            approach_type VARCHAR(64) NOT NULL DEFAULT '',
            remarks VARCHAR(500) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (airport_icao),
            KEY idx_atc_atis_updated_by (updated_by),
            KEY idx_atc_atis_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

