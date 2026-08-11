<?php
require_once __DIR__ . '/../includes/atc_atis_scope.php';
header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once 'aircraft_types.php';
require_once '../includes/awards_checks.php';
require_once '../includes/track_maintenance.php';


$token = trim($_POST["token"] ?? "");

/** Return the active controller heard on this frequency at the pilot position. */
function findPositionAtcCallsign(
    PDO $pdo,
    string $frequency,
    float $latitude,
    float $longitude
): string {
    $frequency = normalizeAtcVoiceFrequency($frequency);
    if ($frequency === '' || $frequency === '122.800') return '';

    $statement = $pdo->prepare(
        "SELECT a.callsign,a.station_code,a.position_code,a.radar_boundary_code,
                ap.latitude_deg,ap.longitude_deg
         FROM atc_sessions a
         LEFT JOIN airports ap ON UPPER(ap.ident)=UPPER(a.station_code)
         WHERE a.is_active=1 AND a.is_spectator=0
           AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
           AND ABS(CAST(a.frequency AS DECIMAL(7,3))-CAST(:frequency AS DECIMAL(7,3)))<0.001"
    );
    $statement->execute(['frequency' => $frequency]);

    $matches = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $session) {
        $position = strtoupper((string)$session['position_code']);
        $inside = false;
        $specificity = 0.0;

        if (in_array($position, ['APP', 'DEP', 'CTR'], true)) {
            foreach (readAtisScopeFeatures($session) as $feature) {
                $geometry = is_array($feature['geometry'] ?? null)
                    ? $feature['geometry'] : [];
                if (!pointInAtisGeometry($longitude, $latitude, $geometry)
                    && distanceToAtcGeometryNm($longitude, $latitude, $geometry) > 10.0) continue;
                $inside = true;
                // Prefer a smaller, more specific sub-sector when sectors overlap.
                $encoded = json_encode($geometry['coordinates'] ?? []);
                preg_match_all('/-?\d+(?:\.\d+)?/', (string)$encoded, $numbers);
                $values = array_map('floatval', $numbers[0] ?? []);
                $lons = []; $lats = [];
                for ($i = 0; $i + 1 < count($values); $i += 2) {
                    $lons[] = $values[$i]; $lats[] = $values[$i + 1];
                }
                if ($lons && $lats) {
                    $specificity = max(0.000001,
                        (max($lons) - min($lons)) * (max($lats) - min($lats)));
                }
                break;
            }
        } elseif ($session['latitude_deg'] !== null && $session['longitude_deg'] !== null) {
            $range = 20.0;
            if ($position === 'TWR') $range = 30.0;
            elseif (in_array($position, ['GND', 'DEL', 'INFO'], true)) $range = 12.0;
            $inside = atisDistanceNm(
                $latitude, $longitude,
                (float)$session['latitude_deg'], (float)$session['longitude_deg']
            ) <= $range;
            $specificity = $range * $range;
        }

        $priority = 6;
        if ($position === 'DEL') $priority = 1;
        elseif ($position === 'GND') $priority = 2;
        elseif ($position === 'TWR') $priority = 3;
        elseif (in_array($position, ['APP', 'DEP'], true)) $priority = 4;
        elseif ($position === 'CTR') $priority = 5;
        if ($inside) $matches[] = [
            'callsign' => strtoupper((string)$session['callsign']),
            'specificity' => $specificity,
            'priority' => $priority,
        ];
    }
    usort($matches, static function (array $a, array $b): int {
        $specificity = $a['specificity'] <=> $b['specificity'];
        return $specificity !== 0 ? $specificity : ($a['priority'] <=> $b['priority']);
    });
    return (string)($matches[0]['callsign'] ?? '');
}

