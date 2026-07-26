<?php

header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

$token = trim((string)($_POST['token'] ?? ''));
if ($token === '') {
    echo json_encode(['success' => false, 'message' => 'Kein Token uebergeben.']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->beginTransaction();
    $pdo->prepare(
        "UPDATE pilot_flights
         SET status = 'aborted', completed_at = NOW()
         WHERE session_token = :token AND status = 'active'"
    )->execute(['token' => $token]);
    $pdo->prepare(
        "DELETE FROM pilot_tracks WHERE session_token = :token"
    )->execute(['token' => $token]);
    $pdo->prepare(
        "DELETE FROM pilot_positions WHERE session_token = :token"
    )->execute(['token' => $token]);
    $pdo->prepare(
        "UPDATE user_sessions
         SET is_active = 0, last_seen = NOW()
         WHERE token = :token
         LIMIT 1"
    )->execute(['token' => $token]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Logout erfolgreich.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Serverfehler.']);
}

