<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();

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

// A logout must never redirect back into a page that immediately requires
// the session we have just destroyed. Those pages otherwise answer with a
// raw JSON `login_required` response instead of rendering the website.
$returnPath = (string)(parse_url($returnTo, PHP_URL_PATH) ?? '');
$returnPage = strtolower(basename($returnPath));
$protectedReturnPages = [
    'admin.php',
    'admin_user.php',
    'admin_history.php',
    'messages.php',
    'moderation.php',
    'notifications.php',
    'flightplans.php',
    'system_status.php'
];
if (
    strpos($returnPage, 'admin') === 0
    || in_array($returnPage, $protectedReturnPages, true)
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
if (!headers_sent()) {
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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
        'type' => 'success',
        'message' => 'logout_success'
    ])
);

exit;
