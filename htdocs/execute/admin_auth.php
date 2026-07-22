<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

function requireAdminUser(PDO $pdo, int $minimumOpPermission = 2): array
{
    if (empty($_SESSION['web_user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'login_required'
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT id, username, real_name, op_permission
         FROM users
         WHERE id = :user_id
         LIMIT 1"
    );

    $stmt->execute([
        'user_id' => (int)$_SESSION['web_user_id']
    ]);

    $user =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int)$user['op_permission'] < $minimumOpPermission) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'access_denied'
        ]);
        exit;
    }

    $_SESSION['web_op_permission'] =
        (int)$user['op_permission'];

    return $user;
}

function createAdminPdo(): PDO
{
    global $dbHost, $dbName, $dbUser, $dbPass;

    return new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );
}
