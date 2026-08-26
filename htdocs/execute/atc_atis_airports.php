<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        http_response_code(401); throw new RuntimeException('login_required');
    }
    $stmt = $pdo->prepare(
        "SELECT station_code,position_code,radar_boundary_code,is_spectator,is_trainer,can_control,user_id
         FROM atc_sessions WHERE user_id=:user AND session_token=:token AND is_active=1
           AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $stmt->execute(['user'=>(int)$_SESSION['web_user_id'], 'token'=>(string)($_SESSION['atc_session_token'] ?? '')]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) { http_response_code(409); throw new RuntimeException('atc_session_inactive'); }
    // ATIS/runway preparation is allowed before the position is operational.
    // Spectators remain read-only; `can_control` intentionally stays false in phase 1.
    if ((int)$session['is_spectator'] && !(int)$session['is_trainer']) {
        http_response_code(403); throw new RuntimeException('atc_atis_permission_denied');
    }
    $includeSmall = (string)($_GET['include_small'] ?? '') === '1';
    echo json_encode(['success'=>true,'airports'=>getAtisAirportsForSession($pdo,$session,$includeSmall)],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);
}
