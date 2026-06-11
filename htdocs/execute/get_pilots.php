<?php

session_start();

header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once 'aircraft_types.php';
require_once '../includes/ratings.php';

$activeSeconds = 10;

function getAirportByCode($pdo, $code)
{
    $code = strtoupper(trim((string)$code));

    if ($code === "" || $code === "ZZZZ") {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT
            ident,
            name,
            latitude_deg,
            longitude_deg,
            municipality,
            icao_code,
            gps_code
         FROM airports
         WHERE ident = :code
            OR icao_code = :code
            OR gps_code = :code
         LIMIT 1"
    );

    $stmt->execute([
        "code" => $code
    ]);

    $airport = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$airport) {
        return null;
    }

    return [
        "ident" => $airport["ident"],
        "name" => $airport["name"],
        "latitude" => (float)$airport["latitude_deg"],
        "longitude" => (float)$airport["longitude_deg"],
        "municipality" => $airport["municipality"],
        "icao_code" => $airport["icao_code"],
        "gps_code" => $airport["gps_code"]
    ];
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
        "DELETE FROM pilot_tracks
         WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
    );

    $stmt->execute();

    $stmt = $pdo->prepare(
        "SELECT
            p.user_id,
            p.session_token,
            p.username,
            p.callsign,
            p.aircraft_icao,
            p.aircraft_category,
            p.latitude,
            p.longitude,
            p.altitude,
            p.heading,
            p.airspeed,
            p.pitch,
            p.roll_angle,
            p.vertical_speed,
            p.com1,
            p.com2,
            p.com3,
            p.transponder,
            p.last_update,
            u.country_code,
            u.division_code,

            s.is_invisible,

            u.op_permission,
            u.rating_pilot,
            u.rating_atc,
            u.rating_special,

            fp.flight_rules,
            fp.flight_type,
            fp.departure_time,
            fp.departure_airport,
            fp.arrival_airport,
            fp.alternate1_airport,
            fp.alternate2_airport,
            fp.route_text,
            fp.cruising_level,
            fp.cruising_speed,
            fp.remarks

         FROM pilot_positions p

         INNER JOIN user_sessions s
            ON s.token = p.session_token

         INNER JOIN users u
            ON u.id = s.user_id

         LEFT JOIN pilot_flightplans fp
            ON fp.session_token = p.session_token

         WHERE s.is_active = 1
            AND p.last_update >= DATE_SUB(NOW(), INTERVAL :activeSeconds SECOND)

         ORDER BY p.callsign ASC"
    );

    $stmt->bindValue(
        ":activeSeconds",
        $activeSeconds,
        PDO::PARAM_INT
    );

    $stmt->execute();

    $viewerOpPermission = 0;

    if (isset($_SESSION["web_user_id"])) {

        $viewerStmt = $pdo->prepare(
            "SELECT
                op_permission
             FROM users
             WHERE id = :id
             LIMIT 1"
        );

        $viewerStmt->execute([
            "id" => (int)$_SESSION["web_user_id"]
        ]);

        $viewer =
            $viewerStmt->fetch(PDO::FETCH_ASSOC);

        if ($viewer) {
            $viewerOpPermission =
                (int)$viewer["op_permission"];
        }
    }

    $pilots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $visiblePilots = [];

    $invisibleCount = 0;

    foreach ($pilots as &$pilot) {

        $isInvisible =
            ((int)$pilot["is_invisible"] === 1);

        $pilotPermission =
            (int)$pilot["op_permission"];

        if ($isInvisible) {

            $invisibleCount++;

            if (
                $viewerOpPermission < $pilotPermission
            ) {
                continue;
            }
        }

        if (
            !isset($pilot["aircraft_category"]) ||
            trim($pilot["aircraft_category"]) === ""
        ) {
            $pilot["aircraft_category"] = getAircraftCategory(
                $pilot["aircraft_icao"] ?? ""
            );
        }

        if (
            !isset($pilot["transponder"]) ||
            trim((string)$pilot["transponder"]) === ""
        ) {
            $pilot["transponder"] = "0000";
        }

        $pilotRating =
            (int)($pilot["rating_pilot"] ?? 0);

        $atcRating =
            (int)($pilot["rating_atc"] ?? 0);

        $specialRating =
            (int)($pilot["rating_special"] ?? 0);

        $pilot["ratings"] =
            getUserRatings(
                $pilotRating,
                $atcRating,
                $specialRating
            );

        $departureAirport =
            strtoupper(trim($pilot["departure_airport"] ?? "ZZZZ"));

        $arrivalAirport =
            strtoupper(trim($pilot["arrival_airport"] ?? "ZZZZ"));

        $alternate1Airport =
            strtoupper(trim($pilot["alternate1_airport"] ?? "ZZZZ"));

        $alternate2Airport =
            strtoupper(trim($pilot["alternate2_airport"] ?? "ZZZZ"));

        if ($departureAirport === "") {
            $departureAirport = "ZZZZ";
        }

        if ($arrivalAirport === "") {
            $arrivalAirport = "ZZZZ";
        }

        if ($alternate1Airport === "") {
            $alternate1Airport = "ZZZZ";
        }

        if ($alternate2Airport === "") {
            $alternate2Airport = "ZZZZ";
        }

        $pilot["flightplan"] = [

            "flight_rules" =>
                $pilot["flight_rules"] ?? "",

            "flight_type" =>
                $pilot["flight_type"] ?? "",

            "departure_time" =>
                $pilot["departure_time"] ?? "",

            "departure_airport" =>
                $departureAirport,

            "arrival_airport" =>
                $arrivalAirport,

            "alternate1_airport" =>
                $alternate1Airport,

            "alternate2_airport" =>
                $alternate2Airport,

            "route_text" =>
                $pilot["route_text"] ?? "",

            "cruising_level" =>
                $pilot["cruising_level"] ?? "",

            "cruising_speed" =>
                $pilot["cruising_speed"] ?? "",

            "remarks" =>
                $pilot["remarks"] ?? "",

            "departure_airport_info" =>
                getAirportByCode(
                    $pdo,
                    $departureAirport
                ),

            "arrival_airport_info" =>
                getAirportByCode(
                    $pdo,
                    $arrivalAirport
                ),

            "alternate1_airport_info" =>
                getAirportByCode(
                    $pdo,
                    $alternate1Airport
                ),

            "alternate2_airport_info" =>
                getAirportByCode(
                    $pdo,
                    $alternate2Airport
                )
        ];

        $pilot["is_invisible"] =
            $isInvisible;

        unset($pilot["session_token"]);
        unset($pilot["flight_rules"]);
        unset($pilot["departure_time"]);
        unset($pilot["departure_airport"]);
        unset($pilot["arrival_airport"]);
        unset($pilot["alternate1_airport"]);
        unset($pilot["alternate2_airport"]);
        unset($pilot["route_text"]);
        unset($pilot["cruising_level"]);
        unset($pilot["cruising_speed"]);
        unset($pilot["remarks"]);
        unset($pilot["flight_type"]);
        unset($pilot["op_permission"]);
        unset($pilot["rating_pilot"]);
        unset($pilot["rating_atc"]);
        unset($pilot["rating_special"]);

        $pilot["track"] = [];

        $visiblePilots[] = $pilot;
    }

    unset($pilot);

    echo json_encode([
        "success" => true,

        "message" =>
            "Aktive Piloten geladen.",

        "visible_count" =>
            count($visiblePilots),

        "invisible_count" =>
            $invisibleCount,

        "total_count" =>
            count($pilots),

        "count" =>
            count($visiblePilots),

        "pilots" =>
            $visiblePilots
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Serverfehler.",
        "error" => $e->getMessage()
    ]);
}