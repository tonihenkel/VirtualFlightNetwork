<?php

function ensureDivisionManagementSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $marker = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'vfn-division-schema-20260827.ready';
    if (is_file($marker)) {
        $checked = true;
        return;
    }
    $checked = true;

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
    $gcaColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'divisions'
           AND COLUMN_NAME = 'gca_requests_enabled'"
    )->fetchColumn();
    if ((int)$gcaColumn === 0) {
        $pdo->exec(
            "ALTER TABLE divisions
             ADD COLUMN gca_requests_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER join_enabled"
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
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS guest_controller_approvals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            division_code VARCHAR(10) NOT NULL,
            status ENUM('pending','approved','rejected','revoked') NOT NULL DEFAULT 'pending',
            request_message TEXT NOT NULL,
            review_note TEXT NULL,
            reviewed_by_user_id INT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gca_user_division (user_id, division_code),
            KEY idx_gca_division_status (division_code, status, requested_at),
            KEY idx_gca_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    @file_put_contents($marker, gmdate('c'));
}

function canManageDivisionGca(PDO $pdo, int $userId, int $opPermission, string $divisionCode): bool
{
    if ($opPermission >= 1) return true;
    $stmt = $pdo->prepare(
        "SELECT 1 FROM division_staff
         WHERE user_id=:user_id AND division_code=:division_code
           AND role_code IN ('DIR','ADIR') AND is_active=1 LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId, 'division_code' => strtoupper($divisionCode)]);
    return (bool)$stmt->fetchColumn();
}

function canManageDivisionGcaSettings(PDO $pdo, int $userId, int $opPermission, string $divisionCode): bool
{
    if ($opPermission >= 1) return true;
    $stmt = $pdo->prepare(
        "SELECT 1 FROM division_staff
         WHERE user_id=:user_id AND division_code=:division_code
           AND is_active=1 LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId, 'division_code' => strtoupper($divisionCode)]);
    return (bool)$stmt->fetchColumn();
}

function getApprovedGcaDivisions(PDO $pdo, int $userId): array
{
    ensureDivisionManagementSchema($pdo);
    $stmt = $pdo->prepare(
        "SELECT division_code FROM guest_controller_approvals
         WHERE user_id=:user_id AND status='approved'"
    );
    $stmt->execute(['user_id' => $userId]);
    return array_values(array_unique(array_map('strtoupper', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function getGcaEffectiveAtcRating(int $rating): int
{
    $effective = max(0, $rating - 1);
    // A guest approval never grants a trainee position in the foreign division.
    // Fall back to the preceding fully qualified controller level instead.
    if ($effective === 4) return 3; // PAT -> TWR
    if ($effective === 6) return 5; // RAT -> APC
    return $effective;
}

function getGcaAllowedAtcPositions(int $rating): array
{
    $positions = [];
    foreach (getAtcPositionPermissions(getGcaEffectiveAtcRating($rating), 0) as $permission) {
        if (!empty($permission['allowed'])) $positions[] = (string)$permission['code'];
    }
    return $positions;
}

function hasApprovedGca(PDO $pdo, int $userId, string $divisionCode): bool
{
    return in_array(strtoupper($divisionCode), getApprovedGcaDivisions($pdo, $userId), true);
}

function deleteHomeDivisionGca(PDO $pdo, int $userId, string $divisionCode): void
{
    $divisionCode = strtoupper(trim($divisionCode));
    if ($userId <= 0 || $divisionCode === '') {
        return;
    }

    ensureDivisionManagementSchema($pdo);
    $stmt = $pdo->prepare(
        'DELETE FROM guest_controller_approvals
         WHERE user_id = :user_id AND division_code = :division_code'
    );
    $stmt->execute([
        'user_id' => $userId,
        'division_code' => $divisionCode,
    ]);
}

function getDivisionAirportPrefixes(PDO $pdo, string $divisionCode): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT LEFT(UPPER(COALESCE(NULLIF(icao_code,''),NULLIF(gps_code,''),ident)),2) prefix
         FROM airports WHERE iso_country=:division
           AND CHAR_LENGTH(COALESCE(NULLIF(icao_code,''),NULLIF(gps_code,''),ident))=4 LIMIT 30"
    );
    $stmt->execute(['division' => strtoupper($divisionCode)]);
    return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static function ($value): bool {
        return (bool)preg_match('/^[A-Z0-9]{2}$/', (string)$value);
    }));
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
