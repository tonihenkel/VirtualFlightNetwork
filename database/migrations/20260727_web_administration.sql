CREATE TABLE IF NOT EXISTS staff_user_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    author_user_id INT NOT NULL,
    note_text TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_notes_user_created (user_id, created_at),
    KEY idx_staff_notes_author_created (author_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_flightplans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    callsign VARCHAR(20) NOT NULL,
    flight_rules ENUM('I','V','Y','Z') NOT NULL DEFAULT 'I',
    flight_type ENUM('S','N','G','M','X') NOT NULL DEFAULT 'G',
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_web_flightplans_user_updated (user_id, updated_at),
    KEY idx_web_flightplans_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_activity_staff_history
    ON user_activity_log (activity_type, created_at);
