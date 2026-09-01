<?php

function ensureCompendiumSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $marker = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'vfn-compendium-schema-20260827.ready';
    if (is_file($marker)) {
        $ready = true;
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS compendium_articles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(180) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            summary TEXT NULL,
            content_html MEDIUMTEXT NOT NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'general',
            language_code VARCHAR(10) NOT NULL DEFAULT 'en',
            scope_type ENUM('global','division') NOT NULL DEFAULT 'global',
            division_code VARCHAR(10) NULL,
            airport_code VARCHAR(20) NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            is_homepage TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 100,
            author_user_id INT UNSIGNED NULL,
            updated_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            published_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_compendium_slug (slug),
            KEY idx_compendium_listing (status, scope_type, division_code, category, sort_order),
            KEY idx_compendium_airport (airport_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    try {
        $pdo->exec("ALTER TABLE compendium_articles ADD COLUMN is_homepage TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    } catch (Throwable $exception) {
        // The column already exists on initialized installations.
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS compendium_aliases (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            article_id INT UNSIGNED NOT NULL,
            alias VARCHAR(180) NOT NULL,
            normalized_alias VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_compendium_alias (normalized_alias),
            KEY idx_compendium_alias_article (article_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS compendium_revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            article_id INT UNSIGNED NOT NULL,
            title VARCHAR(180) NOT NULL,
            summary TEXT NULL,
            content_html MEDIUMTEXT NOT NULL,
            editor_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_compendium_revision (article_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
    @file_put_contents($marker, gmdate('c'));
}

function compendiumNormalizeTerm(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
    return trim($value, '-');
}

function compendiumSlug(string $value): string
{
    $slug = compendiumNormalizeTerm($value);
    return $slug !== '' ? mb_substr($slug, 0, 190) : 'article-' . bin2hex(random_bytes(4));
}

function canEditCompendiumDivision(PDO $pdo, int $userId, int $opPermission, ?string $divisionCode): bool
{
    if ($opPermission >= 3) {
        return true;
    }
    if (!$divisionCode) {
        return false;
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM division_staff
         WHERE user_id=:user_id AND division_code=:division_code AND is_active=1
           AND (can_edit_content=1 OR role_code='DIR') LIMIT 1"
    );
    $stmt->execute(['user_id'=>$userId, 'division_code'=>$divisionCode]);
    return (bool)$stmt->fetchColumn();
}
