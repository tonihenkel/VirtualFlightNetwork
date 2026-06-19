<?php
header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once '../includes/activity_log.php';
require_once '../includes/award_system.php';

$token = trim($_POST["token"] ?? "");

$callsign = strtoupper(trim($_POST["callsign"] ?? ""));

$flight_rules = strtoupper(trim($_POST["flight_rules"] ?? "I"));
$flight_type = strtoupper(trim($_POST["flight_type"] ?? "G"));

$departure_time = trim($_POST["departure_time"] ?? "");

$departure_airport = strtoupper(trim($_POST["departure_airport"] ?? "ZZZZ"));
$arrival_airport = strtoupper(trim($_POST["arrival_airport"] ?? "ZZZZ"));
$alternate1_airport = strtoupper(trim($_POST["alternate1_airport"] ?? "ZZZZ"));
$alternate2_airport = strtoupper(trim($_POST["alternate2_airport"] ?? "ZZZZ"));

$route_text = trim($_POST["route_text"] ?? "");
$cruising_level = strtoupper(trim($_POST["cruising_level"] ?? ""));
$cruising_speed = strtoupper(trim($_POST["cruising_speed"] ?? ""));
$remarks = trim($_POST["remarks"] ?? "");

if ($token === "") {
    echo json_encode([
        "success" => false,
        "message" => "Kein Token uebergeben."
    ]);
    exit;
}

if ($callsign === "") {
    echo json_encode([
        "success" => false,
        "message" => "Callsign fehlt."
    ]);
    exit;
}

/*
    ICAO Flight Rules:
    I = IFR
    V = VFR
    Y = IFR zuerst, dann VFR
    Z = VFR zuerst, dann IFR
*/
$validFlightRules = ["I", "V", "Y", "Z"];

if (!in_array($flight_rules, $validFlightRules, true)) {
    if ($flight_rules === "IFR") {
        $flight_rules = "I";
    } elseif ($flight_rules === "VFR") {
        $flight_rules = "V";
    } else {
        $flight_rules = "I";
    }
}

/*
    ICAO Flight Type:
    S = Scheduled
    N = Non-Scheduled
    G = General Aviation
    M = Military
    X = Other
*/
$validFlightTypes = ["S", "N", "G", "M", "X"];

if (!in_array($flight_type, $validFlightTypes, true)) {
    $flight_type = "G";
}

if ($departure_airport === "") {
    $departure_airport = "ZZZZ";
}

if ($arrival_airport === "") {
    $arrival_airport = "ZZZZ";
}

if ($alternate1_airport === "") {
    $alternate1_airport = "ZZZZ";
}

if ($alternate2_airport === "") {
    $alternate2_airport = "ZZZZ";
}

/*
    ICAO-Felder auf einfache sichere Zeichen begrenzen.
    ZZZZ ist erlaubt und bedeutet: kein fest definierter Flughafen.
*/
$airportPattern = '/^[A-Z0-9]{3,10}$/';

if (!preg_match($airportPattern, $departure_airport)) {
    $departure_airport = "ZZZZ";
}

if (!preg_match($airportPattern, $arrival_airport)) {
    $arrival_airport = "ZZZZ";
}

if (!preg_match($airportPattern, $alternate1_airport)) {
    $alternate1_airport = "ZZZZ";
}

if (!preg_match($airportPattern, $alternate2_airport)) {
    $alternate2_airport = "ZZZZ";
}

/*
    Textfelder begrenzen, damit nicht versehentlich riesige Datenmengen gespeichert werden.
*/
$departure_time = mb_substr($departure_time, 0, 20, "UTF-8");
$route_text = mb_substr($route_text, 0, 5000, "UTF-8");
$cruising_level = mb_substr($cruising_level, 0, 20, "UTF-8");
$cruising_speed = mb_substr($cruising_speed, 0, 20, "UTF-8");
$remarks = mb_substr($remarks, 0, 2000, "UTF-8");

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
        Session pruefen.
    */
    $stmt = $pdo->prepare(
        "SELECT user_id
         FROM user_sessions
         WHERE token = :token
           AND is_active = 1
         LIMIT 1"
    );

    $stmt->execute([
        "token" => $token
    ]);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode([
            "success" => false,
            "message" => "Ungueltige oder abgelaufene Session."
        ]);
        exit;
    }

    /*
        Flightplan pro Session speichern oder aktualisieren.
    */
    $stmt = $pdo->prepare(
        "INSERT INTO pilot_flightplans
        (
            session_token,
            callsign,
            flight_rules,
            flight_type,
            departure_time,
            departure_airport,
            arrival_airport,
            alternate1_airport,
            alternate2_airport,
            route_text,
            cruising_level,
            cruising_speed,
            remarks
        )
        VALUES
        (
            :session_token,
            :callsign,
            :flight_rules,
            :flight_type,
            :departure_time,
            :departure_airport,
            :arrival_airport,
            :alternate1_airport,
            :alternate2_airport,
            :route_text,
            :cruising_level,
            :cruising_speed,
            :remarks
        )
        ON DUPLICATE KEY UPDATE
            callsign = VALUES(callsign),
            flight_rules = VALUES(flight_rules),
            flight_type = VALUES(flight_type),
            departure_time = VALUES(departure_time),
            departure_airport = VALUES(departure_airport),
            arrival_airport = VALUES(arrival_airport),
            alternate1_airport = VALUES(alternate1_airport),
            alternate2_airport = VALUES(alternate2_airport),
            route_text = VALUES(route_text),
            cruising_level = VALUES(cruising_level),
            cruising_speed = VALUES(cruising_speed),
            remarks = VALUES(remarks),
            updated_at = NOW()"
    );

    $stmt->execute([
        "session_token" => $token,
        "callsign" => $callsign,
        "flight_rules" => $flight_rules,
        "flight_type" => $flight_type,
        "departure_time" => $departure_time,
        "departure_airport" => $departure_airport,
        "arrival_airport" => $arrival_airport,
        "alternate1_airport" => $alternate1_airport,
        "alternate2_airport" => $alternate2_airport,
        "route_text" => $route_text,
        "cruising_level" => $cruising_level,
        "cruising_speed" => $cruising_speed,
        "remarks" => $remarks
    ]);

    $firstFlightStmt = $pdo->prepare(
        "SELECT id
         FROM user_activity_log
         WHERE user_id = :user_id
           AND activity_key = 'activity_first_flight'
         LIMIT 1"
    );

    $firstFlightStmt->execute([
        'user_id' => (int)$session['user_id']
    ]);

    $firstFlight =
        $firstFlightStmt->fetch(PDO::FETCH_ASSOC);

    if (!$firstFlight) {

        logActivity(
            $pdo,
            (int)$session['user_id'],
            'flight',
            'activity_first_flight',
            $departure_airport . ' ? ' . $arrival_airport,
            0
        );

        awardUser(
            $pdo,
            (int)$session['user_id'],
            'award_first_flight'
        );
    }

    echo json_encode([
        "success" => true,
        "message" => "Flightplan gespeichert.",
        "flightplan" => [
            "callsign" => $callsign,
            "flight_rules" => $flight_rules,
            "flight_type" => $flight_type,
            "departure_time" => $departure_time,
            "departure_airport" => $departure_airport,
            "arrival_airport" => $arrival_airport,
            "alternate1_airport" => $alternate1_airport,
            "alternate2_airport" => $alternate2_airport,
            "cruising_level" => $cruising_level,
            "cruising_speed" => $cruising_speed
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Serverfehler.",
        "error" => $e->getMessage()
    ]);
}