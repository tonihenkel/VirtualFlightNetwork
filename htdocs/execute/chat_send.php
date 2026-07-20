<?php
header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once '../includes/chat_system.php';

$token =
    trim($_POST['token'] ?? '');

$callsign =
    strtoupper(trim($_POST['callsign'] ?? ''));

$frequency =
    normalizeChatFrequency($_POST['frequency'] ?? null);

$message =
    trim((string)($_POST['message'] ?? ''));

if ($token === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Kein Token uebergeben.'
    ]);
    exit;
}

if ($callsign === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Callsign fehlt.'
    ]);
    exit;
}

if ($frequency === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Ungueltige Frequenz.'
    ]);
    exit;
}

if ($message === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Nachricht fehlt.'
    ]);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $stmt = $pdo->prepare(
        "SELECT
            s.user_id
         FROM user_sessions s
         WHERE s.token = :token
           AND s.is_active = 1
         LIMIT 1"
    );

    $stmt->execute([
        'token' => $token
    ]);

    $session =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode([
            'success' => false,
            'message' => 'Ungueltige oder abgelaufene Session.'
        ]);
        exit;
    }

    $positionStmt = $pdo->prepare(
        "SELECT
            latitude,
            longitude
         FROM pilot_positions
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $positionStmt->execute([
        'user_id' =>
            (int)$session['user_id']
    ]);

    $position =
        $positionStmt->fetch(PDO::FETCH_ASSOC);

    $senderLatitude =
        $position ? (float)$position['latitude'] : null;

    $senderLongitude =
        $position ? (float)$position['longitude'] : null;

    insertChatMessage(
        $pdo,
        $frequency,
        null,
        (int)$session['user_id'],
        $callsign,
        'pilot',
        $message,
        $senderLatitude,
        $senderLongitude
    );

    echo json_encode([
        'success' => true,
        'message' => 'Nachricht gesendet.'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Serverfehler.',
        'error' => $e->getMessage()
    ]);
}
