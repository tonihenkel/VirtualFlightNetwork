<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/two_factor.php';
require_once __DIR__ . '/../includes/web_session.php';

function settingsRedirect(string $type, string $message): void
{
    header(
        'Location: ../profile.php?'
        . http_build_query([
            'id' => (int)($_SESSION['web_user_id'] ?? 0),
            'a' => 'settings',
            'type' => $type,
            'message' => $message
        ])
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['web_user_id'])) {
    settingsRedirect('error', 'login_required');
}

if (
    empty($_POST['csrf'])
    || empty($_SESSION['profile_settings_csrf'])
    || !hash_equals((string)$_SESSION['profile_settings_csrf'], (string)$_POST['csrf'])
) {
    settingsRedirect('error', 'settings_invalid_request');
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (!validateVfnWebSession($pdo)) {
        settingsRedirect('error', 'login_required');
    }
    $userId = (int)$_SESSION['web_user_id'];
    $stmt = $pdo->prepare(
        "SELECT username, real_name, password_hash, country_code, division_code, op_permission
         FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        settingsRedirect('error', 'user_not_found');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'personal') {
        $username = trim((string)($_POST['username'] ?? ''));
        $realName = trim((string)($_POST['real_name'] ?? ''));
        $country = strtoupper(trim((string)($_POST['country_code'] ?? '')));
        $password = (string)($_POST['current_password'] ?? '');
        $countries = require __DIR__ . '/../includes/countries.php';

        if (
            !preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username)
            || $realName === ''
            || mb_strlen($realName) > 100
            || !isset($countries[$country])
            || !password_verify($password, (string)$user['password_hash'])
        ) {
            settingsRedirect('error', 'settings_invalid_data');
        }

        $duplicate = $pdo->prepare(
            "SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1"
        );
        $duplicate->execute(['username' => $username, 'id' => $userId]);
        if ($duplicate->fetchColumn()) {
            settingsRedirect('error', 'settings_username_taken');
        }

        $pdo->prepare(
            "UPDATE users
             SET username = :username, real_name = :real_name,
                 country_code = :country_code, updated_at = NOW()
             WHERE id = :id"
        )->execute([
            'username' => $username,
            'real_name' => $realName,
            'country_code' => $country,
            'id' => $userId
        ]);
        $_SESSION['web_username'] = $username;
        $_SESSION['web_real_name'] = $realName;
        if ($username !== (string)$user['username']) {
            logActivity($pdo, $userId, 'username_changed', 'activity_username_changed', $username, $userId);
        }
        if ($realName !== (string)$user['real_name']) {
            logActivity($pdo, $userId, 'real_name_changed', 'activity_real_name_changed', $realName, $userId);
        }
        if ($country !== strtoupper((string)$user['country_code'])) {
            logActivity($pdo, $userId, 'country_changed', 'activity_country_changed', $country, $userId);
        }
        settingsRedirect('success', 'settings_saved');
    }

    if ($action === 'division') {
        $division = strtoupper(trim((string)($_POST['division_code'] ?? '')));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $valid = $pdo->prepare(
            "SELECT code FROM divisions WHERE code = :code AND is_active = 1 LIMIT 1"
        );
        $valid->execute(['code' => $division]);
        if (
            !$valid->fetchColumn()
            || $division === strtoupper((string)$user['division_code'])
            || $reason === ''
        ) {
            settingsRedirect('error', 'settings_invalid_data');
        }
        $pending = $pdo->prepare(
            "SELECT id FROM division_transfer_requests
             WHERE user_id = :user_id AND status = 'pending' LIMIT 1"
        );
        $pending->execute(['user_id' => $userId]);
        if ($pending->fetchColumn()) {
            settingsRedirect('error', 'settings_division_already_pending');
        }
        $pdo->prepare(
            "INSERT INTO division_transfer_requests
                (user_id, current_division_code, requested_division_code, reason)
             VALUES (:user_id, :current_division, :requested_division, :reason)"
        )->execute([
            'user_id' => $userId,
            'current_division' => (string)$user['division_code'],
            'requested_division' => $division,
            'reason' => mb_substr($reason, 0, 500)
        ]);
        logActivity(
            $pdo,
            $userId,
            'division_change_requested',
            'activity_division_change_requested',
            (string)$user['division_code'] . ' -> ' . $division,
            $userId
        );
        settingsRedirect('success', 'settings_division_submitted');
    }

    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $repeat = (string)($_POST['repeat_password'] ?? '');
        if (
            !password_verify($current, (string)$user['password_hash'])
            || strlen($new) < 10
            || $new !== $repeat
        ) {
            settingsRedirect('error', 'settings_password_invalid');
        }
        $newPasswordHash =
            password_hash($new, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE users SET password_hash = :password_hash, updated_at = NOW()
             WHERE id = :id"
        )->execute([
            'password_hash' => $newPasswordHash,
            'id' => $userId
        ]);
        $pdo->prepare(
            "UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND used_at IS NULL"
        )->execute(['user_id' => $userId]);
        logActivity($pdo, $userId, 'password_changed', 'activity_password_changed', '', $userId);
        $pdo->commit();
        $_SESSION['web_auth_fingerprint'] =
            hash('sha256', $newPasswordHash);
        settingsRedirect('success', 'settings_password_changed');
    }

    if ($action === 'two_factor') {
        $method = (string)($_POST['method'] ?? 'off');
        $password = (string)($_POST['current_password'] ?? '');
        if (
            (int)$user['op_permission'] < 1
            ||
            !in_array($method, ['off', 'totp', 'email'], true)
            || !password_verify($password, (string)$user['password_hash'])
        ) {
            settingsRedirect('error', 'settings_invalid_data');
        }
        $secret = null;
        if ($method === 'totp') {
            $secret = (string)($_SESSION['profile_totp_setup_secret'] ?? '');
            if (
                $secret === ''
                || !twoFactorVerifyTotp($secret, (string)($_POST['totp_code'] ?? ''))
            ) {
                settingsRedirect('error', 'settings_2fa_code_invalid');
            }
        }
        $pdo->prepare(
            "INSERT INTO user_two_factor (user_id, method, totp_secret, enabled_at)
             VALUES (:user_id, :method, :secret, IF(:method_enabled = 'off', NULL, NOW()))
             ON DUPLICATE KEY UPDATE
                method = VALUES(method),
                totp_secret = VALUES(totp_secret),
                enabled_at = VALUES(enabled_at)"
        )->execute([
            'user_id' => $userId,
            'method' => $method,
            'secret' => $secret,
            'method_enabled' => $method
        ]);
        unset($_SESSION['profile_totp_setup_secret']);
        settingsRedirect('success', 'settings_2fa_saved');
    }

    settingsRedirect('error', 'settings_invalid_request');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    settingsRedirect('error', 'login_server_error');
}
