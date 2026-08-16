<?php
declare(strict_types=1);

function findOnlineNetworkTarget(PDO $pdo, string $identifier): ?array
{
    $identifier = strtoupper(trim($identifier));
    if ($identifier === '') return null;
    $stmt = $pdo->prepare(
        "SELECT x.user_id,x.callsign,x.session_kind,x.session_key,u.op_permission,u.username
         FROM (
            SELECT user_id,callsign,'pilot' session_kind,token session_key,last_seen seen
            FROM user_sessions WHERE is_active=1 AND UPPER(callsign)=:pilot
            UNION ALL
            SELECT user_id,callsign,'atc' session_kind,session_token session_key,last_seen_at seen
            FROM atc_sessions WHERE is_active=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
              AND UPPER(callsign)=:atc
         ) x JOIN users u ON u.id=x.user_id
         ORDER BY x.seen DESC LIMIT 1"
    );
    $stmt->execute(['pilot'=>$identifier,'atc'=>$identifier]);
    $target=$stmt->fetch(PDO::FETCH_ASSOC);
    return $target ?: null;
}

function disconnectNetworkUser(PDO $pdo, int $userId): void
{
    $pdo->prepare("UPDATE user_sessions SET is_active=0,last_seen=NOW() WHERE user_id=:uid AND is_active=1")->execute(['uid'=>$userId]);
    $pdo->prepare("UPDATE atc_sessions SET is_active=0,disconnected_at=COALESCE(disconnected_at,NOW()) WHERE user_id=:uid AND is_active=1")->execute(['uid'=>$userId]);
    if (function_exists('archiveAtcSessions')) {
        archiveAtcSessions($pdo, 'a.user_id=:history_user AND a.is_active=0', ['history_user'=>$userId]);
    }
    $pdo->prepare("UPDATE pilot_flights SET status='aborted',completed_at=NOW() WHERE user_id=:uid AND status='active'")->execute(['uid'=>$userId]);
    $pdo->prepare('DELETE FROM pilot_positions WHERE user_id=:uid')->execute(['uid'=>$userId]);
    $pdo->prepare('DELETE FROM pilot_tracks WHERE user_id=:uid')->execute(['uid'=>$userId]);
}
