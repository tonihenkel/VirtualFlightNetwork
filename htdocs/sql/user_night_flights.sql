CREATE TABLE IF NOT EXISTS user_night_flights (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    pilot_landing_id INT NOT NULL,
    aircraft_icao VARCHAR(20) NOT NULL,
    night_duration_seconds INT NOT NULL,
    total_duration_seconds INT NOT NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_night_flight_landing (pilot_landing_id),
    KEY idx_user_night_flights_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
