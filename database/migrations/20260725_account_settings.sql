CREATE TABLE IF NOT EXISTS division_transfer_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    current_division_code VARCHAR(12) NOT NULL,
    requested_division_code VARCHAR(12) NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_division_transfer_user_status (user_id, status),
    KEY idx_division_transfer_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action_name VARCHAR(40) NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_rate_action_subject (action_name, subject_hash),
    KEY idx_auth_rate_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_two_factor (
    user_id INT NOT NULL,
    method ENUM('off','totp','email') NOT NULL DEFAULT 'off',
    totp_secret VARCHAR(128) NULL,
    enabled_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS two_factor_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    challenge_token_hash CHAR(64) NOT NULL,
    code_hash CHAR(64) NULL,
    method ENUM('totp','email') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_two_factor_challenge_token (challenge_token_hash),
    KEY idx_two_factor_user_created (user_id, created_at),
    KEY idx_two_factor_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
