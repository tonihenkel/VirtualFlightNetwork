CREATE TABLE IF NOT EXISTS division_staff (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    division_code VARCHAR(10) NOT NULL,
    user_id INT NOT NULL,
    role_code VARCHAR(24) NOT NULL DEFAULT 'STAFF',
    role_title VARCHAR(100) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 100,
    can_edit_content TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    appointed_by_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_division_staff_division_user (division_code, user_id),
    KEY idx_division_staff_division_active (division_code, is_active, sort_order),
    KEY idx_division_staff_user_active (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

