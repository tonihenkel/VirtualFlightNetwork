CREATE TABLE IF NOT EXISTS division_content_revisions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    division_code VARCHAR(10) NOT NULL,
    website_content LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_division_revision (division_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
