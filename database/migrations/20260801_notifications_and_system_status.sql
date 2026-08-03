CREATE TABLE IF NOT EXISTS web_notification_state (
    user_id INT NOT NULL,
    last_private_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_job_status (
    job_key VARCHAR(80) NOT NULL,
    last_started_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_error TEXT NULL,
    details_json TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (job_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
