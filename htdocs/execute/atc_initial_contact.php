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
    $stmt = $pdo->prepare(
        "SELECT * FROM atc_sessions WHERE user_id=:user_id AND session_token=:token
         AND is_active=1 AND is_spectator=0 AND can_control=1
         AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $stmt->execute(['user_id'=>(int)$_SESSION['web_user_id'], 'token'=>(string)($_SESSION['atc_session_token'] ?? '')]);
    $atc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$atc) contactReply(false, 'atc_control_session_required', 403);

    $callsign = strtoupper(trim((string)($_POST['callsign'] ?? '')));
    $frequency = normalizeChatFrequency((string)($_POST['frequency'] ?? $atc['frequency'] ?? ''));
    if ($callsign === '' || $frequency === null) contactReply(false, 'invalid_data', 422);
    $pilotStmt = $pdo->prepare(
        "SELECT p.user_id FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token
         WHERE UPPER(p.callsign)=:callsign AND s.is_active=1 AND s.is_spectator=0
           AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND) LIMIT 1"
    );
    $pilotStmt->execute(['callsign'=>$callsign]);
    $pilot = $pilotStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pilot) contactReply(false, 'pilot_not_online', 404);

    $atcCallsign = strtoupper((string)$atc['callsign']);
    $text = '⚠ INITIAL CONTACT: Bitte wechsle auf ' . $frequency . ' MHz und melde dich bei ' . $atcCallsign . '.';
    insertChatMessage($pdo, null, (int)$pilot['user_id'], (int)$atc['user_id'],
        $atcCallsign, 'atc_contact', $text);
    contactReply(true, $text);
} catch (Throwable $error) {
    contactReply(false, 'server_error', 500);
}
