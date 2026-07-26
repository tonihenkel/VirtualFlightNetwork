<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/chat_system.php';
require_once __DIR__ . '/../includes/auth_security.php';

try {
    $pdo = createAdminPdo();
    $admin = requireAdminUser($pdo, 2);
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
    $frequency = normalizeChatFrequency((string)($_POST['frequency'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $scope = (string)($_POST['scope'] ?? 'global');
    $referenceUserId = (int)($_POST['reference_user_id'] ?? 0);
    $rangeNm = (float)($_POST['range_nm'] ?? 200);
    if ($frequency === null || $message === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'invalid_data']);
        exit;
    }
    $rateSubject = 'admin:' . (int)$admin['id'];
    if (authRateIsBlocked($pdo, 'admin_chat', $rateSubject)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'rate_limited']);
        exit;
    }
    authRateFail($pdo, 'admin_chat', $rateSubject, 20);

    $senderLatitude = null;
    $senderLongitude = null;
    $deliveryRangeNm = null;

    if ($scope === 'regional') {
        if ($referenceUserId <= 0 || $rangeNm < 10 || $rangeNm > 1000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'invalid_region']);
            exit;
        }

        $referenceStmt = $pdo->prepare(
            "SELECT p.latitude, p.longitude
             FROM pilot_positions p
             INNER JOIN user_sessions s
                ON s.user_id = p.user_id
               AND s.is_active = 1
             WHERE p.user_id = :user_id
               AND p.last_update >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
               AND (p.com1 = :frequency OR p.com2 = :frequency)
             LIMIT 1"
        );
        $referenceStmt->execute([
            'user_id' => $referenceUserId,
            'frequency' => $frequency
        ]);
        $reference = $referenceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$reference) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'reference_not_available']);
            exit;
        }
        $senderLatitude = (float)$reference['latitude'];
        $senderLongitude = (float)$reference['longitude'];
        $deliveryRangeNm = $rangeNm;
    }

    $sentMessage = insertChatMessage(
        $pdo,
        $frequency,
        null,
        (int)$admin['id'],
        'STAFF:' . strtoupper((string)$admin['username']),
        'pilot',
        mb_substr($message, 0, 255),
        $senderLatitude,
        $senderLongitude,
        $deliveryRangeNm
    );

    $messageId =
        (int)$pdo->lastInsertId();

    $messageStmt = $pdo->prepare(
        "SELECT original_message_text, was_filtered, created_at
         FROM chat_messages
         WHERE id = :message_id
         LIMIT 1"
    );
    $messageStmt->execute([
        'message_id' => $messageId
    ]);
    $storedMessage =
        $messageStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'message' => [
            'id' => $messageId,
            'time' => date('H:i:s'),
            'date_time' => date('d.m.Y H:i:s'),
            'frequency' => $frequency,
            'sender_user_id' => (int)$admin['id'],
            'sender' => 'STAFF:' . strtoupper((string)$admin['username']),
            'type' => 'pilot',
            'text' => $sentMessage,
            'original_text' => (string)($storedMessage['original_message_text'] ?? ''),
            'was_filtered' => (int)($storedMessage['was_filtered'] ?? 0) === 1
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
