<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_frequency_catalog.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        http_response_code(401); throw new RuntimeException('login_required');
    }
    $stmt = $pdo->prepare(
        "SELECT a.*,u.op_permission FROM atc_sessions a JOIN users u ON u.id=a.user_id
         WHERE a.user_id=:user AND a.session_token=:token AND a.is_active=1 LIMIT 1"
    );
    $stmt->execute([
        'user' => (int)$_SESSION['web_user_id'],
        'token' => (string)($_SESSION['atc_session_token'] ?? ''),
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) { http_response_code(409); throw new RuntimeException('atc_session_inactive'); }
    if ((!(int)$session['is_spectator'] || (int)$session['is_trainer'])
        && !(int)$session['is_ready']) {
        $pdo->prepare(
            "UPDATE atc_sessions SET is_ready=1,can_control=1,can_transmit_voice=1,
                    connected_at=NOW(),last_seen_at=NOW()
             WHERE id=:id"
        )->execute(['id' => (int)$session['id']]);
        $session['is_ready'] = 1; $session['can_control'] = 1; $session['can_transmit_voice'] = 1;
        $voiceToken = (string)($_SESSION['web_voice_token'] ?? '');
        if ($voiceToken !== '') {
            $pdo->prepare(
                "UPDATE user_sessions SET callsign=:callsign,is_spectator=0
                 WHERE user_id=:user AND token=:token AND is_active=1"
            )->execute(['callsign'=>(string)$session['callsign'], 'user'=>(int)$session['user_id'], 'token'=>$voiceToken]);
        }
    }
    $session['available_frequencies'] = findAtcFrequencies(
        $pdo, (string)$session['station_code'], (string)$session['position_code']
    );
    echo json_encode(['success'=>true,'session'=>$session], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$error->getMessage()], JSON_UNESCAPED_UNICODE);
}
