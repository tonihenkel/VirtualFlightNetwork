<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once '../includes/ratings.php';
require_once '../includes/ban_status.php';

$username = $_POST["username"] ?? "";
$password = $_POST["password"] ?? "";
$callsign = $_POST["callsign"] ?? "";
$pluginVersion = trim((string)($_POST["plugin_version"] ?? ""));
$pluginLanguage = strtolower(trim((string)($_POST["plugin_language"] ?? "")));
$pluginLanguage = in_array($pluginLanguage, ["de", "en"], true)
    ? $pluginLanguage
    : "en";
$spectatorMode = (string)($_POST["spectator"] ?? "0") === "1";

$username = trim($username);
$callsign = strtoupper(trim($callsign));

if (
    $pluginVersion === ''
    || !hash_equals((string)$requiredPluginVersion, $pluginVersion)
) {
    echo json_encode([
        "success" => false,
        "update_required" => true,
        "required_plugin_version" => (string)$requiredPluginVersion,
        "client_plugin_version" => $pluginVersion,
        "message" =>
            "Plugin-Update erforderlich. Benoetigte Version: "
            . (string)$requiredPluginVersion
            . ($pluginVersion !== '' ? " (installiert: " . $pluginVersion . ")" : "")
    ]);
    exit;
}

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

    $languageColumn = $pdo->query(
        "SHOW COLUMNS FROM user_sessions LIKE 'plugin_language'"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$languageColumn) {
        $pdo->exec(
            "ALTER TABLE user_sessions
             ADD COLUMN plugin_language VARCHAR(2) NOT NULL DEFAULT 'en'"
        );
    }

    $stmt = $pdo->prepare(
        "SELECT
            id,
            username,
            email,
            real_name,
            password_hash,
            is_active,
            email_verified,
            is_banned,
            ban_reason,
            ban_expires_at,
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

    if (
        !empty($maintenanceMode)
        && (int)$user["op_permission"] < 5
    ) {
        echo json_encode([
            "success" => false,
            "maintenance_mode" => true,
            "message" =>
                "Das VFN-Netzwerk befindet sich im Wartungsmodus. "
                . "Der Login ist derzeit nur fuer OP-Level 5 moeglich."
        ]);
        exit;
    }

    $banStatus = getActiveBanStatus($pdo, (int)$user["id"]);
    if ($banStatus['active']) {
        $banMessage = "Account gebannt: " . $banStatus['reason'];
        if ($banStatus['expires_at']) {
            $banMessage .= " (bis " . date('d.m.Y H:i', strtotime($banStatus['expires_at'])) . ")";
        }
        echo json_encode(["success" => false, "message" => $banMessage]);
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
        (int)($minimumInvisibleOpPermission ?? 2);

    $canUseInvisible =
        ((int)$user["op_permission"] >= $minimumInvisibleLevel);

    $token = bin2hex(random_bytes(32));

    $pdo->prepare(
        "UPDATE pilot_flights
         SET status = 'aborted', completed_at = NOW()
         WHERE user_id = :user_id AND status = 'active'"
    )->execute(['user_id' => (int)$user['id']]);

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
                is_invisible,
                is_spectator,
                plugin_language
            )
         VALUES
            (
                :user_id,
                :token,
                :callsign,
                1,
                0,
                :is_spectator,
                :plugin_language
            )"
    );

    $stmt->execute([
        "user_id" => (int)$user["id"],
        "token" => $token,
        "callsign" => $callsign,
        "is_spectator" => $spectatorMode ? 1 : 0,
        "plugin_language" => $pluginLanguage
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
        "is_spectator" => $spectatorMode,
        "callsign" => $callsign,
        "token" => $token
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Serverfehler."
    ]);
}
