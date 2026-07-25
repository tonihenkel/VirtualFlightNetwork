<?php
session_start();

require_once __DIR__ . '/execute/config.php';

$returnTo =
    $_GET['return_to']
    ?? $_SERVER['HTTP_REFERER']
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

if (!empty($_SESSION['web_voice_token'])) {
    try {
        $pdo =
            new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]
            );

        $stmt =
            $pdo->prepare(
                "UPDATE user_sessions
                 SET is_active = 0
                 WHERE token = :token"
            );

        $stmt->execute([
            'token' => (string)$_SESSION['web_voice_token']
        ]);
    } catch (Throwable $e) {
        // Logout darf nicht blockieren, falls die DB gerade nicht erreichbar ist.
    }
}

$_SESSION = [];

session_destroy();

$separator =
    strpos($returnTo, '?') !== false
    ? '&'
    : '?';

header(
    'Location: '
    . $returnTo
    . $separator
    . http_build_query([
        'type' => 'success',
        'message' => 'logout_success'
    ])
);

exit;
