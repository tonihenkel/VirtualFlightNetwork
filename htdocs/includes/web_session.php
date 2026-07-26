<?php

function clearVfnWebSession(): void
{
    foreach (array_keys($_SESSION ?? []) as $key) {
        if (
            strpos((string)$key, 'web_') === 0
            || strpos((string)$key, 'admin_') === 0
            || strpos((string)$key, 'two_factor_') === 0
        ) {
            unset($_SESSION[$key]);
        }
    }
}

function validateVfnWebSession(PDO $pdo): bool
{
    if (empty($_SESSION['web_user_id'])) {
        return false;
    }

    require_once __DIR__ . '/ban_status.php';

    $stmt = $pdo->prepare(
        "SELECT password_hash, id, op_permission
         FROM users
         WHERE id = :user_id
           AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([
        'user_id' => (int)$_SESSION['web_user_id']
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $passwordHash = (string)($user['password_hash'] ?? '');

    if (
        !empty($GLOBALS['maintenanceMode'])
        && (int)($user['op_permission'] ?? 0) < 5
    ) {
        clearVfnWebSession();
        return false;
    }

    if (
        $user
        && getActiveBanStatus($pdo, (int)$user['id'])['active']
    ) {
        clearVfnWebSession();
        return false;
    }

    $expectedFingerprint =
        $passwordHash === ''
            ? ''
            : hash('sha256', $passwordHash);

    $sessionFingerprint =
        (string)($_SESSION['web_auth_fingerprint'] ?? '');

    if (
        $expectedFingerprint === ''
        || $sessionFingerprint === ''
        || !hash_equals($expectedFingerprint, $sessionFingerprint)
    ) {
        clearVfnWebSession();
        return false;
    }

    return true;
}
