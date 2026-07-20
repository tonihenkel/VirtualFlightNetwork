<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once '../includes/ratings.php';

$username = $_POST["username"] ?? "";
$password = $_POST["password"] ?? "";
$callsign = $_POST["callsign"] ?? "";

$username = trim($username);
$callsign = strtoupper(trim($callsign));

if ($username === "" || $password === "" || $callsign === "") {
    echo json_encode([
        "success" => false,
        "message" => "Benutzername, Passwort und Callsign erforderlich."
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
            id,
            username,
            email,
            real_name,
            password_hash,
            is_active,
            email_verified,
            rating_pilot,
            rating_atc,
            op_permission
         FROM users
         WHERE username = :username
            OR email = :username
         LIMIT 1"
    );

    $stmt->execute([
        "username" => $username
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            "success" => false,
            "message" => "Benutzer nicht gefunden."
        ]);
        exit;
    }

    if (!password_verify($password, $user["password_hash"])) {
        echo json_encode([
            "success" => false,
            "message" => "Passwort falsch."
        ]);
        exit;
    }

    if ((int)$user["is_active"] !== 1) {
        echo json_encode([
            "success" => false,
            "message" => "Benutzer ist deaktiviert."
        ]);
        exit;
    }

    if ((int)$user["email_verified"] !== 1) {
        echo json_encode([
            "success" => false,
            "message" => "Bitte bestaetige zuerst deine E-Mail-Adresse."
        ]);
        exit;
    }

    $minimumInvisibleLevel =
        (int)($minimumInvisibleOpPermission ?? 3);

    $canUseInvisible =
        ((int)$user["op_permission"] >= $minimumInvisibleLevel);

    $token = bin2hex(random_bytes(32));

    $pilotRating =
        getPilotRating((int)($user["rating_pilot"] ?? 0));

    $atcRating =
        getAtcRating((int)($user["rating_atc"] ?? 0));

    $stmt = $pdo->prepare(
        "INSERT INTO user_sessions
            (
                user_id,
                token,
                callsign,
                is_active,
                is_invisible
            )
         VALUES
            (
                :user_id,
                :token,
                :callsign,
                1,
                0
            )"
    );

    $stmt->execute([
        "user_id" => (int)$user["id"],
        "token" => $token,
        "callsign" => $callsign
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Login erfolgreich.",
        "user_id" => (int)$user["id"],
        "username" => $user["username"],
        "real_name" => $user["real_name"],
        "email" => $user["email"],
        "pilot_rating" => (int)($user["rating_pilot"] ?? 0),
        "pilot_rating_code" => $pilotRating["code"],
        "pilot_rating_name" => $pilotRating["name"],
        "atc_rating" => (int)($user["rating_atc"] ?? 0),
        "atc_rating_code" => $atcRating["code"],
        "atc_rating_name" => $atcRating["name"],
        "op_permission" => (int)$user["op_permission"],
        "can_use_invisible" => $canUseInvisible,
        "is_invisible" => false,
        "callsign" => $callsign,
        "token" => $token
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Serverfehler."
    ]);
}
