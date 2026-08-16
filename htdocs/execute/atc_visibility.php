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
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        http_response_code(401);
        throw new RuntimeException('login_required');
    }
    ensureAtcSchema($pdo);
    $userStmt = $pdo->prepare('SELECT op_permission FROM users WHERE id=:id LIMIT 1');
    $userStmt->execute(['id' => (int)$_SESSION['web_user_id']]);
    if ((int)$userStmt->fetchColumn() < 1) {
        http_response_code(403);
        throw new RuntimeException('permission_denied');
    }
    $token = (string)($_SESSION['atc_session_token'] ?? '');
    if ($token === '') {
        http_response_code(409);
        throw new RuntimeException('atc_session_inactive');
    }
    $invisible = (string)($_POST['invisible'] ?? '0') === '1' ? 1 : 0;
    $update = $pdo->prepare(
        'UPDATE atc_sessions SET is_invisible=:invisible, last_seen_at=NOW()
         WHERE user_id=:user_id AND session_token=:token AND is_active=1'
    );
    $update->execute([
        'invisible' => $invisible,
        'user_id' => (int)$_SESSION['web_user_id'],
        'token' => $token,
    ]);
    if ($update->rowCount() < 1) {
        $check = $pdo->prepare(
            'SELECT 1 FROM atc_sessions
             WHERE user_id=:user_id AND session_token=:token AND is_active=1 LIMIT 1'
        );
        $check->execute(['user_id'=>(int)$_SESSION['web_user_id'], 'token'=>$token]);
        if (!$check->fetchColumn()) {
            http_response_code(409);
            throw new RuntimeException('atc_session_inactive');
        }
    }
    echo json_encode(['success'=>true, 'is_invisible'=>$invisible], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>$error->getMessage()], JSON_UNESCAPED_UNICODE);
}
