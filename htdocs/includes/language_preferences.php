<?php

const VFN_LANGUAGE_COOKIE = 'vfn_language';
const VFN_LANGUAGE_COOKIE_LIFETIME = 31536000; // 1 year

function vfnNormalizeLanguage($language): string
{
    $language = strtolower(trim((string)$language));
    return in_array($language, ['de', 'en'], true) ? $language : '';
}

function vfnStoreLanguageCookie(string $language): void
{
    if ($language === '' || headers_sent()) {
        return;
    }

    setcookie(VFN_LANGUAGE_COOKIE, $language, [
        'expires' => time() + VFN_LANGUAGE_COOKIE_LIFETIME,
        'path' => '/',
        'secure' => true,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[VFN_LANGUAGE_COOKIE] = $language;
}

function vfnEnsurePreferredLanguageColumn(PDO $pdo): void
{
    $column = $pdo->query(
        "SHOW COLUMNS FROM users LIKE 'preferred_language'"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$column) {
        try {
            $pdo->exec(
                "ALTER TABLE users
                 ADD COLUMN preferred_language VARCHAR(2) NULL DEFAULT NULL
                 AFTER country_code"
            );
        } catch (Throwable $error) {
            // A concurrent request may have created the column already.
            $column = $pdo->query(
                "SHOW COLUMNS FROM users LIKE 'preferred_language'"
            )->fetch(PDO::FETCH_ASSOC);
            if (!$column) {
                throw $error;
            }
        }
    }
}

function vfnLoadUserLanguage(PDO $pdo, int $userId): string
{
    vfnEnsurePreferredLanguageColumn($pdo);
    $stmt = $pdo->prepare(
        "SELECT preferred_language FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $userId]);
    return vfnNormalizeLanguage($stmt->fetchColumn());
}

function vfnSaveUserLanguage(
    PDO $pdo,
    int $userId,
    string $language
): void {
    $language = vfnNormalizeLanguage($language);
    if ($userId <= 0 || $language === '') {
        return;
    }
    vfnEnsurePreferredLanguageColumn($pdo);
    $stmt = $pdo->prepare(
        "UPDATE users SET preferred_language = :language WHERE id = :id"
    );
    $stmt->execute(['language' => $language, 'id' => $userId]);
}
