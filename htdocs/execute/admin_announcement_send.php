<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/chat_system.php';

try {
    $pdo = createAdminPdo();
    $admin = requireAdminUser($pdo, 5);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'method_not_allowed']);
        exit;
    }

    if (
        empty($_POST['csrf'])
        || empty($_SESSION['admin_csrf'])
        || !hash_equals((string)$_SESSION['admin_csrf'], (string)$_POST['csrf'])
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'invalid_csrf']);
        exit;
    }

    $message =
        trim((string)($_POST['message'] ?? ''));

    if ($message === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'invalid_message']);
        exit;
    }

    $message =
        mb_substr($message, 0, 220);

    $onlineStmt = $pdo->query(
        "SELECT DISTINCT user_id
         FROM user_sessions
         WHERE is_active = 1"
    );

    $onlineUserIds =
        array_map('intval', $onlineStmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($onlineUserIds)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'no_online_users']);
        exit;
    }

    $pdo->beginTransaction();

    foreach ($onlineUserIds as $onlineUserId) {
        insertChatMessage(
            $pdo,
            null,
            $onlineUserId,
            (int)$admin['id'],
            'ANNOUNCEMENT',
            'system',
            '[ANNOUNCEMENT] ' . $message
        );
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'announcement_sent',
        'recipient_count' => count($onlineUserIds),
        'announcement' => $message
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