/** Approximate distance to a sector boundary in NM (local equirectangular plane). */
function distanceToAtcGeometryNm(
    float $longitude,
    float $latitude,
    array $geometry
): float {
    $type = (string)($geometry['type'] ?? '');
    $coordinates = $geometry['coordinates'] ?? [];
    $polygons = $type === 'Polygon' ? [$coordinates]
        : ($type === 'MultiPolygon' ? $coordinates : []);
    $best = INF;
    $lonScale = max(0.05, cos(deg2rad($latitude)));
    foreach ($polygons as $polygon) {
        foreach (is_array($polygon) ? $polygon : [] as $ring) {
            if (!is_array($ring) || count($ring) < 2) continue;
            for ($i = 1; $i < count($ring); ++$i) {
                $a = $ring[$i - 1]; $b = $ring[$i];
                if (!is_array($a) || !is_array($b)) continue;
                $ax = ((float)$a[0] - $longitude) * $lonScale;
                $ay = (float)$a[1] - $latitude;
                $bx = ((float)$b[0] - $longitude) * $lonScale;
                $by = (float)$b[1] - $latitude;
                $dx = $bx - $ax; $dy = $by - $ay;
                $denominator = $dx * $dx + $dy * $dy;
                $t = $denominator > 0.0
                    ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $denominator))
                    : 0.0;
                $distanceDegrees = hypot($ax + $t * $dx, $ay + $t * $dy);
                $best = min($best, $distanceDegrees * 60.0);
            }
        }
    }
    return $best;
}

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
$fuelRemainingPercent =
    isset($_POST["fuel_remaining_percent"])
        ? (float)$_POST["fuel_remaining_percent"]
        : null;
$nightFlightSeconds =
    max(
        0,
        (int)($_POST["night_flight_seconds"] ?? 0)
    );
$totalFlightSeconds =
    max(
        0,
        (int)($_POST["total_flight_seconds"] ?? 0)
    );

$onGround =
    (int)($_POST["on_ground"] ?? 0);

$onGround =
    $onGround === 1 ? 1 : 0;
$aiControlsAircraft =
    (int)($_POST['ai_controls_aircraft'] ?? 0) === 1 ? 1 : 0;
$aiDestinationIcao =
    strtoupper(trim((string)($_POST['ai_destination_icao'] ?? '')));
if (
    $aiControlsAircraft !== 1
    || !preg_match('/^[A-Z0-9]{2,8}$/', $aiDestinationIcao)
) {
    $aiDestinationIcao = '';
}

$ratioValue = static function (string $name): float {
    return max(
        0.0,
        min(1.0, (float)($_POST[$name] ?? 0.0))
    );
};
$switchValue = static function (string $name): int {
    return (int)($_POST[$name] ?? 0) === 1 ? 1 : 0;
};
$gearRatio = $ratioValue('gear_ratio');
$flapRatio = $ratioValue('flap_ratio');
$speedbrakeRatio = $ratioValue('speedbrake_ratio');
$thrustRatio = $ratioValue('thrust_ratio');
$engineRpm = max(0.0, (float)($_POST['engine_rpm'] ?? 0.0));
$engineCount = max(1, min(8, (int)($_POST['engine_count'] ?? 1)));
$engineArray = static function (
    string $name,
    int $count,
    float $minimum,
    float $maximum
): array {
    $rawValues = explode(',', (string)($_POST[$name] ?? ''));
    $values = [];
    for ($index = 0; $index < $count; $index++) {
        $value = isset($rawValues[$index])
            ? (float)$rawValues[$index]
            : 0.0;
        $values[] = max($minimum, min($maximum, $value));
    }
    return $values;
};
$engineThrustRatios = $engineArray(
    'engine_thrust_ratios',
    $engineCount,
    0.0,
    1.0
);
$engineRpms = $engineArray(
    'engine_rpms',
    $engineCount,
    0.0,
    100000.0
);
$yokePitchRatio = max(
    -1.0,
    min(1.0, (float)($_POST['yoke_pitch_ratio'] ?? 0.0))
);
$yokeRollRatio = max(
    -1.0,
    min(1.0, (float)($_POST['yoke_roll_ratio'] ?? 0.0))
);
$yokeHeadingRatio = max(
    -1.0,
    min(1.0, (float)($_POST['yoke_heading_ratio'] ?? 0.0))
);
$taxiLights = $switchValue('taxi_lights');
$landingLights = $switchValue('landing_lights');
$beaconLights = $switchValue('beacon_lights');
$strobeLights = $switchValue('strobe_lights');
$navLights = $switchValue('nav_lights');
$slatRatio = $ratioValue('slat_ratio');
$wingSweepRatio = $ratioValue('wing_sweep_ratio');
$thrustReverserRatio = $ratioValue('thrust_reverser_ratio');
$noseWheelAngle = max(
    -90.0,
    min(90.0, (float)($_POST['nose_wheel_angle'] ?? 0.0))
);
$tireRotationRadSec = max(
    -1000.0,
    min(1000.0, (float)($_POST['tire_rotation_rad_sec'] ?? 0.0))
);

