<?php

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/send_mail.php';

try {
    $pdo = createAdminPdo();
    $admin = requireAdminUser($pdo, 4);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            empty($_POST['csrf'])
            || empty($_SESSION['admin_csrf'])
            || !hash_equals((string)$_SESSION['admin_csrf'], (string)$_POST['csrf'])
        ) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'invalid_csrf']);
            exit;
        }

        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $reviewReason = trim((string)($_POST['review_reason'] ?? ''));
        if (
            $requestId <= 0
            || !in_array($action, ['approve', 'reject'], true)
            || $reviewReason === ''
            || mb_strlen($reviewReason) > 255
        ) {
            throw new InvalidArgumentException('invalid_request');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT r.id, r.user_id, u.op_permission, u.email, u.username, u.real_name
             FROM ban_appeal_requests r
             JOIN users u ON u.id = r.user_id
             WHERE r.id = :id AND r.status = 'pending'
             FOR UPDATE"
        );
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new RuntimeException('not_pending');
        }
        if ((int)$request['op_permission'] >= (int)$admin['op_permission']) {
            throw new RuntimeException('hierarchy_denied');
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';
        $pdo->prepare(
            "UPDATE ban_appeal_requests
             SET status = :status, reviewed_by = :reviewed_by,
                 review_reason = :review_reason, reviewed_at = NOW()
             WHERE id = :id"
        )->execute([
            'status' => $status,
            'reviewed_by' => $admin['id'],
            'review_reason' => $reviewReason,
            'id' => $requestId
        ]);

        if ($action === 'approve') {
            $pdo->prepare(
                "UPDATE users
                 SET is_banned = 0, ban_reason = NULL, ban_expires_at = NULL,
                     banned_at = NULL, banned_by_user_id = NULL
                 WHERE id = :user_id"
            )->execute(['user_id' => $request['user_id']]);
        }

        logActivity(
            $pdo,
            (int)$request['user_id'],
            $action === 'approve' ? 'unban' : 'ban_appeal_rejected',
            $action === 'approve'
                ? 'activity_ban_appeal_approved'
                : 'activity_ban_appeal_rejected',
            $reviewReason,
            (int)$admin['id']
        );
        $pdo->commit();

        $approved = $action === 'approve';
        $mailSubject = $approved
            ? 'VFN Entbannungsantrag genehmigt / Ban appeal approved'
            : 'VFN Entbannungsantrag abgelehnt / Ban appeal rejected';
        $mailTitle = $approved
            ? 'Dein Entbannungsantrag wurde genehmigt.'
            : 'Dein Entbannungsantrag wurde abgelehnt.';
        $mailTitleEn = $approved
            ? 'Your ban appeal was approved.'
            : 'Your ban appeal was rejected.';
        $mailBody =
            '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#172033;">'
            . '<h2>Virtual Flight Network</h2>'
            . '<p><strong>' . htmlspecialchars($mailTitle) . '</strong></p>'
            . '<p>Begründung des Moderationsteams: '
            . htmlspecialchars($reviewReason) . '</p><hr>'
            . '<p><strong>' . htmlspecialchars($mailTitleEn) . '</strong></p>'
            . '<p>Moderation team reason: ' . htmlspecialchars($reviewReason) . '</p>'
            . '</div>';
        try {
            sendMail(
                (string)$request['email'],
                (string)($request['real_name'] ?: $request['username']),
                $mailSubject,
                $mailBody
            );
        } catch (Throwable $mailError) {
            error_log('Ban appeal decision mail failed: ' . $mailError->getMessage());
        }

        echo json_encode(['success' => true]);
        exit;
    }

    $items = $pdo->query(
        "SELECT r.id, r.user_id, r.reason, r.created_at,
                u.username, u.real_name, u.email, u.ban_reason, u.ban_expires_at
         FROM ban_appeal_requests r
         JOIN users u ON u.id = r.user_id
         WHERE r.status = 'pending'
         ORDER BY r.created_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'items' => array_map(static function (array $item): array {
        return [
            'id' => (int)$item['id'],
            'user_id' => (int)$item['user_id'],
            'username' => (string)$item['username'],
            'real_name' => (string)($item['real_name'] ?? ''),
            'email' => (string)$item['email'],
            'ban_reason' => (string)($item['ban_reason'] ?? ''),
            'ban_expires_at' => $item['ban_expires_at']
                ? date('d.m.Y H:i', strtotime($item['ban_expires_at']))
                : null,
            'appeal_reason' => (string)$item['reason'],
            'created_at' => date('d.m.Y H:i', strtotime($item['created_at']))
        ];
    }, $items)]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
