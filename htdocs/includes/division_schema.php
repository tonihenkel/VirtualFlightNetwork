<?php

function ensureDivisionManagementSchema(PDO $pdo): void
{
    $joinColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'divisions'
           AND COLUMN_NAME = 'join_enabled'"
    )->fetchColumn();
    if ((int)$joinColumn === 0) {
        $pdo->exec(
            "ALTER TABLE divisions
             ADD COLUMN join_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active"
        );
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS division_staff (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS division_content_revisions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            division_code VARCHAR(10) NOT NULL,
            website_content LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_division_revision (division_code, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function canManageDivisionJoin(PDO $pdo, int $userId, int $opPermission, string $divisionCode): bool
{
    if ($opPermission >= 1) {
        return true;
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM division_staff
         WHERE user_id = :user_id
           AND division_code = :division_code
           AND role_code = 'DIR'
           AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([
        'user_id' => $userId,
        'division_code' => strtoupper($divisionCode)
    ]);
    return (bool)$stmt->fetchColumn();
}

function divisionStaffRoles(): array
{
    return [
        'DIR' => 'Division Director',
        'ADIR' => 'Assistant Division Director',
        'OPS' => 'Flight Operations Coordinator',
        'TRAINING' => 'Training Coordinator',
        'MEMBERSHIP' => 'Membership Coordinator',
        'EVENTS' => 'Events Coordinator',
        'WEB' => 'Web & Systems Coordinator',
        'STAFF' => 'Division Staff'
    ];
}

function divisionFlagEmoji(string $code): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code) || !function_exists('mb_chr')) {
        return '🏳';
    }
    return mb_chr(0x1F1E6 + ord($code[0]) - 65, 'UTF-8')
        . mb_chr(0x1F1E6 + ord($code[1]) - 65, 'UTF-8');
}

function canEditDivisionContent(PDO $pdo, int $userId, int $opPermission, string $divisionCode): bool
{
    if ($opPermission >= 3) {
        return true;
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM division_staff
         WHERE user_id = :user_id
           AND division_code = :division_code
           AND is_active = 1
           AND can_edit_content = 1
         LIMIT 1"
    );
    $stmt->execute([
        'user_id' => $userId,
        'division_code' => strtoupper($divisionCode)
    ]);
    return (bool)$stmt->fetchColumn();
}
