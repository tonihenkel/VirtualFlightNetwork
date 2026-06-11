<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';

$token = $_POST["token"] ?? "";
$isInvisible = $_POST["is_invisible"] ?? "0";

$token = trim($token);

$isInvisible =
    (int)$isInvisible === 1
    ? 1
    : 0;

if ($token === "") {
    echo json_encode([
        "success" => false,
        "message" => "Token fehlt."
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
            s.id AS session_id,
            s.user_id,
            s.is_active,
            u.op_permission
         FROM user_sessions s
         INNER JOIN users u
            ON u.id = s.user_id
         WHERE s.token = :token
         LIMIT 1"
    );

    $stmt->execute([
        "token" => $token
    ]);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode([
            "success" => false,
            "message" => "Session nicht gefunden."
        ]);
        exit;
    }

    if ((int)$session["is_active"] !== 1) {
        echo json_encode([
            "success" => false,
            "message" => "Session ist nicht aktiv."
        ]);
        exit;
    }

    $minimumInvisibleLevel =
        (int)($minimumInvisibleOpPermission ?? 3);

    if ((int)$session["op_permission"] < $minimumInvisibleLevel) {
        echo json_encode([
            "success" => false,
            "message" => "Keine Berechtigung fuer Invisible Mode."
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE user_sessions
         SET is_invisible = :is_invisible
         WHERE id = :session_id"
    );

    $stmt->execute([
        "is_invisible" => $isInvisible,
        "session_id" => (int)$session["session_id"]
    ]);

    echo json_encode([
        "success" => true,
        "message" => $isInvisible === 1
            ? "Invisible Mode aktiviert."
            : "Invisible Mode deaktiviert.",
        "is_invisible" => $isInvisible === 1
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Serverfehler."
    ]);
}