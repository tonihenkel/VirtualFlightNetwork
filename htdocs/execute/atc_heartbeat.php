<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_frequency_catalog.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        http_response_code(401); throw new RuntimeException('login_required');
    }
    // Mobile browsers (notably Firefox on Android) suspend JavaScript timers
    // while the app is in the background. Keep the controller's position
    // resumable for a short grace period instead of treating one missed
    // 30-second heartbeat as an explicit logout.
    $pdo->exec("UPDATE atc_sessions SET is_active=0, disconnected_at=NOW()
                WHERE is_active=1 AND (last_seen_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                   OR (is_ready=0 AND connected_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))");
    archiveAtcSessions($pdo, "a.is_active=0 AND a.disconnected_at IS NOT NULL");
    $token = (string)($_SESSION['atc_session_token'] ?? '');
    $stmt = $pdo->prepare(
        "SELECT a.id, a.user_id, a.callsign, a.station_code, a.position_code, a.is_gca, a.is_spectator, a.is_trainer, a.is_invisible,
                a.can_control, a.can_transmit_voice, a.scope_positions, a.map_profile, a.radar_boundary_code,
                a.frequency, a.atis_scope_ready, a.is_ready, a.connected_at, u.op_permission
         FROM atc_sessions a
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.user_id=:user_id AND a.session_token=:token AND a.is_active=1 LIMIT 1"
    );
    $stmt->execute(['user_id'=>(int)$_SESSION['web_user_id'], 'token'=>$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) { http_response_code(409); throw new RuntimeException('atc_session_inactive'); }
    if (empty($atcLoginEnabled) && (int)$session['op_permission'] < 5) {
        $pdo->prepare(
            "UPDATE user_sessions SET is_active=0
             WHERE user_id=:user_id AND callsign=:callsign AND is_active=1"
        )->execute([
            'user_id'=>(int)$session['user_id'],
            'callsign'=>(string)$session['callsign'],
        ]);
        $pdo->prepare(
            "UPDATE atc_sessions SET is_active=0, disconnected_at=NOW() WHERE id=:id"
        )->execute(['id'=>(int)$session['id']]);
        http_response_code(403);
        throw new RuntimeException('atc_login_disabled');
    }
    if ((!(int)$session['is_spectator'] || (int)$session['is_trainer']) && !(int)$session['atis_scope_ready']) {
            $storeScope = $pdo->prepare(
                "INSERT IGNORE INTO atc_session_atis_airports
                 (session_id,airport_icao,frequency,airport_name,latitude,longitude)
                 VALUES (:session_id,:icao,:frequency,:name,:latitude,:longitude)"
            );
            foreach (getAtisAirportsForSession($pdo, $session) as $airport) {
                $storeScope->execute([
                    'session_id'=>(int)$session['id'], 'icao'=>$airport['icao'],
                    'frequency'=>$airport['frequency'], 'name'=>$airport['name'],
                    'latitude'=>$airport['latitude'], 'longitude'=>$airport['longitude'],
                ]);
            }
            $pdo->prepare("UPDATE atc_sessions SET atis_scope_ready=1 WHERE id=:id")
                ->execute(['id'=>(int)$session['id']]);
    }
    $session['available_frequencies'] = findAtcFrequencies(
        $pdo,
        (string)$session['station_code'],
        (string)$session['position_code']
    );
    if (trim((string)($session['frequency'] ?? '')) === '' && !empty($session['available_frequencies'])) {
        $session['frequency'] = (string)$session['available_frequencies'][0]['frequency'];
        $pdo->prepare("UPDATE atc_sessions SET frequency=:frequency WHERE id=:id")
            ->execute(['frequency'=>$session['frequency'],'id'=>(int)$session['id']]);
    }
    $pdo->prepare("UPDATE atc_sessions SET last_seen_at=NOW() WHERE id=:id")->execute(['id'=>(int)$session['id']]);
    $showInvisible = ((int)$session['op_permission'] >= 1
        && (string)($_COOKIE['vfn_atc_hide_invisible'] ?? '1') === '0') ? 1 : 0;
    $activeStmt = $pdo->prepare(
        "SELECT a.user_id,a.callsign, a.station_code, a.position_code, a.frequency, a.is_gca, a.is_spectator, a.is_trainer, a.is_invisible,u.op_permission,
                COALESCE(NULLIF(TRIM(u.real_name), ''), u.username) AS controller_name,
                TIMESTAMPDIFF(SECOND, a.connected_at, NOW()) AS online_seconds
         FROM atc_sessions a
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.is_active=1 AND a.is_ready=1
           AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
           AND (a.is_invisible=0 OR (:show_invisible=1 AND u.op_permission <= :viewer_op))
         ORDER BY a.station_code, a.position_code, a.callsign"
    );
    $activeStmt->execute([
        'show_invisible'=>$showInvisible,
        'viewer_op'=>(int)$session['op_permission'],
    ]);
    $active = $activeStmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'session'=>$session,'active_positions'=>$active], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code()<400) http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$error->getMessage()]);
}
