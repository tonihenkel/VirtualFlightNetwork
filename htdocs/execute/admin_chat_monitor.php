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
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));

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
        'm.frequency IS NOT NULL'
    ];

    $search =
        trim((string)($_GET['search'] ?? ''));
    $userFilter =
        trim((string)($_GET['user'] ?? ''));
    $typeFilter =
        trim((string)($_GET['type'] ?? ''));
    $frequencyFilter =
        normalizeChatFrequency((string)($_GET['frequency'] ?? ''));
    $dateFrom =
        trim((string)($_GET['date_from'] ?? ''));
    $dateTo =
        trim((string)($_GET['date_to'] ?? ''));

    if ($search !== '') {
        $where[] =
            '(m.message_text LIKE :search
              OR m.original_message_text LIKE :search
              OR m.sender_callsign LIKE :search)';
        $params['search'] =
            '%' . mb_substr($search, 0, 120) . '%';
    }

    if ($userFilter !== '') {
        $where[] =
            '(m.sender_callsign LIKE :user_filter
              OR u.username LIKE :user_filter
              OR u.email LIKE :user_filter
              OR u.real_name LIKE :user_filter)';
        $params['user_filter'] =
            '%' . mb_substr($userFilter, 0, 190) . '%';
    }

    if ($typeFilter !== '') {
        $where[] =
            'm.message_type = :type_filter';
        $params['type_filter'] =
            mb_substr($typeFilter, 0, 40);
    }

    if ($frequencyFilter !== null) {
        $where[] = 'm.frequency = :frequency_filter';
        $params['frequency_filter'] = $frequencyFilter;
    }

    if ($dateFrom !== '') {
        $timestamp = strtotime($dateFrom);
        if ($timestamp !== false) {
            $where[] = 'm.created_at >= :date_from';
            $params['date_from'] = date('Y-m-d H:i:s', $timestamp);
        }
    }

    if ($dateTo !== '') {
        $timestamp = strtotime($dateTo);
        if ($timestamp !== false) {
            $where[] = 'm.created_at <= :date_to';
            $params['date_to'] = date('Y-m-d H:i:s', $timestamp);
        }
    }

    if ($sinceId > 0) {
        $where[] =
            'm.id > :since_id';
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
            'm.frequency IN (' . implode(',', $placeholders) . ')';
    }

    $order =
        $sinceId > 0
            ? 'ASC'
            : 'DESC';

    $total = 0;
    if ($sinceId <= 0) {
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM chat_messages m
             LEFT JOIN users u ON u.id = m.sender_user_id
             WHERE " . implode(' AND ', $where)
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
    }
    $limit = $sinceId > 0 ? 250 : $perPage;
    $offset = $sinceId > 0 ? 0 : (($page - 1) * $perPage);
    $stmt = $pdo->prepare(
        "SELECT
            m.id,
            m.frequency,
            m.sender_user_id,
            m.sender_callsign,
            m.message_type,
            m.message_text,
            m.original_message_text,
            m.was_filtered,
            m.created_at
         FROM chat_messages m
         LEFT JOIN users u ON u.id = m.sender_user_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY m.id $order
         LIMIT $limit OFFSET $offset"
    );

    $stmt->execute($params);

    $messages =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($sinceId <= 0) {
        $messages =
            array_reverse($messages);
    }

    // Apply the current filter list when monitoring historic messages too.
    // This makes newly added filter terms visible without rewriting history.
    foreach ($messages as &$message) {
        $sourceText = trim((string)($message['original_message_text'] ?? ''));
        if ($sourceText === '') {
            $sourceText = (string)$message['message_text'];
        }
        $currentFilter = filterChatMessage($sourceText, $pdo);
        if ($currentFilter['was_filtered']) {
            $message['original_message_text'] = $currentFilter['original'];
            $message['message_text'] = $currentFilter['filtered'];
            $message['was_filtered'] = 1;
        }
    }
    unset($message);

    echo json_encode([
        'success' => true,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $perPage))
        ],
        'messages' => array_map(
            static function (array $message): array {
                return [
                    'id' => (int)$message['id'],
                    'time' => date('H:i:s', strtotime((string)$message['created_at'])),
                    'date_time' => date('d.m.Y H:i:s', strtotime((string)$message['created_at'])),
                    'frequency' => (string)$message['frequency'],
                    'sender_user_id' => (int)($message['sender_user_id'] ?? 0),
                    'sender' => (string)$message['sender_callsign'],
                    'type' => (string)$message['message_type'],
                    'text' => (string)$message['message_text'],
                    'original_text' => (string)($message['original_message_text'] ?? ''),
                    'was_filtered' => (int)($message['was_filtered'] ?? 0) === 1
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
