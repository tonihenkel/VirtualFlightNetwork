<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/language.php';

try {
    $pdo =
        createAdminPdo();

    requireAdminUser($pdo, 2);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $sort = (string)($_GET['sort'] ?? 'date');
    $direction = strtolower((string)($_GET['direction'] ?? 'desc'));
    $search = trim((string)($_GET['search'] ?? ''));
    if (!in_array($sort, ['target', 'actor', 'type', 'date'], true)) {
        $sort = 'date';
    }
    if (!in_array($direction, ['asc', 'desc'], true)) {
        $direction = $sort === 'date' ? 'desc' : 'asc';
    }
    $activityStmt = $pdo->prepare(
        "SELECT
            l.id,
            l.activity_type,
            l.activity_key,
            l.activity_value,
            l.created_at,
            target.username AS target_username,
            target.real_name AS target_real_name,
            actor.username AS actor_username,
            actor.real_name AS actor_real_name
         FROM user_activity_log l
         LEFT JOIN users target ON target.id = l.user_id
         LEFT JOIN users actor ON actor.id = l.actor_user_id
         WHERE l.activity_key LIKE '%kick%'
            OR l.activity_key LIKE '%ban%'
            OR l.activity_key LIKE '%announcement%'
            OR l.activity_type IN ('staff', 'admin', 'moderation')
         ORDER BY l.created_at DESC"
    );

    $activityStmt->execute();

    $items = [];

    foreach ($activityStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $targetName =
            trim((string)($row['target_real_name'] ?? ''));

        if ($targetName === '') {
            $targetName =
                (string)($row['target_username'] ?? '-');
        }

        $actorName =
            trim((string)($row['actor_real_name'] ?? ''));

        if ($actorName === '') {
            $actorName =
                (string)($row['actor_username'] ?? t('admin_system'));
        }

        $items[] = [
            'id' => 'activity-' . (int)$row['id'],
            'sort_time' => strtotime((string)$row['created_at']),
            'time' => date('d.m.Y H:i', strtotime((string)$row['created_at'])),
            'title' => t((string)$row['activity_key']),
            'sort_type' => (string)$row['activity_type'],
            'target' => $targetName,
            'actor' => $actorName,
            'detail' => (string)($row['activity_value'] ?? '')
        ];
    }

    $announcementStmt = $pdo->prepare(
        "SELECT
            MIN(id) AS id,
            sender_callsign,
            message_text,
            created_at
         FROM chat_messages
         WHERE message_text LIKE '[ANNOUNCEMENT]%'
            OR sender_callsign = 'ANNOUNCEMENT'
         GROUP BY sender_callsign, message_text, created_at
         ORDER BY created_at DESC"
    );

    $announcementStmt->execute();

    foreach ($announcementStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'id' => 'announcement-' . (int)$row['id'],
            'sort_time' => strtotime((string)$row['created_at']),
            'time' => date('d.m.Y H:i', strtotime((string)$row['created_at'])),
            'title' => t('admin_activity_announcement'),
            'sort_type' => 'announcement',
            'target' => t('admin_all_online_pilots'),
            'actor' => (string)($row['sender_callsign'] ?? 'ANNOUNCEMENT'),
            'detail' => preg_replace('/^\[ANNOUNCEMENT\]\s*/', '', (string)$row['message_text'])
        ];
    }

    if ($search !== '') {
        $needle = mb_strtolower($search, 'UTF-8');
        $items = array_values(array_filter(
            $items,
            static function (array $item) use ($needle): bool {
                $haystack = implode(' ', [
                    $item['target'] ?? '',
                    $item['actor'] ?? '',
                    $item['title'] ?? '',
                    $item['sort_type'] ?? '',
                    $item['detail'] ?? '',
                    $item['time'] ?? ''
                ]);
                return mb_strpos(mb_strtolower($haystack, 'UTF-8'), $needle) !== false;
            }
        ));
    }

    usort(
        $items,
        static function (array $a, array $b) use ($sort, $direction): int {
            if ($sort === 'date') {
                $comparison = (int)$a['sort_time'] <=> (int)$b['sort_time'];
            } else {
                $field = $sort === 'type' ? 'title' : $sort;
                $comparison = strnatcasecmp((string)$a[$field], (string)$b[$field]);
            }
            if ($comparison === 0) {
                $comparison = (int)$a['sort_time'] <=> (int)$b['sort_time'];
            }
            return $direction === 'desc' ? -$comparison : $comparison;
        }
    );

    foreach ($items as &$item) {
        unset($item['sort_time']);
        unset($item['sort_type']);
    }
    unset($item);

    $total = count($items);
    echo json_encode([
        'success' => true,
        'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $perPage))
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'server_error'
    ]);
}
