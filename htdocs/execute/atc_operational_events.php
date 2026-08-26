<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';

function operationalEventReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        operationalEventReply(['success' => false, 'message' => 'login_required'], 401);
    }
    $token = (string)($_SESSION['atc_session_token'] ?? '');
    $sessionStmt = $pdo->prepare(
        "SELECT id,user_id,station_code,position_code,radar_boundary_code,is_spectator
         FROM atc_sessions
         WHERE user_id=:user_id AND session_token=:token AND is_active=1
           AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $sessionStmt->execute(['user_id' => (int)$_SESSION['web_user_id'], 'token' => $token]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) operationalEventReply(['success' => false, 'message' => 'atc_session_inactive'], 409);

    $handoffStmt = $pdo->prepare(
        "SELECT id,pilot_callsign,source_callsign,target_callsign,status,created_at,responded_at,
                CASE WHEN target_session_id=:target_id THEN 'incoming' ELSE 'outgoing' END AS direction
         FROM atc_handoff_requests
         WHERE (target_session_id=:target_filter AND status='pending')
            OR (source_session_id=:source_filter AND status IN ('accepted','rejected')
                AND responded_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE))
         ORDER BY id ASC"
    );
    $handoffStmt->execute([
        'target_id'=>(int)$session['id'],
        'target_filter'=>(int)$session['id'],
        'source_filter'=>(int)$session['id'],
    ]);
    $handoffs = $handoffStmt->fetchAll(PDO::FETCH_ASSOC);

    $latestId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM atc_operational_events")->fetchColumn();
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    if (isset($_GET['initialize']) || $sinceId === 0) {
        operationalEventReply(['success' => true, 'events' => [], 'handoffs'=>$handoffs, 'last_id' => $latestId]);
    }

    $scopeStmt = $pdo->prepare(
        "SELECT airport_icao FROM atc_session_atis_airports WHERE session_id=:session_id"
    );
    $scopeStmt->execute(['session_id' => (int)$session['id']]);
    $codes = array_values(array_unique(array_filter(array_map(
        static fn(string $code): string => strtoupper(trim($code)),
        $scopeStmt->fetchAll(PDO::FETCH_COLUMN)
    ))));
    if (!$codes && !in_array(strtoupper((string)$session['position_code']), ['APP', 'DEP', 'CTR'], true)) {
        $codes[] = strtoupper((string)$session['station_code']);
    }
    if (!$codes) operationalEventReply(['success' => true, 'events' => [], 'handoffs'=>$handoffs, 'last_id' => $latestId]);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $pdo->prepare(
        "SELECT id,airport_icao,event_type,old_value,new_value,created_by_callsign,created_at
         FROM atc_operational_events
         WHERE id>? AND airport_icao IN ($placeholders)
         ORDER BY id ASC LIMIT 100"
    );
    $stmt->execute(array_merge([$sinceId], $codes));
    operationalEventReply([
        'success' => true,
        'events' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'handoffs' => $handoffs,
        'last_id' => $latestId
    ]);
} catch (Throwable $error) {
    operationalEventReply(['success' => false, 'message' => $error->getMessage()], 500);
}
