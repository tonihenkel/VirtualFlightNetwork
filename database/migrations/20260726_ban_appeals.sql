CREATE TABLE ban_appeal_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT NULL,
    review_reason VARCHAR(255) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ban_appeal_status_created (status, created_at),
    KEY idx_ban_appeal_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

