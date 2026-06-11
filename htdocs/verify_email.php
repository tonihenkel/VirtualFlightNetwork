<?php
session_start();

$verifyLanguage =
    $_GET['lang']
    ?? $_SESSION['language']
    ?? 'en';

$verifyLanguage =
    strtolower(
        trim($verifyLanguage)
    );

if (
    $verifyLanguage !== 'de' &&
    $verifyLanguage !== 'en'
) {
    $verifyLanguage = 'en';
}

$_GET['lang'] =
    $verifyLanguage;

require_once 'execute/config.php';
require_once 'includes/language.php';

function redirectBack(string $type, string $message): void
{
    global $verifyLanguage;

    header(
        'Location: index.php?'
        . http_build_query([
            'lang' => $verifyLanguage,
            'type' => $type,
            'message' => $message
        ])
    );

    exit;
}

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    redirectBack(
        'error',
        'verify_invalid_link'
    );
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
            email_verified,
            email_verify_created_at
         FROM users
         WHERE email_verify_token = :token
         LIMIT 1"
    );

    $stmt->execute([
        'token' => $token
    ]);

    $user =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        redirectBack(
            'error',
            'verify_link_invalid_used'
        );
    }

    if ((int)$user['email_verified'] === 1) {
        redirectBack(
            'success',
            'verify_already_confirmed'
        );
    }

    if (!empty($user['email_verify_created_at'])) {
        $createdAt =
            strtotime($user['email_verify_created_at']);

        $maxAgeSeconds =
            60 * 60 * 24;

        if (
            $createdAt !== false &&
            time() - $createdAt > $maxAgeSeconds
        ) {
            redirectBack(
                'error',
                'verify_link_expired'
            );
        }
    }

    $stmt = $pdo->prepare(
        "UPDATE users
         SET
            email_verified = 1,
            email_verify_token = NULL,
            email_verify_created_at = NULL,
            updated_at = NOW()
         WHERE id = :id"
    );

    $stmt->execute([
        'id' => $user['id']
    ]);

    redirectBack(
        'success',
        'verify_success'
    );
} catch (Exception $e) {
    redirectBack(
        'error',
        'verify_server_error'
    );
}