CREATE TABLE user_warnings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    issued_by_user_id INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoked_by_user_id INT NULL,
    revoke_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_warnings_user_active (user_id, revoked_at, expires_at),
    KEY idx_user_warnings_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pilot_flights (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_token VARCHAR(128) NOT NULL,
    callsign VARCHAR(20) NOT NULL,
    aircraft_icao VARCHAR(20) NOT NULL,
    departure_airport VARCHAR(10) NULL,
    arrival_airport VARCHAR(10) NULL,
    landed_airport VARCHAR(10) NULL,
    destination_distance_nm DECIMAL(10,2) NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    distance_nm DECIMAL(12,2) NOT NULL DEFAULT 0,
    landing_rate_fpm INT NULL,
    status ENUM('active','completed','wrong_destination','aborted') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pilot_flights_active_session (session_token),
    KEY idx_pilot_flights_user_started (user_id, started_at),
    KEY idx_pilot_flights_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
