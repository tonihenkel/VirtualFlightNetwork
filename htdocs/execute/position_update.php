<?php
header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once 'aircraft_types.php';

$token = trim($_POST["token"] ?? "");

$callsign = strtoupper(trim($_POST["callsign"] ?? ""));
$aircraft_icao = strtoupper(trim($_POST["aircraft_icao"] ?? ""));

if ($aircraft_icao === "") {
    $aircraft_icao = "UNKNOWN";
}

$aircraft_category = getAircraftCategory($aircraft_icao);

$latitude = $_POST["latitude"] ?? null;
$longitude = $_POST["longitude"] ?? null;
$altitude = $_POST["altitude"] ?? null;
$heading = $_POST["heading"] ?? null;
$airspeed = $_POST["airspeed"] ?? null;
$pitch = $_POST["pitch"] ?? null;
$roll = $_POST["roll"] ?? null;
$vertical_speed = $_POST["vertical_speed"] ?? null;

$com1 = $_POST["com1"] ?? 0;
$com2 = $_POST["com2"] ?? 0;
$com3 = $_POST["com3"] ?? 0;

$transponder = trim($_POST["transponder"] ?? "0000");

if ($transponder === "") {
    $transponder = "0000";
}

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



function calculateDistanceNm(
    float $lat1,
    float $lon1,
    float $lat2,
    float $lon2
): float
{
    $earthRadiusKm = 6371.0;

    $dLat =
        deg2rad($lat2 - $lat1);

    $dLon =
        deg2rad($lon2 - $lon1);

    $a =
        sin($dLat / 2) * sin($dLat / 2)
        +
        cos(deg2rad($lat1))
        *
        cos(deg2rad($lat2))
        *
        sin($dLon / 2)
        *
        sin($dLon / 2);

    $c =
        2 *
        atan2(
            sqrt($a),
            sqrt(1 - $a)
        );

    $distanceKm =
        $earthRadiusKm * $c;

    return $distanceKm * 0.539957;
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
        "SELECT s.user_id, u.username
         FROM user_sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.token = :token
           AND s.is_active = 1
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

    $previousPosition = null;

    $positionStmt = $pdo->prepare(
        "SELECT
            latitude,
            longitude,
            last_update
         FROM pilot_positions
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $positionStmt->execute([
        "user_id" =>
            (int)$session["user_id"]
    ]);

    $previousPosition =
        $positionStmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "UPDATE user_sessions
         SET last_seen = NOW(),
             callsign = :callsign
         WHERE token = :token
         LIMIT 1"
    );

    $stmt->execute([
        "callsign" => $callsign,
        "token" => $token
    ]);

    $stmt = $pdo->prepare(
        "INSERT INTO pilot_positions
        (
            user_id,
            session_token,
            username,
            callsign,
            aircraft_icao,
            aircraft_category,
            latitude,
            longitude,
            altitude,
            heading,
            airspeed,
            pitch,
            roll_angle,
            vertical_speed,
            com1,
            com2,
            com3,
            transponder
        )
        VALUES
        (
            :user_id,
            :session_token,
            :username,
            :callsign,
            :aircraft_icao,
            :aircraft_category,
            :latitude,
            :longitude,
            :altitude,
            :heading,
            :airspeed,
            :pitch,
            :roll_angle,
            :vertical_speed,
            :com1,
            :com2,
            :com3,
            :transponder
        )
        ON DUPLICATE KEY UPDATE
            session_token = VALUES(session_token),
            username = VALUES(username),
            callsign = VALUES(callsign),
            aircraft_icao = VALUES(aircraft_icao),
            aircraft_category = VALUES(aircraft_category),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            altitude = VALUES(altitude),
            heading = VALUES(heading),
            airspeed = VALUES(airspeed),
            pitch = VALUES(pitch),
            roll_angle = VALUES(roll_angle),
            vertical_speed = VALUES(vertical_speed),
            com1 = VALUES(com1),
            com2 = VALUES(com2),
            com3 = VALUES(com3),
            transponder = VALUES(transponder),
            last_update = NOW()"
    );

    $stmt->execute([
        "user_id" => (int)$session["user_id"],
        "session_token" => $token,
        "username" => $session["username"],
        "callsign" => $callsign,
        "aircraft_icao" => $aircraft_icao,
        "aircraft_category" => $aircraft_category,
        "latitude" => (float)$latitude,
        "longitude" => (float)$longitude,
        "altitude" => (float)$altitude,
        "heading" => (float)$heading,
        "airspeed" => (float)$airspeed,
        "pitch" => (float)$pitch,
        "roll_angle" => (float)$roll,
        "vertical_speed" => (float)$vertical_speed,
        "com1" => (float)$com1,
        "com2" => (float)$com2,
        "com3" => (float)$com3,
        "transponder" => $transponder
    ]);

    /*
        Trackpunkt speichern.

        Wichtig:
        Wir speichern wieder bei jedem Positionsupdate einen Trackpunkt.
        Dadurch wird die Flugroute wieder zuverlässig aufgezeichnet.
        Die Map lädt später nur neue Punkte nach.
    */
    $insertTrackStmt = $pdo->prepare(
        "INSERT INTO pilot_tracks
        (
            session_token,
            callsign,
            latitude,
            longitude,
            altitude,
            heading
        )
        VALUES
        (
            :session_token,
            :callsign,
            :latitude,
            :longitude,
            :altitude,
            :heading
        )"
    );

    $insertTrackStmt->execute([
        "session_token" => $token,
        "callsign" => $callsign,
        "latitude" => (float)$latitude,
        "longitude" => (float)$longitude,
        "altitude" => (float)$altitude,
        "heading" => (float)$heading
    ]);


    /* !!! */

    if (
        (float)$airspeed >= 30 &&
        $previousPosition
    )
    {
        $distanceNm =
            calculateDistanceNm(
                (float)$previousPosition["latitude"],
                (float)$previousPosition["longitude"],
                (float)$latitude,
                (float)$longitude
            );

        /*
            Schutz gegen Teleports
        */
        if ($distanceNm > 5)
        {
            $distanceNm = 0;
        }

        $seconds = 1;

        if (
            !empty(
                $previousPosition["last_update"]
            )
        )
        {
            $lastUpdate =
                strtotime(
                    $previousPosition["last_update"]
                );

            if ($lastUpdate !== false)
            {
                $seconds =
                    time() - $lastUpdate;

                if (
                    $seconds < 1 ||
                    $seconds > 10
                )
                {
                    $seconds = 1;
                }
            }
        }

        $statsStmt = $pdo->prepare(
            "UPDATE users
             SET
                total_flight_seconds =
                    total_flight_seconds + :seconds,

                total_flight_miles =
                    total_flight_miles + :distance

             WHERE id = :user_id"
        );

        $statsStmt->execute([
            "seconds" =>
                $seconds,

            "distance" =>
                $distanceNm,

            "user_id" =>
                (int)$session["user_id"]
        ]);
    }



    /* !!! */



    echo json_encode([
        "success" => true,
        "message" => "Position aktualisiert.",
        "aircraft_icao" => $aircraft_icao,
        "aircraft_category" => $aircraft_category,
        "transponder" => $transponder
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Serverfehler.",
        "error" => $e->getMessage()
    ]);


}

