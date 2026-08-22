<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/config.php';
require_once __DIR__.'/../includes/cpdlc_schema.php';

function cpdlcOut(array $data, int $status=200) { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

try {
    $pdo=new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    ensureCpdlcSchema($pdo);
    $token=trim((string)($_REQUEST['token']??''));
    if($token==='') cpdlcOut(['success'=>false,'message'=>'login_required'],401);
    $stmt=$pdo->prepare("SELECT s.user_id,s.callsign,s.is_spectator FROM user_sessions s WHERE s.token=:token AND s.is_active=1 LIMIT 1");
    $stmt->execute(['token'=>$token]); $pilot=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$pilot) cpdlcOut(['success'=>false,'message'=>'invalid_session'],401);
    $userId=(int)$pilot['user_id']; $callsign=strtoupper(trim((string)$pilot['callsign']));
    $action=strtolower(trim((string)($_REQUEST['action']??'status')));

    $active=function()use($pdo,$token){$s=$pdo->prepare("SELECT * FROM cpdlc_connections WHERE pilot_session_token=:token AND state IN ('requested','connected') ORDER BY id DESC LIMIT 1");$s->execute(['token'=>$token]);return $s->fetch(PDO::FETCH_ASSOC)?:null;};
    if($_SERVER['REQUEST_METHOD']==='POST'&&$action==='logon'){
        if((int)$pilot['is_spectator']===1) cpdlcOut(['success'=>false,'message'=>'cpdlc_spectator_denied'],403);
        $station=strtoupper(trim((string)($_POST['station']??'')));
        if(!preg_match('/^[A-Z0-9_\-]{3,32}$/',$station)) cpdlcOut(['success'=>false,'message'=>'cpdlc_invalid_station'],422);
        if($active()) cpdlcOut(['success'=>false,'message'=>'cpdlc_connection_exists'],409);
        $s=$pdo->prepare("INSERT INTO cpdlc_connections(pilot_user_id,pilot_session_token,pilot_callsign,station_code) VALUES(:uid,:token,:callsign,:station)");
        $s->execute(['uid'=>$userId,'token'=>$token,'callsign'=>$callsign,'station'=>$station]);
    } elseif($_SERVER['REQUEST_METHOD']==='POST'&&in_array($action,['logoff','send','respond'],true)){
        $connection=$active(); if(!$connection) cpdlcOut(['success'=>false,'message'=>'cpdlc_not_connected'],409);
        if($action==='logoff'){
            $pdo->prepare("UPDATE cpdlc_connections SET state='closed',closed_at=NOW(),last_activity_at=NOW() WHERE id=:id")->execute(['id'=>$connection['id']]);
        } else {
            if($connection['state']!=='connected') cpdlcOut(['success'=>false,'message'=>'cpdlc_not_connected'],409);
            $text=trim((string)($_POST['message']??''));
            if($action==='respond'){$allowed=['WILCO','UNABLE','STANDBY','ROGER'];$text=strtoupper($text);if(!in_array($text,$allowed,true))cpdlcOut(['success'=>false,'message'=>'cpdlc_invalid_response'],422);}
            if($text===''||mb_strlen($text)>500) cpdlcOut(['success'=>false,'message'=>'cpdlc_invalid_message'],422);
            $reply=(int)($_POST['reply_to_id']??0);
            $s=$pdo->prepare("INSERT INTO cpdlc_messages(connection_id,sender_role,sender_user_id,message_type,message_text,reply_to_id) VALUES(:cid,'pilot',:uid,:type,:text,:reply)");
            $s->execute(['cid'=>$connection['id'],'uid'=>$userId,'type'=>$action==='respond'?'response':'free_text','text'=>$text,'reply'=>$reply?:null]);
            if($reply)$pdo->prepare("UPDATE cpdlc_messages SET status='responded',responded_at=NOW() WHERE id=:id AND connection_id=:cid")->execute(['id'=>$reply,'cid'=>$connection['id']]);
            $pdo->prepare("UPDATE cpdlc_connections SET last_activity_at=NOW() WHERE id=:id")->execute(['id'=>$connection['id']]);
        }
    }
    $connection=$active(); $messages=[];
    if($connection){$s=$pdo->prepare("SELECT id,sender_role,message_type,message_text,response_options,reply_to_id,status,DATE_FORMAT(created_at,'%H:%iZ') time FROM cpdlc_messages WHERE connection_id=:id ORDER BY id ASC LIMIT 200");$s->execute(['id'=>$connection['id']]);$messages=$s->fetchAll(PDO::FETCH_ASSOC);$pdo->prepare("UPDATE cpdlc_messages SET status='delivered',delivered_at=COALESCE(delivered_at,NOW()) WHERE connection_id=:id AND sender_role='atc' AND status='sent'")->execute(['id'=>$connection['id']]);}
    $out=['success'=>true,'connected'=>$connection!==null,'message_count'=>count($messages)];
    if($connection){
        $out['connection_id']=(int)$connection['id'];
        $out['station_code']=(string)$connection['station_code'];
        $out['connection_state']=(string)$connection['state'];
    }else{
        $out['connection_id']=0;$out['station_code']='';$out['connection_state']='closed';
    }
    foreach($messages as $i=>$message){
        $prefix='message_'.$i.'_';
        $out[$prefix.'id']=(int)$message['id'];
        $out[$prefix.'sender_role']=(string)$message['sender_role'];
        $out[$prefix.'message_type']=(string)$message['message_type'];
        $out[$prefix.'text']=(string)$message['message_text'];
        $out[$prefix.'response_options']=(string)($message['response_options']??'');
        $out[$prefix.'status']=(string)$message['status'];
        $out[$prefix.'time']=(string)$message['time'];
    }
    cpdlcOut($out);
}catch(Throwable $e){cpdlcOut(['success'=>false,'message'=>'server_error'],500);}
