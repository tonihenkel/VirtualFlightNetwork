<?php
session_start();

require_once 'execute/config.php';
require_once 'includes/auth_security.php';
require_once 'includes/ban_status.php';
require_once 'execute/send_mail.php';

function redirectBack(string $type, string $message): void
{
    $returnTo =
        $_POST['return_to']
        ?? $_GET['return_to']
        ?? 'index.php';

    if (
        !is_string($returnTo) ||
        $returnTo === '' ||
        strpos($returnTo, 'http://') === 0 ||
        strpos($returnTo, 'https://') === 0 ||
        strpos($returnTo, '//') === 0
    ) {
        $returnTo = 'index.php';
    }

    $separator =
        strpos($returnTo, '?') !== false
        ? '&'
        : '?';

    header(
        'Location: '
        . $returnTo
        . $separator
        . http_build_query([
            'type' => $type,
            'message' => $message
        ])
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectBack('error', 'Ungültige Anfrage.');
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    redirectBack('error', 'Bitte Benutzername und Passwort eingeben.');
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (
        authRateIsBlocked($pdo, 'login_identifier', $username)
        || authRateIsBlocked($pdo, 'login_ip', $clientIp)
    ) {
        redirectBack('error', 'login_rate_limited');
    }

    $stmt = $pdo->prepare(
        "SELECT
            id,
            username,
            email,
            real_name,
            password_hash,
            op_permission,
            rating_pilot,
            rating_atc,
            rating_special,
            email_verified,
            is_active,
            is_banned,
            ban_reason,
            ban_expires_at
         FROM users
         WHERE username = :username
            OR email = :username
         LIMIT 1"
    );

    $stmt->execute([
        'username' => $username
    ]);

    $user =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        authRateFail($pdo, 'login_identifier', $username);
        authRateFail($pdo, 'login_ip', $clientIp, 10);
        redirectBack('error', 'login_invalid');
    }

    if (!password_verify($password, $user['password_hash'])) {
        authRateFail($pdo, 'login_identifier', $username);
        authRateFail($pdo, 'login_ip', $clientIp, 10);
        redirectBack('error', 'login_invalid');
    }

    if ((int)$user['is_active'] !== 1) {
        redirectBack('error', 'Dieser Account ist derzeit deaktiviert.');
    }

    if (
        !empty($maintenanceMode)
        && (int)$user['op_permission'] < 5
    ) {
        redirectBack('error', 'maintenance_mode_active');
    }

    if ((int)$user['email_verified'] !== 1) {
        redirectBack('error', 'Bitte bestätige zuerst deine E-Mail-Adresse.');
    }

    authRateClear($pdo, 'login_identifier', $username);

    $twoFactorStmt = $pdo->prepare(
        "SELECT method FROM user_two_factor WHERE user_id = :user_id LIMIT 1"
    );
    $twoFactorStmt->execute(['user_id' => $user['id']]);
    $twoFactorMethod = (string)($twoFactorStmt->fetchColumn() ?: 'off');

    if ($twoFactorMethod === 'totp' || $twoFactorMethod === 'email') {
        $challengeToken = bin2hex(random_bytes(32));
        $emailCode = $twoFactorMethod === 'email'
            ? str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            : null;
        $pdo->prepare(
            "INSERT INTO two_factor_challenges
                (user_id, challenge_token_hash, code_hash, method, expires_at)
             VALUES (:user_id, :token_hash, :code_hash, :method,
                     DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
        )->execute([
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $challengeToken),
            'code_hash' => $emailCode !== null ? hash('sha256', $emailCode) : null,
            'method' => $twoFactorMethod
        ]);

        if ($emailCode !== null) {
            $mailSent = sendMail(
                (string)$user['email'],
                (string)($user['real_name'] ?: $user['username']),
                'VFN Login-Code',
                '<p>Dein Code für die Anmeldung beim Virtual Flight Network lautet:</p>'
                . '<p style="font-size:28px;font-weight:bold;letter-spacing:5px;">'
                . htmlspecialchars($emailCode) . '</p>'
                . '<p>Der Code ist 10 Minuten gültig.</p>'
            );
            if (!$mailSent) {
                $pdo->prepare(
                    "DELETE FROM two_factor_challenges
                     WHERE challenge_token_hash = :token_hash"
                )->execute(['token_hash' => hash('sha256', $challengeToken)]);
                redirectBack('error', 'login_server_error');
            }
        }

        $_SESSION['two_factor_challenge_token'] = $challengeToken;
        $_SESSION['two_factor_return_to'] =
            (string)($_POST['return_to'] ?? 'index.php');
        header('Location: two_factor_login.php');
        exit;
    }

    $banStatus = getActiveBanStatus($pdo, (int)$user['id']);
    if ($banStatus['active']) {
        redirectBack('error', 'Dieser Account ist gebannt: ' . $banStatus['reason']);
    }

    $_SESSION['web_user_id'] =
        (int)$user['id'];

    $_SESSION['web_username'] =
        $user['username'];

    $_SESSION['web_email'] =
        $user['email'];

    $_SESSION['web_real_name'] =
        $user['real_name'];

    $_SESSION['web_op_permission'] =
        (int)$user['op_permission'];

    $_SESSION['web_auth_fingerprint'] =
        hash('sha256', (string)$user['password_hash']);

    $_SESSION['web_rating_pilot'] =
    (int)$user['rating_pilot'];

    $_SESSION['web_rating_atc'] =
        (int)$user['rating_atc'];

    $_SESSION['web_rating_special'] =
        (int)$user['rating_special'];

    $stmt = $pdo->prepare(
        "UPDATE users
         SET last_login = NOW()
         WHERE id = :id"
    );

    $stmt->execute([
        'id' => $user['id']
    ]);

    redirectBack(
        'success',
        'login_success'
    );

} catch (Exception $e) {
    redirectBack(
        'error',
        'login_server_error'
    );
}
