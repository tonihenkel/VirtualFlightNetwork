<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/language.php';

try {
    $pdo =
        createAdminPdo();

    requireAdminUser($pdo, 2);

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
         ORDER BY l.created_at DESC
         LIMIT 80"
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
            'target' => $targetName,
            'actor' => $actorName,
            'detail' => (string)($row['activity_value'] ?? '')
        ];
    }

    $announcementStmt = $pdo->prepare(
        "SELECT
            id,
            sender_callsign,
            message_text,
            created_at
         FROM chat_messages
         WHERE message_text LIKE '[ANNOUNCEMENT]%'
            OR sender_callsign = 'ANNOUNCEMENT'
         ORDER BY created_at DESC
         LIMIT 80"
    );

    $announcementStmt->execute();

    foreach ($announcementStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'id' => 'announcement-' . (int)$row['id'],
            'sort_time' => strtotime((string)$row['created_at']),
            'time' => date('d.m.Y H:i', strtotime((string)$row['created_at'])),
            'title' => t('admin_activity_announcement'),
            'target' => t('admin_all_online_pilots'),
            'actor' => (string)($row['sender_callsign'] ?? 'ANNOUNCEMENT'),
            'detail' => preg_replace('/^\[ANNOUNCEMENT\]\s*/', '', (string)$row['message_text'])
        ];
    }

    usort(
        $items,
        static function (array $a, array $b): int {
            return (int)$b['sort_time'] <=> (int)$a['sort_time'];
        }
    );

    foreach ($items as &$item) {
        unset($item['sort_time']);
    }
    unset($item);

    echo json_encode([
        'success' => true,
        'items' => array_slice($items, 0, 120)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'server_error'
    ]);
}
