<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/ratings.php';

try {
    $pdo = createAdminPdo();
    $actor = requireAdminUser($pdo, 1);

    // Phase-1 reservations are limited to ten minutes even when this admin
    // view is the first request after their controller client disappeared.
    $pdo->exec(
        "UPDATE atc_sessions SET is_active=0,disconnected_at=NOW()
         WHERE is_active=1 AND is_ready=0
           AND connected_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)"
    );

    $stmt = $pdo->query(
        "SELECT u.id,u.username,u.real_name,u.op_permission,
                u.rating_pilot,u.rating_atc,u.rating_special,
                EXISTS(
                    SELECT 1 FROM user_sessions s
                    WHERE s.user_id=u.id AND s.is_active=1
                      AND s.last_seen>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
                ) AS network_online
         FROM users u
         WHERE EXISTS(
                 SELECT 1 FROM user_sessions s
                 WHERE s.user_id=u.id AND s.is_active=1
                   AND s.last_seen>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
               )
            OR EXISTS(
                 SELECT 1 FROM atc_sessions a
                 WHERE a.user_id=u.id AND a.is_active=1
                   AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
               )
         ORDER BY COALESCE(NULLIF(TRIM(u.real_name),''),u.username),u.username"
    );
    $users = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pilot = getPilotRating((int)$row['rating_pilot']);
        $atc = getAtcRating((int)$row['rating_atc']);
        $special = getSpecialRating((int)$row['rating_special']);
        $users[(int)$row['id']] = [
            'id'=>(int)$row['id'], 'username'=>(string)$row['username'],
            'real_name'=>(string)($row['real_name'] ?? ''),
            'op_permission'=>(int)$row['op_permission'],
            'rating_pilot'=>(int)$row['rating_pilot'],
            'rating_atc'=>(int)$row['rating_atc'],
            'rating_special'=>(int)$row['rating_special'],
            'pilot_rank'=>$pilot['code'].' – '.$pilot['name'],
            'atc_rank'=>$atc['code'].' – '.$atc['name'],
            'special_rank'=>$special ? $special['code'].' – '.$special['name'] : '',
            'roles'=>[], 'can_moderate'=>(int)$row['id'] !== (int)$actor['id']
                && (int)$row['op_permission'] < (int)$actor['op_permission'],
        ];
    }

    if ($users) {
        $ids = array_keys($users);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $sessions = $pdo->prepare(
            "SELECT s.user_id,s.callsign,s.is_spectator,s.is_invisible,
                    EXISTS(SELECT 1 FROM pilot_positions p
                           WHERE p.session_token=s.token
                             AND p.last_update>=DATE_SUB(NOW(),INTERVAL 30 SECOND)) AS has_pilot
             FROM user_sessions s WHERE s.user_id IN ($marks) AND s.is_active=1
               AND s.last_seen>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
               AND (
                   EXISTS(SELECT 1 FROM pilot_positions p
                          WHERE p.session_token=s.token
                            AND p.last_update>=DATE_SUB(NOW(),INTERVAL 30 SECOND))
                   OR (s.is_spectator=1 AND NOT EXISTS(
                       SELECT 1 FROM atc_sessions a WHERE a.user_id=s.user_id
                         AND a.is_active=1 AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
                   ))
               )"
        );
        $sessions->execute($ids);
        foreach ($sessions->fetchAll(PDO::FETCH_ASSOC) as $session) {
            $id = (int)$session['user_id'];
            if (!isset($users[$id])) continue;
            $users[$id]['roles'][] = [
                'type'=>(int)$session['has_pilot'] ? 'pilot' : 'spectator',
                'callsign'=>(string)($session['callsign'] ?? ''),
            ];
        }
        $atcSessions = $pdo->prepare(
            "SELECT user_id,callsign,station_code,position_code,is_spectator,is_trainer,is_ready
             FROM atc_sessions WHERE user_id IN ($marks) AND is_active=1
               AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)"
        );
        $atcSessions->execute($ids);
        foreach ($atcSessions->fetchAll(PDO::FETCH_ASSOC) as $session) {
            $id = (int)$session['user_id'];
            if (!isset($users[$id])) continue;
            $type = (int)$session['is_trainer'] ? 'trainer'
                : ((int)$session['is_spectator'] ? 'atc_spectator' : 'controller');
            $users[$id]['roles'][] = [
                'type'=>$type, 'callsign'=>(string)$session['callsign'],
                'station'=>(string)$session['station_code'],
                'position'=>(string)$session['position_code'],
                'phase'=>(int)$session['is_ready'] ? 2 : 1,
            ];
        }
    }

    echo json_encode(['success'=>true,'users'=>array_values($users),'timeout_minutes'=>10],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$error->getMessage()], JSON_UNESCAPED_UNICODE);
}
