<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/admin_auth.php';

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

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            $word = mb_strtolower(trim((string)($_POST['word'] ?? '')));
            if (
                $word === ''
                || mb_strlen($word) > 60
                || !preg_match("/^[\p{L}\p{N}][\p{L}\p{N}\s'’-]*$/u", $word)
            ) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'invalid_word']);
                exit;
            }
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO chat_filter_words (word, created_by)
                 VALUES (:word, :created_by)"
            );
            $stmt->execute(['word' => $word, 'created_by' => $admin['id']]);
            if ($stmt->rowCount() === 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'word_exists']);
                exit;
            }
        } elseif ($action === 'remove') {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $stmt = $pdo->prepare("DELETE FROM chat_filter_words WHERE id = :id");
            $stmt->execute(['id' => $id]);
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'invalid_action']);
            exit;
        }
    }

    $items = $pdo->query(
        "SELECT w.id, w.word, w.created_at, u.username AS created_by
         FROM chat_filter_words w
         LEFT JOIN users u ON u.id = w.created_by
         ORDER BY w.word ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'items' => array_map(static function (array $item): array {
            return [
                'id' => (int)$item['id'],
                'word' => (string)$item['word'],
                'created_by' => (string)($item['created_by'] ?? ''),
                'created_at' => date('d.m.Y H:i', strtotime($item['created_at']))
            ];
        }, $items)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'server_error']);
}
