<?php

function ensureBugReportSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $marker = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'vfn-bug-report-schema-20260827.ready';
    if (is_file($marker)) {
        $ready = true;
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS bug_reports (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        reporter_user_id INT NOT NULL,
        claimed_by_user_id INT NULL,
        title VARCHAR(160) NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'other',
        affected_area VARCHAR(40) NOT NULL DEFAULT 'website',
        severity VARCHAR(20) NOT NULL DEFAULT 'normal',
        status VARCHAR(24) NOT NULL DEFAULT 'new',
        environment VARCHAR(255) NOT NULL DEFAULT '',
        client_version VARCHAR(100) NOT NULL DEFAULT '',
        reproducibility VARCHAR(24) NOT NULL DEFAULT 'sometimes',
        description TEXT NOT NULL,
        steps_to_reproduce TEXT NULL,
        expected_result TEXT NULL,
        actual_result TEXT NULL,
        reference_url VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_bug_reports_reporter (reporter_user_id, updated_at),
        KEY idx_bug_reports_claimed (claimed_by_user_id, status, updated_at),
        KEY idx_bug_reports_status (status, updated_at),
        CONSTRAINT fk_bug_reports_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_bug_reports_claimed FOREIGN KEY (claimed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bug_report_staff (
        bug_report_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        added_by_user_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (bug_report_id, user_id),
        KEY idx_bug_report_staff_user (user_id),
        CONSTRAINT fk_bug_report_staff_report FOREIGN KEY (bug_report_id) REFERENCES bug_reports(id) ON DELETE CASCADE,
        CONSTRAINT fk_bug_report_staff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_bug_report_staff_added_by FOREIGN KEY (added_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bug_report_posts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bug_report_id BIGINT UNSIGNED NOT NULL,
        author_user_id INT NOT NULL,
        message TEXT NOT NULL,
        is_internal TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        edited_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_bug_report_posts_report (bug_report_id, created_at),
        CONSTRAINT fk_bug_report_posts_report FOREIGN KEY (bug_report_id) REFERENCES bug_reports(id) ON DELETE CASCADE,
        CONSTRAINT fk_bug_report_posts_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bug_report_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bug_report_id BIGINT UNSIGNED NOT NULL,
        actor_user_id INT NULL,
        event_type VARCHAR(40) NOT NULL,
        old_value VARCHAR(255) NOT NULL DEFAULT '',
        new_value VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_bug_report_events_report (bug_report_id, created_at),
        CONSTRAINT fk_bug_report_events_report FOREIGN KEY (bug_report_id) REFERENCES bug_reports(id) ON DELETE CASCADE,
        CONSTRAINT fk_bug_report_events_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
    @file_put_contents($marker, gmdate('c'));
}

function bugReportStatuses(): array
{
    return ['new', 'open', 'in_progress', 'waiting_user', 'testing', 'resolved', 'closed', 'rejected'];
}

function bugReportCanClose(string $status): bool
{
    return in_array($status, ['resolved', 'closed', 'rejected'], true);
}