$com1 = $_POST["com1"] ?? 0;
$com2 = $_POST["com2"] ?? 0;
$com3 = $_POST["com3"] ?? 0;

$hasCrashed =
    (int)($_POST['has_crashed'] ?? 0);

$transponder = trim($_POST["transponder"] ?? "0000");
$transponderMode = max(
    0,
    min(7, (int)($_POST['transponder_mode'] ?? 0))
);

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

function normalizeLandingAirportCode(?string $airportCode): ?string
{
    $airportCode =
        strtoupper(trim((string)$airportCode));

    if ($airportCode === '' || $airportCode === 'ZZZZ') {
        return null;
    }

    if (!preg_match('/^[A-Z0-9]{3,10}$/', $airportCode)) {
        return null;
    }

    return $airportCode;
}

function resolveFlightplanLandingAirport(
    PDO $pdo,
    array $airportCodes,
    float $landingLatitude,
    float $landingLongitude
): ?string {

    $candidateCodes = [];

    foreach ($airportCodes as $airportCode) {
        $normalizedCode =
            normalizeLandingAirportCode($airportCode);

        if ($normalizedCode === null) {
            continue;
        }

        if (in_array($normalizedCode, $candidateCodes, true)) {
            continue;
        }

        $candidateCodes[] =
            $normalizedCode;
    }

    if (empty($candidateCodes)) {
        return null;
    }

    $placeholders =
        implode(
            ',',
            array_fill(0, count($candidateCodes), '?')
        );

    $airportStmt = $pdo->prepare(
        "SELECT
            ident,
            icao_code,
            gps_code,
            latitude_deg,
            longitude_deg
         FROM airports
         WHERE ident IN ($placeholders)
            OR icao_code IN ($placeholders)
            OR gps_code IN ($placeholders)"
    );

    $airportStmt->execute(
        array_merge(
            $candidateCodes,
            $candidateCodes,
            $candidateCodes
        )
    );

    $airportRows =
        $airportStmt->fetchAll(PDO::FETCH_ASSOC);

    $closestAirportCode = null;
    $closestDistanceNm = null;

    foreach ($airportRows as $airportRow) {
        $matchedCode = null;

        foreach ($candidateCodes as $candidateCode) {
            if (
                strtoupper((string)$airportRow['ident']) === $candidateCode
                || strtoupper((string)$airportRow['icao_code']) === $candidateCode
                || strtoupper((string)$airportRow['gps_code']) === $candidateCode
            ) {
                $matchedCode =
                    $candidateCode;

                break;
            }
        }

        if ($matchedCode === null) {
            continue;
        }

        $distanceNm =
            calculateDistanceNm(
                $landingLatitude,
                $landingLongitude,
                (float)$airportRow['latitude_deg'],
                (float)$airportRow['longitude_deg']
            );

        if (
            $closestDistanceNm === null
            || $distanceNm < $closestDistanceNm
        ) {
            $closestDistanceNm =
                $distanceNm;

            $closestAirportCode =
                $matchedCode;
        }
    }

    if (
        $closestAirportCode === null
        || $closestDistanceNm === null
    ) {
        return null;
    }

    if ($closestDistanceNm > 15.0) {
        return null;
    }

    return $closestAirportCode;
}

