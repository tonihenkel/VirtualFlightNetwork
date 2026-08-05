<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (!empty($_SESSION['web_user_id']) && validateVfnWebSession($pdo)) {
        ensureAtcSchema($pdo);
        $pdo->prepare(
            "UPDATE atc_sessions
             SET is_active = 0, disconnected_at = NOW()
             WHERE user_id = :user_id AND is_active = 1"
        )->execute(['user_id' => (int)$_SESSION['web_user_id']]);
    }
    unset($_SESSION['atc_session_id'], $_SESSION['atc_session_token']);
    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}

