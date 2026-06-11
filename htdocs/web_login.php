<?php
session_start();

require_once 'execute/config.php';

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
            is_active
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
        redirectBack('error', 'login_invalid');
    }

    if (!password_verify($password, $user['password_hash'])) {
        redirectBack('error', 'login_invalid');
    }

    if ((int)$user['is_active'] !== 1) {
        redirectBack('error', 'Dieser Account ist derzeit deaktiviert.');
    }

    if ((int)$user['email_verified'] !== 1) {
        redirectBack('error', 'Bitte bestätige zuerst deine E-Mail-Adresse.');
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