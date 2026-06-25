<?php
header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once 'aircraft_types.php';
require_once '../includes/activity_log.php';
require_once '../includes/award_system.php';


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

$onGround =
    (int)($_POST["on_ground"] ?? 0);

$onGround =
    $onGround === 1 ? 1 : 0;

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
        "SELECT
            s.user_id,
            s.was_airborne,
            s.last_vertical_speed,
            u.username
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

    /*
        Vorherige Position vor dem Update lesen.
        Diese Daten werden fuer Distanz / Flugzeit benoetigt.
    */
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
        Nicht jede Sekunde, sondern nur wenn der letzte Punkt
        mindestens 5 Sekunden alt ist oder sich die Position geaendert hat.
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

    /*
        Flugzeit, Distanz und Flugzeug-Statistik.
        Gezaehlt wird nur ab 30 kt und nur mit vorhandener Vorposition.
    */
    if (
        (float)$airspeed >= 30 &&
        $previousPosition
    ) {
        $distanceNm =
            calculateDistanceNm(
                (float)$previousPosition["latitude"],
                (float)$previousPosition["longitude"],
                (float)$latitude,
                (float)$longitude
            );

        /*
            Schutz gegen Teleports.
            Spruenge ueber 5 NM pro Update werden nicht gewertet.
        */
        if ($distanceNm > 5) {
            $distanceNm = 0;
        }

        $seconds = 1;

        if (!empty($previousPosition["last_update"])) {
            $lastUpdate =
                strtotime($previousPosition["last_update"]);

            if ($lastUpdate !== false) {
                $seconds =
                    time() - $lastUpdate;

                if (
                    $seconds < 1 ||
                    $seconds > 10
                ) {
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

        $aircraftStatsStmt = $pdo->prepare(
            "INSERT INTO pilot_aircraft_stats
            (
                user_id,
                aircraft_icao,
                total_seconds,
                total_miles,
                last_used
            )
            VALUES
            (
                :user_id,
                :aircraft_icao,
                :seconds,
                :distance,
                NOW()
            )
            ON DUPLICATE KEY UPDATE

                total_seconds =
                    total_seconds + VALUES(total_seconds),

                total_miles =
                    total_miles + VALUES(total_miles),

                last_used = NOW()"
        );

        $aircraftStatsStmt->execute([
            "user_id" =>
                (int)$session["user_id"],

            "aircraft_icao" =>
                $aircraft_icao,

            "seconds" =>
                $seconds,

            "distance" =>
                $distanceNm
        ]);
    }

    /*
        Landing Detection

        Dieser Block steht absichtlich ausserhalb der Flugzeit-/Speed-30-Logik.
        Beim Aufsetzen kann die Groundspeed bereits unter 30 kt liegen.

        Eine Landung wird nur beim Statuswechsel erkannt:
            was_airborne = 1
            on_ground = 1

        Eine 30-Sekunden-Sperre verhindert doppelte Landungen,
        falls mehrere Positionsupdates direkt nach dem Touchdown eintreffen.
    */
    $wasAirborne =
        (int)($session["was_airborne"] ?? 0);

    $lastVerticalSpeed =
        (int)($session["last_vertical_speed"] ?? 0);

    $currentVerticalSpeed =
        (int)round((float)$vertical_speed);

    $currentAirspeed =
        (float)$airspeed;

    $isAirborneNow =
        $onGround === 0 &&
        $currentAirspeed >= 40;

    $isLandingNow =
        $onGround === 1 &&
        $wasAirborne === 1;

    if ($isLandingNow) {
        $recentLandingStmt = $pdo->prepare(
            "SELECT id
             FROM pilot_landings
             WHERE user_id = :user_id
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
             LIMIT 1"
        );

        $recentLandingStmt->execute([
            "user_id" =>
                (int)$session["user_id"]
        ]);

        $recentLanding =
            $recentLandingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$recentLanding) {
            $landingRateFpm =
                abs($lastVerticalSpeed);

            $landingStmt = $pdo->prepare(
                "INSERT INTO pilot_landings
                (
                    user_id,
                    aircraft_icao,
                    landing_rate_fpm,
                    latitude,
                    longitude,
                    created_at
                )
                VALUES
                (
                    :user_id,
                    :aircraft_icao,
                    :landing_rate_fpm,
                    :latitude,
                    :longitude,
                    NOW()
                )"
            );

            $landingStmt->execute([
                "user_id" =>
                    (int)$session["user_id"],

                "aircraft_icao" =>
                    $aircraft_icao,

                "landing_rate_fpm" =>
                    $landingRateFpm,

                "latitude" =>
                    (float)$latitude,

                "longitude" =>
                    (float)$longitude
            ]);

            $landingCounterStmt = $pdo->prepare(
                "UPDATE users
                 SET total_landings =
                     total_landings + 1
                 WHERE id = :user_id"
            );

            $landingCounterStmt->execute([
                "user_id" =>
                    (int)$session["user_id"]
            ]);

            $totalLandingStmt = $pdo->prepare(
                "SELECT total_landings
                 FROM users
                 WHERE id = :user_id
                 LIMIT 1"
            );

            $totalLandingStmt->execute([
                "user_id" =>
                    (int)$session["user_id"]
            ]);

            $userStats =
                $totalLandingStmt->fetch(PDO::FETCH_ASSOC);

            $totalLandings =
                (int)($userStats["total_landings"] ?? 0);

            if ($totalLandings === 1) {

                logActivity(
                    $pdo,
                    (int)$session["user_id"],
                    'flight',
                    'activity_first_landing',
                    $aircraft_icao . ' - ' . $landingRateFpm . ' fpm',
                    0
                );

                awardUser(
                    $pdo,
                    (int)$session["user_id"],
                    'award_first_landing'
                );
            }
        }

        $sessionStateStmt = $pdo->prepare(
            "UPDATE user_sessions
             SET
                was_airborne = 0,
                last_vertical_speed = :vertical_speed
             WHERE token = :token
             LIMIT 1"
        );

        $sessionStateStmt->execute([
            "vertical_speed" =>
                $currentVerticalSpeed,

            "token" =>
                $token
        ]);

    } elseif ($isAirborneNow) {
        $sessionStateStmt = $pdo->prepare(
            "UPDATE user_sessions
             SET
                was_airborne = 1,
                last_vertical_speed = :vertical_speed
             WHERE token = :token
             LIMIT 1"
        );

        $sessionStateStmt->execute([
            "vertical_speed" =>
                $currentVerticalSpeed,

            "token" =>
                $token
        ]);

    } elseif ($onGround === 1) {
        $sessionStateStmt = $pdo->prepare(
            "UPDATE user_sessions
             SET
                was_airborne = 0,
                last_vertical_speed = :vertical_speed
             WHERE token = :token
             LIMIT 1"
        );

        $sessionStateStmt->execute([
            "vertical_speed" =>
                $currentVerticalSpeed,

            "token" =>
                $token
        ]);
    }

    echo json_encode([
        "success" => true,
        "message" => "Position aktualisiert.",
        "aircraft_icao" => $aircraft_icao,
        "aircraft_category" => $aircraft_category,
        "transponder" => $transponder,
        "on_ground" => $onGround
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Serverfehler.",
        "error" => $e->getMessage()
    ]);
}
