<?php

require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();

header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once 'aircraft_types.php';
require_once '../includes/ratings.php';
require_once '../includes/web_session.php';
require_once '../includes/atc_frequency_catalog.php';
require_once '../includes/flightplan_schema.php';

$providedProtection = (string)($_GET['protection'] ?? '');
$expectedProtection = (string)($getPilotsProtection ?? '');

if (
    $expectedProtection === ''
    || !hash_equals($expectedProtection, $providedProtection)
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Zugriff verweigert.'
    ]);
    exit;
}

// Keep the worldwide map stable during short position-upload outages. Fresh
// coordinates are still returned immediately on every two-second map poll;
// this grace period only prevents a target from disappearing between updates.
$activeSeconds = 60;

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

    ensurePilotFlightplanCommunicationColumn($pdo);

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
            p.on_ground,
            p.gear_ratio,
            p.flap_ratio,
            p.slat_ratio,
            p.speedbrake_ratio,
            p.wing_sweep_ratio,
            p.thrust_ratio,
            p.thrust_reverser_ratio,
            p.engine_rpm,
            p.engine_count,
            p.engine_thrust_ratios,
            p.engine_rpms,
            p.yoke_pitch_ratio,
            p.yoke_roll_ratio,
            p.yoke_heading_ratio,
            p.nose_wheel_angle,
            p.tire_rotation_rad_sec,
            p.taxi_lights,
            p.landing_lights,
            p.beacon_lights,
            p.strobe_lights,
            p.nav_lights,
            p.ai_controls_aircraft,
            p.ai_destination_icao,
            p.com1,
            p.com2,
            p.com3,
            p.transponder,
            p.transponder_mode,
            p.last_update,
            u.country_code,
            u.division_code,
            d.name AS division_name,

            s.is_invisible,
            s.is_spectator,

            u.op_permission,
            u.rating_pilot,
            u.rating_atc,
            u.rating_special,

            fp.flight_rules,
            fp.flight_type,
            fp.communication_mode,
            fp.departure_time,
            fp.departure_airport,
            fp.arrival_airport,
            fp.alternate1_airport,
            fp.alternate2_airport,
            fp.route_text,
            fp.cruising_level,
            fp.cruising_speed,
            fp.remarks,

            af.started_at AS active_flight_started_at,
            af.duration_seconds AS active_flight_duration_seconds,
            af.distance_nm AS active_flight_distance_nm

         FROM pilot_positions p

         INNER JOIN user_sessions s
            ON s.token = p.session_token

         INNER JOIN users u
            ON u.id = s.user_id

         LEFT JOIN divisions d
            ON d.code = u.division_code

         LEFT JOIN pilot_flightplans fp
            ON fp.session_token = p.session_token

         LEFT JOIN pilot_flights af
            ON af.session_token = p.session_token
           AND af.status = 'active'

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
    $viewerUserId = 0;

    if (isset($_SESSION["web_user_id"])) {
        $viewerUserId = (int)$_SESSION["web_user_id"];
        validateVfnWebSession($pdo);
    }

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

    // The map refreshes independently from the rest of the website. Release
    // the PHP session lock as soon as the viewer permission has been read so
    // map polling can never block page loads, login, or the admin panel.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $pilots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $trainingStatement=$pdo->query(
        "SELECT ta.*,creator.user_id AS trainer_user_id
         FROM atc_training_aircraft ta
         INNER JOIN atc_sessions creator ON creator.id=ta.trainer_session_id
         WHERE creator.is_active=1 AND creator.is_trainer=1
           AND creator.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)
         ORDER BY ta.callsign"
    );
    foreach($trainingStatement->fetchAll(PDO::FETCH_ASSOC) as $training){
        $onGround=(string)$training['placement_type']!=='air'
            && (float)$training['altitude']<=5.0;
        $trainingTransponderStatus=strtolower((string)($training['transponder_status']??'standby'));
        $trainingTransponderMode=$trainingTransponderStatus==='ident'?4:($trainingTransponderStatus==='on'?2:1);
        $pilots[]=[
            'user_id'=>-(int)$training['id'],'session_token'=>'training:'.(int)$training['id'],
            'username'=>'TRAINING','callsign'=>(string)$training['callsign'],
            'aircraft_icao'=>(string)$training['aircraft_icao'],'aircraft_category'=>'',
            'latitude'=>(float)$training['latitude'],'longitude'=>(float)$training['longitude'],
            'altitude'=>(int)$training['altitude'],'heading'=>(int)$training['heading'],
            'airspeed'=>(int)$training['airspeed'],'pitch'=>0,'roll_angle'=>0,'vertical_speed'=>0,
            'on_ground'=>$onGround?1:0,'gear_ratio'=>$onGround?1:0,'flap_ratio'=>0,'slat_ratio'=>0,
            'speedbrake_ratio'=>0,'wing_sweep_ratio'=>0,'thrust_ratio'=>0,'thrust_reverser_ratio'=>0,
            'engine_rpm'=>0,'engine_count'=>2,'engine_thrust_ratios'=>'[]','engine_rpms'=>'[]',
            'yoke_pitch_ratio'=>0,'yoke_roll_ratio'=>0,'yoke_heading_ratio'=>0,'nose_wheel_angle'=>0,
            'tire_rotation_rad_sec'=>0,'taxi_lights'=>0,'landing_lights'=>0,'beacon_lights'=>1,
            'strobe_lights'=>0,'nav_lights'=>1,'ai_controls_aircraft'=>0,'ai_destination_icao'=>'',
            'com1'=>(string)($training['com1_frequency']??'122.800'),'com2'=>(string)($training['com2_frequency']??'122.800'),'com3'=>'','transponder'=>(string)($training['transponder_code']??'7000'),'transponder_mode'=>$trainingTransponderMode,
            'last_update'=>date('Y-m-d H:i:s'),'country_code'=>'','division_code'=>'','division_name'=>'Training',
            'is_invisible'=>0,'is_spectator'=>0,'op_permission'=>0,'rating_pilot'=>0,'rating_atc'=>0,
            'rating_special'=>0,'flight_rules'=>(string)$training['flight_rules'],'flight_type'=>(string)$training['flight_type'],
            'communication_mode'=>(string)$training['communication_mode'],'departure_time'=>'',
            'departure_airport'=>(string)$training['departure_airport'],'arrival_airport'=>(string)$training['arrival_airport'],
            'alternate1_airport'=>(string)$training['alternate1_airport'],'alternate2_airport'=>(string)$training['alternate2_airport'],
            'route_text'=>(string)$training['route_text'],'cruising_level'=>(string)$training['cruising_level'],
            'cruising_speed'=>(string)$training['cruising_speed'],'remarks'=>(string)$training['remarks'],
            'active_flight_started_at'=>null,'active_flight_duration_seconds'=>0,'active_flight_distance_nm'=>0,
            'is_training_aircraft'=>true,'training_aircraft_id'=>(int)$training['id'],
        ];
    }

    $visiblePilots = [];

    $invisibleCount = 0;
    $regularPilotCount = 0;

    foreach ($pilots as &$pilot) {

        $isSpectator = ((int)($pilot['is_spectator'] ?? 0) === 1);
        if ($isSpectator && $viewerOpPermission < 1) {
            continue;
        }
        $pilot['is_spectator'] = $isSpectator;

        $isInvisible =
            ((int)$pilot["is_invisible"] === 1);

        $pilotPermission =
            (int)$pilot["op_permission"];

        if ($isInvisible) {
            // Invisible pilots are never disclosed publicly. OP users may see
            // invisible staff members of the same or a lower permission level.
            if (
                $viewerOpPermission < 1
                || $viewerOpPermission < $pilotPermission
            ) {
                continue;
            }

            $invisibleCount++;
        } else {
            // The live map lists training targets as online traffic. Long-term
            // pilot statistics are calculated elsewhere and remain unaffected.
            $regularPilotCount++;
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

        $pilot["aircraft_icao"] =
            strtoupper(
                trim((string)($pilot["aircraft_icao"] ?? "UNKNOWN"))
            );
        $pilot["ai_controls_aircraft"] =
            (int)($pilot["ai_controls_aircraft"] ?? 0) === 1;

        // Expose every simulator state that can affect how this aircraft is
        // rendered by other multiplayer clients. Keep the flat fields for
        // existing consumers and add a structured block for diagnostics and
        // future clients.
        $pilot["on_ground"] =
            (int)($pilot["on_ground"] ?? 0) === 1;
        $pilot["gear_ratio"] =
            (float)($pilot["gear_ratio"] ?? 0.0);
        $pilot["flap_ratio"] =
            (float)($pilot["flap_ratio"] ?? 0.0);
        $pilot["slat_ratio"] =
            (float)($pilot["slat_ratio"] ?? 0.0);
        $pilot["speedbrake_ratio"] =
            (float)($pilot["speedbrake_ratio"] ?? 0.0);
        $pilot["wing_sweep_ratio"] =
            (float)($pilot["wing_sweep_ratio"] ?? 0.0);
        $pilot["thrust_ratio"] =
            (float)($pilot["thrust_ratio"] ?? 0.0);
        $pilot["thrust_reverser_ratio"] =
            (float)($pilot["thrust_reverser_ratio"] ?? 0.0);
        $pilot["engine_rpm"] =
            (float)($pilot["engine_rpm"] ?? 0.0);
        $pilot["engine_count"] = max(
            1,
            min(8, (int)($pilot["engine_count"] ?? 1))
        );
        $engineThrustRatios = json_decode(
            (string)($pilot["engine_thrust_ratios"] ?? '[]'),
            true
        );
        $engineRpms = json_decode(
            (string)($pilot["engine_rpms"] ?? '[]'),
            true
        );
        if (!is_array($engineThrustRatios)) {
            $engineThrustRatios = [];
        }
        if (!is_array($engineRpms)) {
            $engineRpms = [];
        }
        $pilot["engines"] = [];
        for ($engineIndex = 0; $engineIndex < $pilot["engine_count"]; $engineIndex++) {
            $pilot["engines"][] = [
                "number" => $engineIndex + 1,
                "thrust_ratio" => (float)($engineThrustRatios[$engineIndex] ?? 0.0),
                "rpm" => (float)($engineRpms[$engineIndex] ?? 0.0),
                "running" =>
                    (float)($engineRpms[$engineIndex] ?? 0.0) > 1.0
            ];
        }
        $pilot["engine_thrust_ratios"] = array_column(
            $pilot["engines"],
            "thrust_ratio"
        );
        $pilot["engine_rpms"] = array_column($pilot["engines"], "rpm");
        $pilot["yoke_pitch_ratio"] =
            (float)($pilot["yoke_pitch_ratio"] ?? 0.0);
        $pilot["yoke_roll_ratio"] =
            (float)($pilot["yoke_roll_ratio"] ?? 0.0);
        $pilot["yoke_heading_ratio"] =
            (float)($pilot["yoke_heading_ratio"] ?? 0.0);
        $pilot["nose_wheel_angle"] =
            (float)($pilot["nose_wheel_angle"] ?? 0.0);
        $pilot["tire_rotation_rad_sec"] =
            (float)($pilot["tire_rotation_rad_sec"] ?? 0.0);
        $pilot["taxi_lights"] =
            (int)($pilot["taxi_lights"] ?? 0) === 1;
        $pilot["landing_lights"] =
            (int)($pilot["landing_lights"] ?? 0) === 1;
        $pilot["beacon_lights"] =
            (int)($pilot["beacon_lights"] ?? 0) === 1;
        $pilot["strobe_lights"] =
            (int)($pilot["strobe_lights"] ?? 0) === 1;
        $pilot["nav_lights"] =
            (int)($pilot["nav_lights"] ?? 0) === 1;
        $pilot["transponder_mode"] =
            (int)($pilot["transponder_mode"] ?? 0);

        $pilot["multiplayer_state"] = [
            "on_ground" => $pilot["on_ground"],
            "transponder_mode" => $pilot["transponder_mode"],
            "lights" => [
                "navigation" => $pilot["nav_lights"],
                "beacon" => $pilot["beacon_lights"],
                "strobe" => $pilot["strobe_lights"],
                "taxi" => $pilot["taxi_lights"],
                "landing" => $pilot["landing_lights"]
            ],
            "configuration" => [
                "gear_ratio" => $pilot["gear_ratio"],
                "flap_ratio" => $pilot["flap_ratio"],
                "slat_ratio" => $pilot["slat_ratio"],
                "speedbrake_ratio" => $pilot["speedbrake_ratio"],
                "spoiler_ratio" => $pilot["speedbrake_ratio"],
                "wing_sweep_ratio" => $pilot["wing_sweep_ratio"],
                "thrust_reverser_ratio" =>
                    $pilot["thrust_reverser_ratio"]
            ],
            "engines" => [
                "count" => $pilot["engine_count"],
                "thrust_ratio" => $pilot["thrust_ratio"],
                "engine_rpm" => $pilot["engine_rpm"],
                "items" => $pilot["engines"]
            ],
            "controls" => [
                "pitch_ratio" => $pilot["yoke_pitch_ratio"],
                "roll_ratio" => $pilot["yoke_roll_ratio"],
                "heading_ratio" => $pilot["yoke_heading_ratio"],
                "nose_wheel_angle" => $pilot["nose_wheel_angle"],
                "tire_rotation_rad_sec" =>
                    $pilot["tire_rotation_rad_sec"]
            ]
        ];

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

        $pilot["ai_destination_icao"] =
            strtoupper(
                trim((string)($pilot["ai_destination_icao"] ?? ""))
            );
        $pilot["flightplan_destination_icao"] =
            $arrivalAirport === "ZZZZ" ? "" : $arrivalAirport;
        $pilot["destination_icao"] =
            $pilot["ai_controls_aircraft"]
            && $pilot["ai_destination_icao"] !== ""
                ? $pilot["ai_destination_icao"]
                : $pilot["flightplan_destination_icao"];

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

            "communication_mode" =>
                $pilot["communication_mode"] ?? "VOICE",

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

    // Keep the read-only network state in one response. Internal database IDs,
    // user IDs and session tokens deliberately never leave this endpoint.
    $atcStatement = $pdo->query(
        "SELECT a.callsign, a.station_code, a.position_code, a.frequency, a.is_gca,
                a.is_trainer,
                a.user_id, a.is_invisible, u.op_permission AS controller_op_permission,
                a.radar_boundary_code, a.scope_positions, a.map_profile,
                a.is_spectator, a.can_control, a.can_transmit_voice,
                a.connected_at, a.last_seen_at,
                COALESCE(NULLIF(TRIM(u.real_name), ''), u.username) AS controller_name,
                u.country_code, u.division_code,
                ap.latitude_deg AS latitude, ap.longitude_deg AS longitude,
                ap.name AS airport_name
         FROM atc_sessions a
         INNER JOIN users u ON u.id = a.user_id
         LEFT JOIN airports ap ON UPPER(ap.ident) = UPPER(a.station_code)
         WHERE a.is_active = 1 AND a.is_ready=1 AND (a.is_spectator = 0 OR a.is_trainer = 1)
           AND ((a.is_trainer=1 AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE))
                OR (a.is_trainer=0 AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)))
         ORDER BY a.station_code, a.position_code, a.callsign"
    );
    $atcs = array_values(array_filter(
        $atcStatement->fetchAll(PDO::FETCH_ASSOC),
        static function (array $atc) use ($viewerOpPermission, $viewerUserId): bool {
            if ((int)($atc['is_invisible'] ?? 0) !== 1) {
                return true;
            }

            // An invisible trainer position must not be disclosed on the map.
            // Its synthetic training aircraft is a separate pilot record and
            // deliberately remains visible for training purposes.
            if ((int)($atc['is_trainer'] ?? 0) === 1) {
                return false;
            }

            // The owner always sees their own invisible ATC session. Other
            // staff may only see invisible operators of the same or a lower
            // permission level, matching the pilot visibility rules.
            if ($viewerUserId > 0 && (int)($atc['user_id'] ?? 0) === $viewerUserId) {
                return true;
            }

            return $viewerOpPermission >= 1
                && $viewerOpPermission >= (int)($atc['controller_op_permission'] ?? 0);
        }
    ));
    foreach ($atcs as &$atc) {
        $atc['is_trainer'] = (int)($atc['is_trainer'] ?? 0) === 1;
        if (normalizeAtcVoiceFrequency((string)($atc['frequency'] ?? '')) === '') {
            $knownFrequencies = findAtcFrequencies(
                $pdo,
                (string)($atc['station_code'] ?? ''),
                (string)($atc['position_code'] ?? '')
            );
            if ($knownFrequencies) {
                $atc['frequency'] = (string)$knownFrequencies[0]['frequency'];
            }
        }
        $atc['is_spectator'] = (int)($atc['is_spectator'] ?? 0) === 1;
        $atc['is_gca'] = (int)($atc['is_gca'] ?? 0) === 1;
        $atc['can_control'] = (int)($atc['can_control'] ?? 0) === 1;
        $atc['can_transmit_voice'] = (int)($atc['can_transmit_voice'] ?? 0) === 1;
        $atc['scope_positions'] = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($atc['scope_positions'] ?? ''))
        )));
        $atc['latitude'] = $atc['latitude'] !== null ? (float)$atc['latitude'] : null;
        $atc['longitude'] = $atc['longitude'] !== null ? (float)$atc['longitude'] : null;
        unset($atc['user_id'], $atc['is_invisible'], $atc['controller_op_permission']);
    }
    unset($atc);

    $atisAirports = [];
    $automaticAtis = [];
    try {
        foreach ($pdo->query(
            "SELECT b.airport_icao, b.frequency, b.info_letter, b.active_runway,
                    b.updated_at, b.is_active, ap.name AS airport_name,
                    ap.latitude_deg AS latitude, ap.longitude_deg AS longitude
             FROM auto_atis_broadcasts b
             LEFT JOIN airports ap ON UPPER(ap.ident) = UPPER(b.airport_icao)
             WHERE b.is_active = 1"
        )->fetchAll(PDO::FETCH_ASSOC) as $automaticAtisRow) {
            $automaticAtis[strtoupper(trim((string)$automaticAtisRow['airport_icao']))] = $automaticAtisRow;
        }
    } catch (Throwable $ignored) {
        $automaticAtis = [];
    }
    try {
        $atisStatement = $pdo->query(
            "SELECT s.airport_icao, s.frequency, s.airport_name,
                    s.latitude, s.longitude, a.callsign AS controller_callsign,
                    a.station_code, a.position_code, a.frequency AS controller_frequency,
                    a.radar_boundary_code, a.is_gca, a.is_trainer,
                    a.user_id, a.is_invisible, u.op_permission AS controller_op_permission,
                    COALESCE(NULLIF(TRIM(u.real_name), ''), u.username) AS controller_name
             FROM atc_session_atis_airports s
             INNER JOIN atc_sessions a ON a.id = s.session_id
             INNER JOIN users u ON u.id = a.user_id
             WHERE a.is_active = 1 AND a.is_ready=1 AND (a.is_spectator = 0 OR a.is_trainer=1)
               AND ((a.is_trainer=1 AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE))
                    OR (a.is_trainer=0 AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)))
             ORDER BY s.airport_icao, a.callsign"
        );
        foreach ($atisStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $atisControllerVisible = (int)($row['is_invisible'] ?? 0) !== 1
                || ((int)($row['is_trainer'] ?? 0) !== 1 && (
                    ($viewerUserId > 0 && (int)($row['user_id'] ?? 0) === $viewerUserId)
                    || ($viewerOpPermission >= 1
                        && $viewerOpPermission >= (int)($row['controller_op_permission'] ?? 0))
                ));
            if (!$atisControllerVisible) {
                continue;
            }
            $icao = strtoupper(trim((string)$row['airport_icao']));
            if (!isset($atisAirports[$icao])) {
                $atisAirports[$icao] = [
                    'airport_icao' => $icao,
                    'frequency' => (string)$row['frequency'],
                    'airport_name' => (string)$row['airport_name'],
                    'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                    'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                    'info_letter' => (string)($automaticAtis[$icao]['info_letter'] ?? ''),
                    'active_runway' => (string)($automaticAtis[$icao]['active_runway'] ?? ''),
                    'updated_at' => (string)($automaticAtis[$icao]['updated_at'] ?? ''),
                    'is_active' => isset($automaticAtis[$icao]),
                    'controllers' => [],
                ];
            }
            $atisAirports[$icao]['controllers'][] = [
                'callsign' => (string)$row['controller_callsign'],
                'is_gca' => (int)($row['is_gca'] ?? 0) === 1,
                'station_code' => (string)$row['station_code'],
                'position_code' => (string)$row['position_code'],
                'frequency' => (string)$row['controller_frequency'],
                'radar_boundary_code' => (string)$row['radar_boundary_code'],
                'controller_name' => (string)$row['controller_name'],
            ];
        }
    } catch (Throwable $ignored) {
        $atisAirports = [];
    }
    foreach ($automaticAtis as $icao => $automaticAtisRow) {
        if (isset($atisAirports[$icao])) {
            continue;
        }
        $atisAirports[$icao] = [
            'airport_icao' => $icao,
            'frequency' => (string)($automaticAtisRow['frequency'] ?? ''),
            'airport_name' => (string)($automaticAtisRow['airport_name'] ?? ''),
            'latitude' => $automaticAtisRow['latitude'] !== null
                ? (float)$automaticAtisRow['latitude'] : null,
            'longitude' => $automaticAtisRow['longitude'] !== null
                ? (float)$automaticAtisRow['longitude'] : null,
            'info_letter' => (string)($automaticAtisRow['info_letter'] ?? ''),
            'active_runway' => (string)($automaticAtisRow['active_runway'] ?? ''),
            'updated_at' => (string)($automaticAtisRow['updated_at'] ?? ''),
            'is_active' => true,
            'controllers' => [],
        ];
    }
    foreach ($atcs as &$atc) {
        $station = strtoupper(trim((string)($atc['station_code'] ?? '')));
        $atc['atis_frequency'] = (string)($atisAirports[$station]['frequency'] ?? '');
        $atc['atis_info_letter'] = (string)($atisAirports[$station]['info_letter'] ?? '');
        $atc['atis_active_runway'] = (string)($atisAirports[$station]['active_runway'] ?? '');
        $atc['atis_updated_at'] = (string)($atisAirports[$station]['updated_at'] ?? '');
        $atc['atis_active'] = isset($atisAirports[$station]);
    }
    unset($atc);

    echo json_encode([
        "success" => true,
        "message" => "Aktive Netzwerk-Teilnehmer geladen.",
        "pilots" => [
            "visible_count" => count($visiblePilots),
            "invisible_count" => $invisibleCount,
            "total_count" => $regularPilotCount,
            "count" => $regularPilotCount,
            "items" => $visiblePilots,
        ],
        "atcs" => [
            "count" => count(array_filter($atcs, static fn(array $atc): bool => empty($atc['is_trainer']))),
            "active_count" => count(array_filter($atcs, static fn(array $atc): bool => empty($atc['is_trainer']))),
            "items" => $atcs,
            "atis_airports" => array_values($atisAirports),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Serverfehler.",
        "error" => $e->getMessage()
    ]);
}
