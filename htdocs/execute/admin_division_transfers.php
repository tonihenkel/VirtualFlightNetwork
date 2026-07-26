<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/activity_log.php';

try {
    $pdo = createAdminPdo();
    $admin = requireAdminUser($pdo, 2);

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
        $requestId = max(0, (int)($_POST['request_id'] ?? 0));
        $action = (string)($_POST['action'] ?? '');
        if ($requestId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('invalid_request');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT id, user_id, current_division_code, requested_division_code
             FROM division_transfer_requests
             WHERE id = :id AND status = 'pending'
             FOR UPDATE"
        );
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'not_pending']);
            exit;
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';
        if ($action === 'approve') {
            $pdo->prepare(
                "UPDATE users SET division_code = :division, updated_at = NOW()
                 WHERE id = :user_id"
            )->execute([
                'division' => $request['requested_division_code'],
                'user_id' => $request['user_id']
            ]);
        }
        $pdo->prepare(
            "UPDATE division_transfer_requests
             SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW()
             WHERE id = :id"
        )->execute([
            'status' => $status,
            'reviewed_by' => $admin['id'],
            'id' => $requestId
        ]);

        logActivity(
            $pdo,
            (int)$request['user_id'],
            $action === 'approve' ? 'division_changed' : 'division_change_rejected',
            $action === 'approve'
                ? 'activity_division_change_approved'
                : 'activity_division_change_rejected',
            $request['current_division_code'] . ' -> ' . $request['requested_division_code'],
            (int)$admin['id']
        );
        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    }

    $items = $pdo->query(
        "SELECT r.id, r.user_id, r.current_division_code,
                r.requested_division_code, r.reason, r.created_at,
                u.username, u.real_name, u.email
         FROM division_transfer_requests r
         JOIN users u ON u.id = r.user_id
         WHERE r.status = 'pending'
         ORDER BY r.created_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'items' => array_map(static function (array $item): array {
            return [
                'id' => (int)$item['id'],
                'user_id' => (int)$item['user_id'],
                'username' => (string)$item['username'],
                'real_name' => (string)($item['real_name'] ?? ''),
                'email' => (string)($item['email'] ?? ''),
                'current_division' => (string)$item['current_division_code'],
                'requested_division' => (string)$item['requested_division_code'],
                'reason' => (string)$item['reason'],
                'created_at' => date('d.m.Y H:i', strtotime($item['created_at']))
            ];
        }, $items)
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
