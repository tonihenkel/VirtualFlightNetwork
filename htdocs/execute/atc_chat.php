<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/chat_system.php';
require_once __DIR__ . '/../includes/activity_log.php';

function atcChatModerationExpiry(string $duration): array
{
    $duration=strtolower(trim($duration));
    if($duration==='permanent') return [null,'permanent'];
    if(!preg_match('/^(\d+)(min|h|d|w|mo|y)$/',$duration,$m)) throw new InvalidArgumentException('invalid_duration');
    $units=['min'=>'minutes','h'=>'hours','d'=>'days','w'=>'weeks','mo'=>'months','y'=>'years'];
    $value=(int)$m[1]; if($value<1||($m[2]==='y'&&$value>10)) throw new InvalidArgumentException('invalid_duration');
    return [(new DateTimeImmutable())->modify('+'.$value.' '.$units[$m[2]])->format('Y-m-d H:i:s'),$duration];
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        http_response_code(401);
        throw new RuntimeException('login_required');
    }

    ensureAtcSchema($pdo);
    $sessionStmt = $pdo->prepare(
        "SELECT * FROM atc_sessions
         WHERE user_id=:user_id AND session_token=:token AND is_active=1
           AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
         LIMIT 1"
    );
    $sessionStmt->execute([
        'user_id' => (int)$_SESSION['web_user_id'],
        'token' => (string)($_SESSION['atc_session_token'] ?? ''),
    ]);
    $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        http_response_code(409);
        throw new RuntimeException('atc_session_inactive');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!(int)$session['can_control'] || (int)$session['is_spectator']) {
            http_response_code(403);
            throw new RuntimeException('atc_chat_receive_only');
        }
        $frequency = normalizeChatFrequency((string)($_POST['frequency'] ?? $session['frequency']));
        $message = trim((string)($_POST['message'] ?? ''));
        if ($frequency === null || $message === '') {
            http_response_code(422);
            throw new RuntimeException('invalid_data');
        }

        $senderUserId = (int)$session['user_id'];
        $senderCallsign = strtoupper((string)$session['callsign']);
        $permissionStmt = $pdo->prepare('SELECT op_permission FROM users WHERE id=:id LIMIT 1');
        $permissionStmt->execute(['id'=>$senderUserId]);
        $opPermission = (int)$permissionStmt->fetchColumn();
        $commandReply = static function (string $text, bool $success=true, ?string $openUrl=null): never {
            $payload=['success'=>true,'command_success'=>$success,'message'=>['id'=>0,'sender'=>'SYSTEM','type'=>'system','text'=>$text,'time'=>date('H:i')]];
            if ($openUrl !== null) $payload['open_url']=$openUrl;
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
        };
        if ($message[0] === '/') {
            if (preg_match('/^\/list\s*$/i',$message)) {
                if ($opPermission < 1) $commandReply('Keine Berechtigung.',false);
                $names=$pdo->query("SELECT DISTINCT UPPER(callsign) FROM user_sessions WHERE is_active=1 AND callsign<>'' ORDER BY callsign")->fetchAll(PDO::FETCH_COLUMN);
                $commandReply($names ? 'Online Spieler ('.count($names).'): '.implode(', ',$names) : 'Keine Spieler online.');
            }
            if (preg_match('/^\/msg\s+([A-Z0-9_-]+)\s*:?\s+(.+)$/i',$message,$m)) {
                $target=strtoupper(trim($m[1])); $private=trim($m[2]);
                $stmt=$pdo->prepare('SELECT user_id,callsign FROM user_sessions WHERE UPPER(callsign)=:callsign AND is_active=1 ORDER BY last_seen DESC LIMIT 1');
                $stmt->execute(['callsign'=>$target]); $recipient=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!$recipient) $commandReply('Ziel nicht online.',false);
                insertChatMessage($pdo,null,(int)$recipient['user_id'],$senderUserId,$senderCallsign,'system','[PM] '.$private);
                $commandReply('An '.strtoupper((string)$recipient['callsign']).': '.$private);
            }
            if (preg_match('/^\/announcement\s+(.+)$/i',$message,$m)) {
                if ($opPermission < 1) $commandReply('Keine Berechtigung.',false);
                $ids=$pdo->query('SELECT DISTINCT user_id FROM user_sessions WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN);
                foreach($ids as $id) insertChatMessage($pdo,null,(int)$id,$senderUserId,'ANNOUNCEMENT','system','[ANNOUNCEMENT] '.trim($m[1]));
                $commandReply($ids ? 'Announcement gesendet.' : 'Keine Spieler online.',(bool)$ids);
            }
            if (preg_match('/^\/staff\s+(.+)$/i',$message,$m)) {
                $stmt=$pdo->query('SELECT DISTINCT s.user_id FROM user_sessions s JOIN users u ON u.id=s.user_id WHERE s.is_active=1 AND u.op_permission>1');
                $ids=$stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach($ids as $id) insertChatMessage($pdo,null,(int)$id,$senderUserId,$senderCallsign,'system','[STAFF] '.trim($m[1]));
                $commandReply($ids ? 'Staff-Nachricht gesendet.' : 'Kein Staff online.',(bool)$ids);
            }
            if (preg_match('/^\/playerinfo\s+([A-Z0-9_-]+)\s*$/i',$message,$m)) {
                $stmt=$pdo->prepare('SELECT u.id,u.username,s.callsign FROM users u LEFT JOIN user_sessions s ON s.user_id=u.id AND s.is_active=1 WHERE UPPER(s.callsign)=:v OR UPPER(u.username)=:v ORDER BY s.is_active DESC,s.last_seen DESC LIMIT 1');
                $stmt->execute(['v'=>strtoupper($m[1])]); $target=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$target)$commandReply('Spieler nicht gefunden.',false);
                $name=strtoupper(trim((string)$target['callsign'])) ?: (string)$target['username'];
                $commandReply('Profil wird geöffnet: '.$name,true,'/profile.php?id='.(int)$target['id']);
            }
            if (preg_match('/^\/kick\s+([A-Z0-9_-]+)(?:\s+(.+))?$/i',$message,$m)) {
                if ($opPermission < 1) $commandReply('Keine Berechtigung.',false);
                $stmt=$pdo->prepare('SELECT s.user_id,s.token,s.callsign,u.op_permission FROM user_sessions s JOIN users u ON u.id=s.user_id WHERE UPPER(s.callsign)=:v AND s.is_active=1 ORDER BY s.last_seen DESC LIMIT 1');
                $stmt->execute(['v'=>strtoupper($m[1])]);$target=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$target)$commandReply('Ziel nicht online.',false);
                if((int)$target['user_id']===$senderUserId||(int)$target['op_permission'] >= $opPermission)$commandReply('Moderation dieses Spielers nicht erlaubt.',false);
                $reason=trim((string)($m[2]??'')) ?: 'Kein Grund angegeben.';
                insertChatMessage($pdo,null,(int)$target['user_id'],$senderUserId,'ADMIN','system','Du wurdest aus dem Netzwerk gekickt. Grund: '.$reason);
                logActivity($pdo,(int)$target['user_id'],'system','activity_kicked','Grund: '.$reason,$senderUserId);
                $pdo->prepare('UPDATE user_sessions SET is_active=0,last_seen=NOW() WHERE token=:token')->execute(['token'=>$target['token']]);
                $pdo->prepare("UPDATE pilot_flights SET status='aborted',completed_at=NOW() WHERE session_token=:token AND status='active'")->execute(['token'=>$target['token']]);
                $pdo->prepare('DELETE FROM pilot_positions WHERE session_token=:token')->execute(['token'=>$target['token']]);
                $pdo->prepare('DELETE FROM pilot_tracks WHERE session_token=:token')->execute(['token'=>$target['token']]);
                $commandReply(strtoupper((string)$target['callsign']).' wurde gekickt. Grund: '.$reason);
            }
            if (preg_match('/^\/(warn|ban)\s+([A-Z0-9_-]+)\s+(?:(permanent|\d+(?:min|h|d|w|mo|y))\s+)?(.+)$/i',$message,$m)) {
                if($opPermission<1)$commandReply('Keine Berechtigung.',false);
                $action=strtolower($m[1]);$duration=trim((string)($m[3]??''));$reason=trim($m[4]);
                if($duration===''&&$action==='warn')$duration='permanent';
                if($duration===''||$reason==='')$commandReply('Grund oder Dauer fehlt.',false);
                try{[$expires,$durationLabel]=atcChatModerationExpiry($duration);}catch(InvalidArgumentException $e){$commandReply('Ungültige Dauer.',false);}
                $stmt=$pdo->prepare('SELECT s.user_id,s.callsign,u.op_permission FROM user_sessions s JOIN users u ON u.id=s.user_id WHERE UPPER(s.callsign)=:v AND s.is_active=1 ORDER BY s.last_seen DESC LIMIT 1');
                $stmt->execute(['v'=>strtoupper($m[2])]);$target=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$target)$commandReply('Ziel nicht online.',false);
                if((int)$target['user_id']===$senderUserId||(int)$target['op_permission'] >= $opPermission)$commandReply('Moderation dieses Spielers nicht erlaubt.',false);
                if($action==='warn'){
                    $pdo->prepare('INSERT INTO user_warnings(user_id,issued_by_user_id,reason,expires_at) VALUES(:uid,:actor,:reason,:expires)')->execute(['uid'=>$target['user_id'],'actor'=>$senderUserId,'reason'=>$reason,'expires'=>$expires]);
                    logActivity($pdo,(int)$target['user_id'],'warning','activity_warning_issued',$reason.' ['.$durationLabel.']',$senderUserId);
                    insertChatMessage($pdo,null,(int)$target['user_id'],$senderUserId,'ADMIN','system','Du wurdest verwarnt. Grund: '.$reason);
                }else{
                    $pdo->prepare('UPDATE users SET is_banned=1,ban_reason=:reason,ban_expires_at=:expires,banned_at=NOW(),banned_by_user_id=:actor WHERE id=:uid')->execute(['reason'=>$reason,'expires'=>$expires,'actor'=>$senderUserId,'uid'=>$target['user_id']]);
                    logActivity($pdo,(int)$target['user_id'],'ban','activity_banned',$reason.' ['.$durationLabel.']',$senderUserId);
                    $pdo->prepare('UPDATE user_sessions SET is_active=0,last_seen=NOW() WHERE user_id=:uid AND is_active=1')->execute(['uid'=>$target['user_id']]);
                    $pdo->prepare("UPDATE pilot_flights SET status='aborted',completed_at=NOW() WHERE user_id=:uid AND status='active'")->execute(['uid'=>$target['user_id']]);
                    $pdo->prepare('DELETE FROM pilot_positions WHERE user_id=:uid')->execute(['uid'=>$target['user_id']]);
                }
                $commandReply(strtoupper((string)$target['callsign']).($action==='warn'?' wurde verwarnt.':' wurde gebannt.'));
            }
            if (preg_match('/^\/(msg|playerinfo|kick|warn|ban|list|announcement|staff)\b/i',$message,$m)) {
                $syntax=['msg'=>'/msg CALLSIGN : Nachricht','playerinfo'=>'/playerinfo CALLSIGN','kick'=>'/kick CALLSIGN [Grund]','warn'=>'/warn CALLSIGN [Dauer] Grund','ban'=>'/ban CALLSIGN Dauer Grund','list'=>'/list','announcement'=>'/announcement Nachricht','staff'=>'/staff Nachricht'];
                $commandReply('Syntax: '.$syntax[strtolower($m[1])],false);
            }
            $name=strtok($message," \t") ?: $message;
            $commandReply('Unbekanntes Kommando: '.$name,false);
        }

        $latitude = null;
        $longitude = null;
        $rangeNm = null;
        if ($frequency !== '122.800') {
            $airportStmt = $pdo->prepare(
                "SELECT latitude_deg, longitude_deg FROM airports
                 WHERE UPPER(ident)=:code1 OR UPPER(icao_code)=:code2 OR UPPER(gps_code)=:code3
                 LIMIT 1"
            );
            $station = strtoupper((string)$session['station_code']);
            $airportStmt->execute(['code1'=>$station, 'code2'=>$station, 'code3'=>$station]);
            $airport = $airportStmt->fetch(PDO::FETCH_ASSOC);
            if ($airport) {
                $latitude = (float)$airport['latitude_deg'];
                $longitude = (float)$airport['longitude_deg'];
                $ranges = [
                    'airport_info'=>35, 'airport_delivery'=>25, 'airport_ground'=>25,
                    'airport_tower'=>60, 'terminal_approach'=>180,
                    'terminal_departure'=>180, 'enroute_center'=>350,
                ];
                $rangeNm = (float)($ranges[(string)$session['map_profile']] ?? 60);
            } else {
                // For en-route sectors, anchor the transmission near a tuned pilot.
                // This prevents a same-frequency controller on another continent from
                // being selected by clients while still covering the local sector.
                $pilotStmt = $pdo->prepare(
                    "SELECT latitude, longitude FROM pilot_positions
                     WHERE last_update >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
                       AND (com1=:frequency1 OR com2=:frequency2)
                     ORDER BY last_update DESC LIMIT 1"
                );
                $pilotStmt->execute(['frequency1'=>$frequency, 'frequency2'=>$frequency]);
                if ($pilot = $pilotStmt->fetch(PDO::FETCH_ASSOC)) {
                    $latitude = (float)$pilot['latitude'];
                    $longitude = (float)$pilot['longitude'];
                    $rangeNm = 350.0;
                }
            }
        }

        $text = insertChatMessage(
            $pdo,
            $frequency,
            null,
            (int)$session['user_id'],
            (string)$session['callsign'],
            'staff',
            $message,
            $latitude,
            $longitude,
            $rangeNm
        );
        echo json_encode([
            'success'=>true,
            'message'=>[
                'id'=>(int)$pdo->lastInsertId(),
                'frequency'=>$frequency,
                'sender'=>(string)$session['callsign'],
                'type'=>'staff',
                'text'=>$text,
                'time'=>date('H:i'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $frequency = normalizeChatFrequency((string)($_GET['frequency'] ?? $session['frequency']));
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    if ($frequency === null) {
        http_response_code(422);
        throw new RuntimeException('invalid_frequency');
    }
    $maxId = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM chat_messages')->fetchColumn();
    if ($sinceId <= 0) {
        echo json_encode(['success'=>true, 'last_id'=>$maxId, 'messages'=>[]]);
        exit;
    }
    if ($sinceId > $maxId) $sinceId = max(0, $maxId - 30);
    $messageStmt = $pdo->prepare(
        "SELECT id, frequency, sender_callsign AS sender, message_type AS type,
                message_text AS text, DATE_FORMAT(created_at,'%H:%i') AS time
         FROM chat_messages
         WHERE id>:since_id AND id<=:max_id AND frequency=:frequency
           AND recipient_user_id IS NULL
         ORDER BY id ASC LIMIT 100"
    );
    $messageStmt->execute(['since_id'=>$sinceId, 'max_id'=>$maxId, 'frequency'=>$frequency]);
    echo json_encode([
        'success'=>true,
        'last_id'=>$maxId,
        'messages'=>$messageStmt->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>$error->getMessage()]);
}