function findNearestLandingAirport(
    PDO $pdo,
    float $landingLatitude,
    float $landingLongitude,
    float $maximumDistanceNm = 15.0
): ?array {
    $latitudeWindow = $maximumDistanceNm / 60.0;
    $longitudeScale = max(0.15, abs(cos(deg2rad($landingLatitude))));
    $longitudeWindow = $maximumDistanceNm / (60.0 * $longitudeScale);

    $airportStmt = $pdo->prepare(
        "SELECT ident, icao_code, gps_code, latitude_deg, longitude_deg
         FROM airports
         WHERE latitude_deg BETWEEN :minimum_latitude AND :maximum_latitude
           AND longitude_deg BETWEEN :minimum_longitude AND :maximum_longitude"
    );
    $airportStmt->execute([
        'minimum_latitude' => $landingLatitude - $latitudeWindow,
        'maximum_latitude' => $landingLatitude + $latitudeWindow,
        'minimum_longitude' => $landingLongitude - $longitudeWindow,
        'maximum_longitude' => $landingLongitude + $longitudeWindow
    ]);

    $nearestAirport = null;
    foreach ($airportStmt->fetchAll(PDO::FETCH_ASSOC) as $airportRow) {
        $distanceNm = calculateDistanceNm(
            $landingLatitude,
            $landingLongitude,
            (float)$airportRow['latitude_deg'],
            (float)$airportRow['longitude_deg']
        );
        if ($distanceNm > $maximumDistanceNm) {
            continue;
        }
        if ($nearestAirport !== null && $distanceNm >= $nearestAirport['distance_nm']) {
            continue;
        }
        $code = strtoupper(trim((string)($airportRow['icao_code'] ?: $airportRow['gps_code'] ?: $airportRow['ident'])));
        $nearestAirport = [
            'code' => $code !== '' ? $code : null,
            'distance_nm' => $distanceNm
        ];
    }

    return $nearestAirport;
}

