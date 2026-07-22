<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/chat_system.php';

try {
    $pdo =
        createAdminPdo();

    $adminUser =
        requireAdminUser($pdo, 2);

    $sinceId =
        max(0, (int)($_GET['since_id'] ?? 0));

    $allRequested =
        !empty($_GET['all']);

    $canViewAll =
        (int)$adminUser['op_permission'] > 3;

    $viewAll =
        $allRequested && $canViewAll;

    $frequencies = [];
    $frequencyInput =
        (string)($_GET['frequencies'] ?? '');

    foreach (explode(',', $frequencyInput) as $rawFrequency) {
        $frequency =
            normalizeChatFrequency($rawFrequency);

        if ($frequency !== null && !in_array($frequency, $frequencies, true)) {
            $frequencies[] =
                $frequency;
        }
    }

    if (!$viewAll && empty($frequencies)) {
        echo json_encode([
            'success' => true,
            'messages' => []
        ]);
        exit;
    }

    $params = [];
    $where = [
        'frequency IS NOT NULL'
    ];

    if ($sinceId > 0) {
        $where[] =
            'id > :since_id';
        $params['since_id'] =
            $sinceId;
    }

    if (!$viewAll) {
        $placeholders = [];

        foreach ($frequencies as $index => $frequency) {
            $key =
                'frequency_' . $index;

            $placeholders[] =
                ':' . $key;

            $params[$key] =
                $frequency;
        }

        $where[] =
            'frequency IN (' . implode(',', $placeholders) . ')';
    }

    $order =
        $sinceId > 0
            ? 'ASC'
            : 'DESC';

    $stmt = $pdo->prepare(
        "SELECT
            id,
            frequency,
            sender_callsign,
            message_type,
            message_text,
            created_at
         FROM chat_messages
         WHERE " . implode(' AND ', $where) . "
         ORDER BY id $order
         LIMIT 120"
    );

    $stmt->execute($params);

    $messages =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($sinceId <= 0) {
        $messages =
            array_reverse($messages);
    }

    echo json_encode([
        'success' => true,
        'messages' => array_map(
            static function (array $message): array {
                return [
                    'id' => (int)$message['id'],
                    'time' => date('H:i:s', strtotime((string)$message['created_at'])),
                    'frequency' => (string)$message['frequency'],
                    'sender' => (string)$message['sender_callsign'],
                    'type' => (string)$message['message_type'],
                    'text' => (string)$message['message_text']
                ];
            },
            $messages
        )
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'server_error'
    ]);
}
