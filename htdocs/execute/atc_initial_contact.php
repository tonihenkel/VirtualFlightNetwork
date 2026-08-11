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

function contactReply(bool $success, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        contactReply(false, 'login_required', 401);
    }
    ensureAtcSchema($pdo);
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
        "SELECT * FROM atc_sessions WHERE user_id=:user_id AND session_token=:token
         AND is_active=1 AND is_spectator=0 AND can_control=1
         AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $stmt->execute(['user_id'=>(int)$_SESSION['web_user_id'], 'token'=>(string)($_SESSION['atc_session_token'] ?? '')]);
    $atc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$atc) contactReply(false, 'atc_control_session_required', 403);
    $pdo->exec("DELETE aa FROM atc_assumed_aircraft aa LEFT JOIN atc_sessions a ON a.id=aa.atc_session_id WHERE a.id IS NULL OR a.is_active=0 OR a.last_seen_at<DATE_SUB(NOW(),INTERVAL 45 SECOND)");

    $callsign = strtoupper(trim((string)($_POST['callsign'] ?? '')));
    $frequency = normalizeChatFrequency((string)($_POST['frequency'] ?? $atc['frequency'] ?? ''));
    $action = strtolower(trim((string)($_POST['action'] ?? 'initial-contact')));
    if ($callsign === '' || ($frequency === null && !in_array($action, ['assume', 'assumed', 'unassume', 'un-assume'], true))) {
        contactReply(false, 'invalid_data', 422);
    }
    $pilotStmt = $pdo->prepare(
        "SELECT p.user_id,p.session_token,
                COALESCE(NULLIF(s.plugin_language,''),NULLIF(u.preferred_language,''),'en') AS plugin_language
         FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token
         INNER JOIN users u ON u.id=p.user_id
         WHERE UPPER(p.callsign)=:callsign AND s.is_active=1 AND s.is_spectator=0
           AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND) LIMIT 1"
    );
    $pilotStmt->execute(['callsign'=>$callsign]);
    $pilot = $pilotStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pilot) contactReply(false, 'pilot_not_online', 404);

    $atcCallsign = strtoupper((string)$atc['callsign']);
    $language = strtolower((string)($pilot['plugin_language'] ?? 'en')) === 'de'
        ? 'de'
        : 'en';
    if (in_array($action, ['assume', 'assumed'], true)) {
        $ownerStatement=$pdo->prepare("SELECT atc_session_id,atc_callsign FROM atc_assumed_aircraft WHERE pilot_session_token=:pilot_token LIMIT 1");
        $ownerStatement->execute(['pilot_token'=>(string)$pilot['session_token']]);
        $existingOwner=$ownerStatement->fetch(PDO::FETCH_ASSOC);
        if($existingOwner&&(int)$existingOwner['atc_session_id']!==(int)$atc['id']) {
            contactReply(false, 'aircraft_already_assumed_by_' . (string)$existingOwner['atc_callsign'], 409);
        }
        $assume = $pdo->prepare(
            "INSERT INTO atc_assumed_aircraft
                (pilot_session_token,pilot_callsign,atc_session_id,atc_user_id,atc_callsign)
             VALUES (:pilot_token,:pilot_callsign,:atc_session_id,:atc_user_id,:atc_callsign)
             ON DUPLICATE KEY UPDATE atc_session_id=VALUES(atc_session_id),
                atc_user_id=VALUES(atc_user_id),atc_callsign=VALUES(atc_callsign),
                pilot_callsign=VALUES(pilot_callsign),assumed_at=NOW(),updated_at=NOW()"
        );
        $assume->execute(['pilot_token'=>(string)$pilot['session_token'],'pilot_callsign'=>$callsign,
            'atc_session_id'=>(int)$atc['id'],'atc_user_id'=>(int)$atc['user_id'],'atc_callsign'=>$atcCallsign]);
        contactReply(true, 'aircraft_assumed');
    }
    if (in_array($action, ['unassume', 'un-assume'], true)) {
        $unassume=$pdo->prepare("DELETE FROM atc_assumed_aircraft WHERE pilot_session_token=:pilot_token AND atc_session_id=:atc_session_id");
        $unassume->execute(['pilot_token'=>(string)$pilot['session_token'],'atc_session_id'=>(int)$atc['id']]);
        contactReply(true, 'aircraft_unassumed');
    }
    if (in_array($action, ['release', 'leave-airspace'], true)) {
        $release=$pdo->prepare("DELETE FROM atc_assumed_aircraft WHERE pilot_session_token=:pilot_token AND atc_session_id=:atc_session_id");
        $release->execute(['pilot_token'=>(string)$pilot['session_token'],'atc_session_id'=>(int)$atc['id']]);
        $text=$language==='de'
            ? '⚠ RELEASE: Du verlässt meinen Luftraum. Wechsle auf UNICOM 122.800 MHz.'
            : '⚠ RELEASE: You are leaving my airspace. Switch to UNICOM on 122.800 MHz.';
    } else {
        $text=$language==='de'
            ? '⚠ FORCE ACT: Bitte wechsle auf '.$frequency.' MHz und melde dich bei '.$atcCallsign.'.'
            : '⚠ FORCE ACT: Switch to '.$frequency.' MHz and contact '.$atcCallsign.'.';
    }
    insertChatMessage($pdo, null, (int)$pilot['user_id'], (int)$atc['user_id'],
        $atcCallsign, 'atc_contact', $text);
    contactReply(true, $text);
} catch (Throwable $error) {
    contactReply(false, 'server_error', 500);
}