function distanceToAirportCode(
    PDO $pdo,
    ?string $airportCode,
    float $latitude,
    float $longitude
): ?float {
    $airportCode = normalizeLandingAirportCode($airportCode);
    if ($airportCode === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT latitude_deg, longitude_deg
         FROM airports
         WHERE ident = :ident OR icao_code = :icao OR gps_code = :gps
         LIMIT 1"
    );
    $stmt->execute([
        'ident' => $airportCode,
        'icao' => $airportCode,
        'gps' => $airportCode
    ]);
    $airport = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$airport) {
        return null;
    }
    return calculateDistanceNm(
        $latitude,
        $longitude,
        (float)$airport['latitude_deg'],
        (float)$airport['longitude_deg']
    );
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

    try {
        vfnRunTrackMaintenance($pdo);
    } catch (Throwable $maintenanceError) {
        error_log('Track maintenance failed: ' . $maintenanceError->getMessage());
    }

    $stmt = $pdo->prepare(
        "SELECT
            s.user_id,
            s.was_airborne,
            s.last_vertical_speed,
            s.is_invisible,
            s.is_spectator,
            s.plugin_language,
            u.username,
            u.op_permission,
            u.rating_atc,
            u.rating_special
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
        $inactiveStmt = $pdo->prepare(
            "SELECT
                s.user_id,
                s.last_seen
             FROM user_sessions s
             WHERE s.token = :token
             LIMIT 1"
        );

        $inactiveStmt->execute([
            "token" => $token
        ]);

        $inactiveSession =
            $inactiveStmt->fetch(PDO::FETCH_ASSOC);

        if ($inactiveSession) {
            $kickActivityStmt = $pdo->prepare(
                "SELECT activity_key, activity_value
                 FROM user_activity_log
                 WHERE user_id = :user_id
                   AND activity_key IN (
                       'activity_kicked',
                       'activity_kicked_spam',
                       'activity_kicked_ground_vehicle_rank',
                       'activity_banned'
                   )
                   AND created_at >= DATE_SUB(:session_ended_at, INTERVAL 5 SECOND)
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $kickActivityStmt->execute([
                "user_id" => (int)$inactiveSession["user_id"],
                "session_ended_at" => (string)$inactiveSession["last_seen"]
            ]);
            $kickActivity = $kickActivityStmt->fetch(PDO::FETCH_ASSOC);

            if ($kickActivity) {
                $isSpamKick =
                    (string)$kickActivity["activity_key"] === "activity_kicked_spam";
                $isBan =
                    (string)$kickActivity["activity_key"] === "activity_banned";
                $isGroundVehicleRankKick =
                    (string)$kickActivity["activity_key"] ===
                    "activity_kicked_ground_vehicle_rank";
                echo json_encode([
                    "success" => false,
                    "kicked" => true,
                    "spam_kick" => $isSpamKick,
                    "ground_vehicle_rank_kick" => $isGroundVehicleRankKick,
                    "message" => $isSpamKick
                        ? "Automatic chat spam protection triggered."
                        : ($isBan
                            ? "Account banned: " . (string)$kickActivity["activity_value"]
                            : (string)$kickActivity["activity_value"])
                ]);
                exit;
            }

            $kickMessageStmt = $pdo->prepare(
                "SELECT
                    message_text
                 FROM chat_messages
                 WHERE recipient_user_id = :user_id
                   AND sender_callsign = 'ADMIN'
                   AND message_text LIKE 'Du wurdest aus dem Netzwerk gekickt.%'
                 ORDER BY id DESC
                 LIMIT 1"
            );

            $kickMessageStmt->execute([
                "user_id" => (int)$inactiveSession["user_id"]
            ]);

            $kickMessage =
                $kickMessageStmt->fetchColumn();

            if ($kickMessage) {
                echo json_encode([
                    "success" => false,
                    "kicked" => true,
                    "message" => (string)$kickMessage
                ]);
                exit;
            }
        }

        echo json_encode([
            "success" => false,
            "message" => "Ungueltige oder abgelaufene Session."
        ]);
        exit;
    }

    if (
        !empty($maintenanceMode)
        && (int)$session['op_permission'] < 5
    ) {
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE pilot_flights
             SET status = 'aborted', completed_at = NOW()
             WHERE session_token = :token AND status = 'active'"
        )->execute(['token' => $token]);
        $pdo->prepare(
            "UPDATE user_sessions
             SET is_active = 0, last_seen = NOW()
             WHERE token = :token"
        )->execute(['token' => $token]);
        $pdo->prepare(
            "DELETE FROM pilot_positions WHERE session_token = :token"
        )->execute(['token' => $token]);
        $pdo->prepare(
            "DELETE FROM pilot_tracks WHERE session_token = :token"
        )->execute(['token' => $token]);
        $pdo->commit();

        echo json_encode([
            "success" => false,
            "kicked" => true,
            "maintenance_mode" => true,
            "message" =>
                "Das VFN-Netzwerk befindet sich im Wartungsmodus. "
                . "Die Verbindung wurde getrennt."
        ]);
        exit;
    }

    if (
        (int)($session['is_spectator'] ?? 0) !== 1
        && $aircraft_category === 'groundvehicle'
        && (int)($session['rating_atc'] ?? 0) < 4
        && (int)($session['rating_special'] ?? 0) < 1
    ) {
        $kickReason =
            'Ground vehicles require at least ATC rank PAT '
            . 'or special rank VFN Operations Officer.';

        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE pilot_flights
             SET status = 'aborted', completed_at = NOW()
             WHERE session_token = :token AND status = 'active'"
        )->execute(['token' => $token]);
        $pdo->prepare(
            "UPDATE user_sessions
             SET is_active = 0, last_seen = NOW()
             WHERE token = :token"
        )->execute(['token' => $token]);
        $pdo->prepare(
            "DELETE FROM pilot_positions WHERE session_token = :token"
        )->execute(['token' => $token]);
        $pdo->prepare(
            "DELETE FROM pilot_tracks WHERE session_token = :token"
        )->execute(['token' => $token]);
        $pdo->prepare(
            "INSERT INTO user_activity_log
                (user_id, actor_user_id, activity_type, activity_key, activity_value)
             VALUES
                (:user_id, 0, 'kick', 'activity_kicked_ground_vehicle_rank', :reason)"
        )->execute([
            'user_id' => (int)$session['user_id'],
            'reason' => $kickReason
        ]);
        $pdo->commit();

        echo json_encode([
            'success' => false,
            'kicked' => true,
            'ground_vehicle_rank_kick' => true,
            'message' => $kickReason
        ]);
        exit;
    }

    $minimumInvisibleLevel =
        (int)($minimumInvisibleOpPermission ?? 2);

    $canUseInvisible =
        ((int)$session["op_permission"] >= $minimumInvisibleLevel);

    if (!$canUseInvisible && (int)$session["is_invisible"] === 1) {
        $resetInvisibleStmt = $pdo->prepare(
            "UPDATE user_sessions
             SET is_invisible = 0
             WHERE token = :token
             LIMIT 1"
        );

        $resetInvisibleStmt->execute([
            "token" => $token
        ]);

        $session["is_invisible"] = 0;
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

    $pluginLanguage = strtolower(trim((string)($_POST['plugin_language'] ?? '')));
    $pluginLanguage = in_array($pluginLanguage, ['de', 'en'], true)
        ? $pluginLanguage
        : (string)($session['plugin_language'] ?? 'en');

    $stmt = $pdo->prepare(
        "UPDATE user_sessions
         SET last_seen = NOW(),
             callsign = :callsign,
             plugin_language = :plugin_language
         WHERE token = :token
         LIMIT 1"
    );

    $stmt->execute([
        "callsign" => $callsign,
        "plugin_language" => $pluginLanguage,
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
            on_ground,
            ai_controls_aircraft,
            ai_destination_icao,
            gear_ratio,
            flap_ratio,
            speedbrake_ratio,
            thrust_ratio,
            engine_rpm,
            engine_count,
            engine_thrust_ratios,
            engine_rpms,
            yoke_pitch_ratio,
            yoke_roll_ratio,
            yoke_heading_ratio,
            taxi_lights,
            landing_lights,
            beacon_lights,
            strobe_lights,
            nav_lights,
            slat_ratio,
            wing_sweep_ratio,
            thrust_reverser_ratio,
            nose_wheel_angle,
            tire_rotation_rad_sec,
            transponder_mode,
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
            :on_ground,
            :ai_controls_aircraft,
            :ai_destination_icao,
            :gear_ratio,
            :flap_ratio,
            :speedbrake_ratio,
            :thrust_ratio,
            :engine_rpm,
            :engine_count,
            :engine_thrust_ratios,
            :engine_rpms,
            :yoke_pitch_ratio,
            :yoke_roll_ratio,
            :yoke_heading_ratio,
            :taxi_lights,
            :landing_lights,
            :beacon_lights,
            :strobe_lights,
            :nav_lights,
            :slat_ratio,
            :wing_sweep_ratio,
            :thrust_reverser_ratio,
            :nose_wheel_angle,
            :tire_rotation_rad_sec,
            :transponder_mode,
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
            on_ground = VALUES(on_ground),
            ai_controls_aircraft = VALUES(ai_controls_aircraft),
            ai_destination_icao = VALUES(ai_destination_icao),
            gear_ratio = VALUES(gear_ratio),
            flap_ratio = VALUES(flap_ratio),
            speedbrake_ratio = VALUES(speedbrake_ratio),
            thrust_ratio = VALUES(thrust_ratio),
            engine_rpm = VALUES(engine_rpm),
            engine_count = VALUES(engine_count),
            engine_thrust_ratios = VALUES(engine_thrust_ratios),
            engine_rpms = VALUES(engine_rpms),
            yoke_pitch_ratio = VALUES(yoke_pitch_ratio),
            yoke_roll_ratio = VALUES(yoke_roll_ratio),
            yoke_heading_ratio = VALUES(yoke_heading_ratio),
            taxi_lights = VALUES(taxi_lights),
            landing_lights = VALUES(landing_lights),
            beacon_lights = VALUES(beacon_lights),
            strobe_lights = VALUES(strobe_lights),
            nav_lights = VALUES(nav_lights),
            slat_ratio = VALUES(slat_ratio),
            wing_sweep_ratio = VALUES(wing_sweep_ratio),
            thrust_reverser_ratio = VALUES(thrust_reverser_ratio),
            nose_wheel_angle = VALUES(nose_wheel_angle),
            tire_rotation_rad_sec = VALUES(tire_rotation_rad_sec),
            transponder_mode = VALUES(transponder_mode),
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
        "on_ground" => $onGround,
        "ai_controls_aircraft" => $aiControlsAircraft,
        "ai_destination_icao" => $aiDestinationIcao,
        "gear_ratio" => $gearRatio,
        "flap_ratio" => $flapRatio,
        "speedbrake_ratio" => $speedbrakeRatio,
        "thrust_ratio" => $thrustRatio,
        "engine_rpm" => $engineRpm,
        "engine_count" => $engineCount,
        "engine_thrust_ratios" => json_encode($engineThrustRatios),
        "engine_rpms" => json_encode($engineRpms),
        "yoke_pitch_ratio" => $yokePitchRatio,
        "yoke_roll_ratio" => $yokeRollRatio,
        "yoke_heading_ratio" => $yokeHeadingRatio,
        "taxi_lights" => $taxiLights,
        "landing_lights" => $landingLights,
        "beacon_lights" => $beaconLights,
        "strobe_lights" => $strobeLights,
        "nav_lights" => $navLights,
        "slat_ratio" => $slatRatio,
        "wing_sweep_ratio" => $wingSweepRatio,
        "thrust_reverser_ratio" => $thrustReverserRatio,
        "nose_wheel_angle" => $noseWheelAngle,
        "tire_rotation_rad_sec" => $tireRotationRadSec,
        "transponder_mode" => $transponderMode,
        "com1" => (float)$com1,
        "com2" => (float)$com2,
        "com3" => (float)$com3,
        "transponder" => $transponder
    ]);

    if ((int)($session['is_spectator'] ?? 0) === 1) {
        echo json_encode([
            "success" => true,
            "message" => "Spectator-Position aktualisiert.",
            "spectator" => true,
            "aircraft_icao" => $aircraft_icao,
            "aircraft_category" => $aircraft_category,
            "transponder" => $transponder,
            "on_ground" => $onGround,
            "op_permission" => (int)$session["op_permission"],
            "can_use_invisible" => $canUseInvisible,
            "is_invisible" => ((int)$session["is_invisible"] === 1)
        ]);
        exit;
    }


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

        $flightProgressStmt = $pdo->prepare(
            "UPDATE pilot_flights
             SET duration_seconds = duration_seconds + :seconds,
                 distance_nm = distance_nm + :distance
             WHERE session_token = :session_token
               AND status = 'active'"
        );
        $flightProgressStmt->execute([
            'seconds' => $seconds,
            'distance' => $distanceNm,
            'session_token' => $token
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

            $landingId =
                (int)$pdo->lastInsertId();

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

            $landingAirport = null;
            $plannedArrivalAirport = null;

            $flightplanStmt = $pdo->prepare(
                "SELECT
                    arrival_airport,
                    alternate1_airport,
                    alternate2_airport
                 FROM pilot_flightplans
                 WHERE session_token = :session_token
                 LIMIT 1"
            );

            $flightplanStmt->execute([
                "session_token" =>
                    $token
            ]);

            $flightplan =
                $flightplanStmt->fetch(PDO::FETCH_ASSOC);

            if ($flightplan) {
                $plannedArrivalAirport =
                    normalizeLandingAirportCode(
                        $flightplan['arrival_airport'] ?? null
                    );
                $landingAirport =
                    resolveFlightplanLandingAirport(
                        $pdo,
                        [
                            $flightplan["arrival_airport"] ?? null,
                            $flightplan["alternate1_airport"] ?? null,
                            $flightplan["alternate2_airport"] ?? null
                        ],
                        (float)$latitude,
                        (float)$longitude
                    );
            }

            $nearestLandingAirport = findNearestLandingAirport(
                $pdo,
                (float)$latitude,
                (float)$longitude
            );
            $destinationDistanceNm = distanceToAirportCode(
                $pdo,
                $plannedArrivalAirport,
                (float)$latitude,
                (float)$longitude
            );
            $flightCompletionStatus =
                $plannedArrivalAirport !== null
                && $destinationDistanceNm !== null
                && $landingAirport === null
                    ? 'wrong_destination'
                    : 'completed';

            $completeFlightStmt = $pdo->prepare(
                "UPDATE pilot_flights
                 SET status = :status,
                     completed_at = NOW(),
                     landing_rate_fpm = :landing_rate,
                     landed_airport = :landed_airport,
                     destination_distance_nm = :destination_distance_nm
                 WHERE session_token = :session_token
                   AND status = 'active'"
            );
            $completeFlightStmt->execute([
                'status' => $flightCompletionStatus,
                'landing_rate' => $landingRateFpm,
                'landed_airport' => $nearestLandingAirport['code'] ?? null,
                'destination_distance_nm' => $destinationDistanceNm,
                'session_token' => $token
            ]);

            checkLandingAwards(
                $pdo,
                (int)$session["user_id"],
                $aircraft_icao,
                $landingRateFpm,
                $fuelRemainingPercent,
                $landingAirport,
                $nightFlightSeconds,
                $totalFlightSeconds,
                $landingId
            );
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
        if ($wasAirborne === 0) {
            $flightplanForHistoryStmt = $pdo->prepare(
                "SELECT departure_airport, arrival_airport
                 FROM pilot_flightplans
                 WHERE session_token = :session_token
                 LIMIT 1"
            );
            $flightplanForHistoryStmt->execute(['session_token' => $token]);
            $flightplanForHistory =
                $flightplanForHistoryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $startFlightStmt = $pdo->prepare(
                "INSERT INTO pilot_flights
                    (user_id, session_token, callsign, aircraft_icao,
                     departure_airport, arrival_airport, started_at, status)
                 VALUES
                    (:user_id, :session_token, :callsign, :aircraft_icao,
                     :departure_airport, :arrival_airport, NOW(), 'active')
                 ON DUPLICATE KEY UPDATE
                    callsign = VALUES(callsign),
                    aircraft_icao = VALUES(aircraft_icao),
                    departure_airport = VALUES(departure_airport),
                    arrival_airport = VALUES(arrival_airport)"
            );
            $startFlightStmt->execute([
                'user_id' => (int)$session['user_id'],
                'session_token' => $token,
                'callsign' => $callsign,
                'aircraft_icao' => $aircraft_icao,
                'departure_airport' => $flightplanForHistory['departure_airport'] ?? null,
                'arrival_airport' => $flightplanForHistory['arrival_airport'] ?? null
            ]);
        }

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

    checkCrashPilot(
        $pdo,
        (int)$session['user_id'],
        $hasCrashed
    );

    checkPositionAwards(
        $pdo,
        (int)$session["user_id"],
        (float)$latitude,
        (float)$longitude
    );

    $com1AtcCallsign = findPositionAtcCallsign(
        $pdo, (string)$com1, (float)$latitude, (float)$longitude
    );
    $com2AtcCallsign = findPositionAtcCallsign(
        $pdo, (string)$com2, (float)$latitude, (float)$longitude
    );

    $currentFlightplanStmt = $pdo->prepare(
        "SELECT flight_rules,flight_type,departure_time,departure_airport,arrival_airport,
                alternate1_airport,alternate2_airport,route_text,cruising_level,
                cruising_speed,remarks,
                (CRC32(CONCAT_WS('|',flight_rules,flight_type,departure_time,
                    departure_airport,arrival_airport,alternate1_airport,
                    alternate2_airport,route_text,cruising_level,cruising_speed,remarks)) & 2147483647) AS revision
         FROM pilot_flightplans WHERE session_token=:token LIMIT 1"
    );
    $currentFlightplanStmt->execute(['token' => $token]);
    $currentFlightplan = $currentFlightplanStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($currentFlightplan !== null) {
        $currentFlightplan['revision'] = (int)($currentFlightplan['revision'] ?? 0);
    }

    echo json_encode([
        "success" => true,
        "message" => "Position aktualisiert.",
        "aircraft_icao" => $aircraft_icao,
        "aircraft_category" => $aircraft_category,
        "transponder" => $transponder,
        "on_ground" => $onGround,
        "op_permission" => (int)$session["op_permission"],
        "can_use_invisible" => $canUseInvisible,
        "is_invisible" => ((int)$session["is_invisible"] === 1)
        ,"com1_atc_callsign" => $com1AtcCallsign
        ,"com2_atc_callsign" => $com2AtcCallsign
        ,"flightplan" => $currentFlightplan
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Serverfehler.",
        "error" => $e->getMessage()
    ]);
}
