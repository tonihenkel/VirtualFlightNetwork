<?php
header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';

$token = $_POST["token"] ?? "";

$token = trim($token);

if ($token === "") {
    echo json_encode([
        "success" => false,
        "message" => "Kein Token uebergeben."
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

    /*
        Trackdaten dieses Piloten löschen,
        damit nach Logout kein Datenmüll bleibt.
    */
    $stmt = $pdo->prepare(
        "DELETE FROM pilot_tracks
         WHERE session_token = :token"
    );

    $stmt->execute([
        "token" => $token
    ]);

    /*
        Session deaktivieren.
    */
    $stmt = $pdo->prepare(
        "UPDATE user_sessions
         SET is_active = 0, last_seen = NOW()
         WHERE token = :token
         LIMIT 1"
    );

    $stmt->execute([
        "token" => $token
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Logout erfolgreich."
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Serverfehler.",
        "error" => $e->getMessage()
    ]);
}