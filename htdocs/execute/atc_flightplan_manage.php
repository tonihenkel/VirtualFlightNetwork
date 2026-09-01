<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/chat_system.php';
require_once __DIR__ . '/../includes/flightplan_schema.php';
require_once __DIR__ . '/../includes/airport_code.php';

function atcFplReply(bool $success, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        atcFplReply(false, ['message' => 'login_required'], 401);
    }
    ensureAtcSchema($pdo);
    ensurePilotFlightplanCommunicationColumn($pdo);
    $sessionToken = (string)($_SESSION['atc_session_token'] ?? '');
    $sessionStatement = $pdo->prepare(
        "SELECT id,user_id,is_spectator,is_trainer,can_control FROM atc_sessions
         WHERE user_id=:user_id AND session_token=:token AND is_active=1
           AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $sessionStatement->execute([
        'user_id' => (int)$_SESSION['web_user_id'],
        'token' => $sessionToken,
    ]);
    $atcSession = $sessionStatement->fetch(PDO::FETCH_ASSOC);
    if (!$atcSession) {
        atcFplReply(false, ['message' => 'atc_session_required'], 403);
    }

    $callsign = strtoupper(trim((string)($_REQUEST['callsign'] ?? '')));
    if ($callsign === '' || !preg_match('/^[A-Z0-9_-]{2,24}$/', $callsign)) {
        atcFplReply(false, ['message' => 'invalid_callsign'], 422);
    }
    $targetStatement = $pdo->prepare(
        "SELECT p.session_token,p.user_id FROM pilot_positions p
         INNER JOIN user_sessions s ON s.token=p.session_token
         WHERE p.callsign=:callsign AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND)
           AND s.is_active=1 AND s.is_spectator=0 LIMIT 1"
    );
    $targetStatement->execute(['callsign' => $callsign]);
    $target = $targetStatement->fetch(PDO::FETCH_ASSOC);
    $isTrainingTarget = false;
    if (!$target) {
        $trainingStatement = $pdo->prepare(
            "SELECT ta.id,creator.user_id FROM atc_training_aircraft ta
             INNER JOIN atc_sessions creator ON creator.id=ta.trainer_session_id
             WHERE UPPER(ta.callsign)=:callsign AND creator.is_active=1 AND creator.is_trainer=1
               AND creator.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) LIMIT 1"
        );
        $trainingStatement->execute(['callsign'=>$callsign]);
        $trainingTarget=$trainingStatement->fetch(PDO::FETCH_ASSOC);
        $trainingId=(int)($trainingTarget['id']??0);
        if($trainingId>0){
            $target=['session_token'=>'training:'.$trainingId,'user_id'=>(int)$trainingTarget['user_id'],'training_id'=>$trainingId];
            $isTrainingTarget=true;
        }
    }
    if (!$target) atcFplReply(false, ['message' => 'pilot_not_online'], 404);

    $fields = [
        'flight_rules', 'flight_type', 'communication_mode', 'departure_airport', 'arrival_airport',
        'alternate1_airport', 'alternate2_airport', 'route_text',
        'cruising_level', 'cruising_speed', 'remarks',
    ];
    $flightplanStatement = $pdo->prepare($isTrainingTarget
        ? "SELECT " . implode(',', $fields) . " FROM atc_training_aircraft WHERE id=:target LIMIT 1"
        : "SELECT " . implode(',', $fields) . " FROM pilot_flightplans WHERE session_token=:target LIMIT 1");
    $flightplanStatement->execute(['target' => $isTrainingTarget ? (int)$target['training_id'] : (string)$target['session_token']]);
    $flightplan = $flightplanStatement->fetch(PDO::FETCH_ASSOC);
    if (!$flightplan) {
        $flightplan = [
            'flight_rules' => 'I',
            'flight_type' => 'G',
            'communication_mode' => 'VOICE',
            'departure_airport' => 'ZZZZ',
            'arrival_airport' => 'ZZZZ',
            'alternate1_airport' => 'ZZZZ',
            'alternate2_airport' => 'ZZZZ',
            'route_text' => '',
            'cruising_level' => '',
            'cruising_speed' => '',
            'remarks' => '',
        ];
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $storedRules = strtoupper(trim((string)($flightplan['flight_rules'] ?? '')));
        if ($storedRules === 'IFR') {
            $flightplan['flight_rules'] = 'I';
        } elseif ($storedRules === 'VFR') {
            $flightplan['flight_rules'] = 'V';
        } elseif (in_array($storedRules, ['I', 'V', 'Y', 'Z'], true)) {
            $flightplan['flight_rules'] = $storedRules;
        } else {
            $flightplan['flight_rules'] = 'I';
        }
        $ownership=$pdo->prepare("SELECT atc_session_id,atc_callsign FROM atc_assumed_aircraft WHERE pilot_session_token=:token LIMIT 1");
        $ownership->execute(['token'=>(string)$target['session_token']]);$owner=$ownership->fetch(PDO::FETCH_ASSOC);
        $isTrainer = (int)($atcSession['is_trainer'] ?? 0) === 1;
        $mayControl = ((int)($atcSession['is_spectator'] ?? 1) === 0 || $isTrainer)
            && (int)($atcSession['can_control'] ?? 0) === 1;
        atcFplReply(true, ['callsign' => $callsign, 'flightplan' => $flightplan, 'assumed_by_me'=>$mayControl&&($isTrainer||($owner&&(int)$owner['atc_session_id']===(int)$atcSession['id'])), 'assumed_by'=>(string)($owner['atc_callsign']??'')]);
    }

    $isTrainer = (int)($atcSession['is_trainer'] ?? 0) === 1;
    if (((int)($atcSession['is_spectator'] ?? 1) === 1 && !$isTrainer) || (int)($atcSession['can_control'] ?? 0) !== 1) {
        atcFplReply(false, ['message' => 'atc_control_session_required'], 403);
    }

    $ownership=$pdo->prepare("SELECT atc_session_id FROM atc_assumed_aircraft WHERE pilot_session_token=:token LIMIT 1");
    $ownership->execute(['token'=>(string)$target['session_token']]);$owner=$ownership->fetch(PDO::FETCH_ASSOC);
    if(!$isTrainer&&(!$owner||(int)$owner['atc_session_id']!==(int)$atcSession['id']))atcFplReply(false,['message'=>'aircraft_must_be_assumed'],409);

    $values = [];
    foreach ($fields as $field) $values[$field] = trim((string)($_POST[$field] ?? ''));
    $values['flight_rules'] = strtoupper(substr($values['flight_rules'], 0, 3));
    if ($values['flight_rules'] === 'IFR') {
        $values['flight_rules'] = 'I';
    } elseif ($values['flight_rules'] === 'VFR') {
        $values['flight_rules'] = 'V';
    }
    if (!in_array($values['flight_rules'], ['I', 'V', 'Y', 'Z'], true)) {
        atcFplReply(false, ['message' => 'invalid_flight_rules'], 422);
    }
    $values['flight_type'] = strtoupper(substr($values['flight_type'], 0, 4));
    $values['communication_mode'] = strtoupper($values['communication_mode']);
    if (!in_array($values['communication_mode'], ['VOICE', 'RECEIVE_ONLY', 'TEXT_ONLY'], true)) {
        $values['communication_mode'] = 'VOICE';
    }
    foreach (['departure_airport','arrival_airport','alternate1_airport','alternate2_airport'] as $field) {
        $values[$field] = vfnNormalizeFlightplanAirport($pdo, $values[$field]);
    }
    $values['cruising_level'] = strtoupper(substr($values['cruising_level'], 0, 20));
    $values['cruising_speed'] = strtoupper(substr($values['cruising_speed'], 0, 20));
    $values['route_text'] = mb_substr(strtoupper($values['route_text']), 0, 5000, 'UTF-8');
    $values['remarks'] = mb_substr($values['remarks'], 0, 2000, 'UTF-8');
    if($isTrainingTarget){
        $values['training_id']=(int)$target['training_id'];
        $update=$pdo->prepare(
            "UPDATE atc_training_aircraft SET flight_rules=:flight_rules,flight_type=:flight_type,
             communication_mode=:communication_mode,departure_airport=:departure_airport,
             arrival_airport=:arrival_airport,alternate1_airport=:alternate1_airport,
             alternate2_airport=:alternate2_airport,route_text=:route_text,
             cruising_level=:cruising_level,cruising_speed=:cruising_speed,remarks=:remarks
             WHERE id=:training_id"
        );
    }else{
        $values['token'] = (string)$target['session_token'];
        $values['callsign'] = $callsign;
        $update = $pdo->prepare(
        "INSERT INTO pilot_flightplans
            (session_token,callsign,flight_rules,flight_type,communication_mode,departure_airport,
             arrival_airport,alternate1_airport,alternate2_airport,route_text,
             cruising_level,cruising_speed,remarks)
         VALUES
            (:token,:callsign,:flight_rules,:flight_type,:communication_mode,:departure_airport,
             :arrival_airport,:alternate1_airport,:alternate2_airport,:route_text,
             :cruising_level,:cruising_speed,:remarks)
         ON DUPLICATE KEY UPDATE callsign=VALUES(callsign),
             flight_rules=VALUES(flight_rules),flight_type=VALUES(flight_type),communication_mode=VALUES(communication_mode),
             departure_airport=VALUES(departure_airport),arrival_airport=VALUES(arrival_airport),
             alternate1_airport=VALUES(alternate1_airport),alternate2_airport=VALUES(alternate2_airport),
             route_text=VALUES(route_text),cruising_level=VALUES(cruising_level),
             cruising_speed=VALUES(cruising_speed),remarks=VALUES(remarks),updated_at=NOW()"
        );
    }
    $update->execute($values);
    if(!$isTrainingTarget) try {
        $log = $pdo->prepare(
            "INSERT INTO user_activity_log
             (user_id,actor_user_id,activity_type,activity_key,activity_value)
             VALUES (:user_id,:actor_user_id,'atc','activity_atc_flightplan_changed',:value)"
        );
        $log->execute([
            'user_id' => (int)$target['user_id'],
            'actor_user_id' => (int)$_SESSION['web_user_id'],
            'value' => $callsign . ': ' . $values['departure_airport'] . ' > ' . $values['arrival_airport'],
        ]);
    } catch (Throwable $ignored) {}
    insertChatMessage(
        $pdo,
        null,
        (int)$target['user_id'],
        (int)$_SESSION['web_user_id'],
        'ATC',
        'atc_flightplan',
        'Dein Flugplan wurde durch ATC aktualisiert: '
            . $values['departure_airport'] . ' > ' . $values['arrival_airport']
    );
    atcFplReply(true, ['message' => 'flightplan_saved']);
} catch (Throwable $error) {
    atcFplReply(false, ['message' => 'server_error'], 500);
}
