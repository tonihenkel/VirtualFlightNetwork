<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_atis.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';

function atisReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanAtisValue(string $value, int $limit): string
{
    $value = trim((string)preg_replace('/\s+/u', ' ', $value));
    return mb_substr($value, 0, $limit, 'UTF-8');
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        atisReply(['success' => false, 'message' => 'login_required'], 401);
    }
    $sessionToken = (string)($_SESSION['atc_session_token'] ?? '');
    $sessionStmt = $pdo->prepare(
        "SELECT station_code, position_code, radar_boundary_code, is_spectator, is_trainer, can_control, user_id
         FROM atc_sessions
         WHERE user_id=:user_id AND session_token=:token AND is_active=1
           AND last_seen_at>=DATE_SUB(NOW(), INTERVAL 30 SECOND)
         LIMIT 1"
    );
    $sessionStmt->execute([
        'user_id' => (int)$_SESSION['web_user_id'],
        'token' => $sessionToken
    ]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) atisReply(['success' => false, 'message' => 'atc_session_inactive'], 409);
    // Preparing controllers may configure ATIS and airport runways before
    // becoming visible. This does not grant traffic-control or voice rights.
    if ((int)$session['is_spectator'] === 1 && (int)$session['is_trainer'] !== 1) {
        atisReply(['success' => false, 'message' => 'atc_atis_permission_denied'], 403);
    }
    $station = strtoupper(trim((string)($_REQUEST['airport'] ?? $session['station_code'])));
    if (!preg_match('/^[A-Z0-9-]{3,12}$/', $station)) {
        atisReply(['success' => false, 'message' => 'atc_atis_airport_required'], 409);
    }
    $includeSmall = (string)($_REQUEST['include_small'] ?? '') === '1';
    $allowedAirports = getAtisAirportsForSession($pdo, $session, $includeSmall);
    $allowed = null;
    foreach ($allowedAirports as $candidate) {
        if ((string)$candidate['icao'] === $station) { $allowed = $candidate; break; }
    }
    if ($allowed === null) atisReply(['success'=>false,'message'=>'atc_atis_airport_outside_scope'],403);
    if (empty($allowed['editable'])) atisReply(['success'=>false,'message'=>'atc_atis_managed_locally'],409);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = strtolower(trim((string)($_POST['action'] ?? 'save')));
        if ($action === 'automatic') {
            $stmt = $pdo->prepare(
                "INSERT INTO atc_atis_overrides (airport_icao,updated_by,is_active)
                 VALUES (:airport,:user_id,0)
                 ON DUPLICATE KEY UPDATE updated_by=VALUES(updated_by),is_active=0,updated_at=NOW()"
            );
            $stmt->execute(['airport' => $station, 'user_id' => (int)$_SESSION['web_user_id']]);
        } else {
            $arrival = strtoupper(cleanAtisValue((string)($_POST['arrival_runways'] ?? ''), 64));
            $departure = strtoupper(cleanAtisValue((string)($_POST['departure_runways'] ?? ''), 64));
            $transitionLevel = strtoupper(cleanAtisValue((string)($_POST['transition_level'] ?? ''), 16));
            $transitionAltitude = strtoupper(cleanAtisValue((string)($_POST['transition_altitude'] ?? ''), 16));
            $approach = cleanAtisValue((string)($_POST['approach_type'] ?? ''), 64);
            $remarks = cleanAtisValue((string)($_POST['remarks'] ?? ''), 500);
            foreach ([$arrival, $departure] as $runways) {
                if ($runways !== '' && !preg_match('/^[0-9]{1,2}[LCR]?(?:[ ,\/]+[0-9]{1,2}[LCR]?)*$/', $runways)) {
                    atisReply(['success' => false, 'message' => 'atc_atis_invalid_runway'], 422);
                }
            }
            if ($transitionLevel !== '' && !preg_match('/^(?:FL\s*)?[0-9]{2,3}$/', $transitionLevel)) {
                atisReply(['success' => false, 'message' => 'atc_atis_invalid_transition_level'], 422);
            }
            if ($transitionAltitude !== '' && !preg_match('/^[0-9]{3,5}(?:\s*FT)?$/', $transitionAltitude)) {
                atisReply(['success' => false, 'message' => 'atc_atis_invalid_transition_altitude'], 422);
            }
            $stmt = $pdo->prepare(
                "INSERT INTO atc_atis_overrides
                    (airport_icao,updated_by,arrival_runways,departure_runways,
                     transition_level,transition_altitude,approach_type,remarks,is_active)
                 VALUES (:airport,:user_id,:arrival,:departure,:tl,:ta,:approach,:remarks,1)
                 ON DUPLICATE KEY UPDATE updated_by=VALUES(updated_by),
                    arrival_runways=VALUES(arrival_runways),departure_runways=VALUES(departure_runways),
                    transition_level=VALUES(transition_level),transition_altitude=VALUES(transition_altitude),
                    approach_type=VALUES(approach_type),remarks=VALUES(remarks),is_active=1,updated_at=NOW()"
            );
            $stmt->execute([
                'airport'=>$station, 'user_id'=>(int)$_SESSION['web_user_id'],
                'arrival'=>$arrival, 'departure'=>$departure, 'tl'=>$transitionLevel,
                'ta'=>$transitionAltitude, 'approach'=>$approach, 'remarks'=>$remarks
            ]);
        }

    }

    $stmt = $pdo->prepare(
        "SELECT o.*, COALESCE(NULLIF(TRIM(u.real_name),''),u.username) AS updated_by_name
         FROM atc_atis_overrides o LEFT JOIN users u ON u.id=o.updated_by
         WHERE o.airport_icao=:airport LIMIT 1"
    );
    $stmt->execute(['airport'=>$station]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'airport_icao'=>$station, 'arrival_runways'=>'', 'departure_runways'=>'',
        'transition_level'=>'', 'transition_altitude'=>'', 'approach_type'=>'',
        'remarks'=>'', 'is_active'=>0, 'updated_at'=>null, 'updated_by_name'=>''
    ];
    $statusStmt = $pdo->prepare(
        "SELECT frequency,info_letter,active_runway,atis_text,is_active,updated_at
         FROM auto_atis_broadcasts WHERE airport_icao=:airport LIMIT 1"
    );
    try {
        $statusStmt->execute(['airport'=>$station]);
        $broadcast = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $ignored) { $broadcast = null; }
    atisReply(['success'=>true,'airport'=>$station,'settings'=>$settings,'broadcast'=>$broadcast]);
} catch (Throwable $error) {
    atisReply(['success'=>false,'message'=>$error->getMessage()], 500);
}
