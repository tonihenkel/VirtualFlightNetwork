<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';

function clearanceReply(bool $success, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$success], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo=new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if(empty($_SESSION['web_user_id'])||!validateVfnWebSession($pdo))clearanceReply(false,['message'=>'login_required'],401);
    ensureAtcSchema($pdo);
    $atcStatement=$pdo->prepare("SELECT id,user_id,callsign FROM atc_sessions WHERE user_id=:user_id AND session_token=:token AND is_active=1 AND is_spectator=0 AND can_control=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1");
    $atcStatement->execute(['user_id'=>(int)$_SESSION['web_user_id'],'token'=>(string)($_SESSION['atc_session_token']??'')]);
    $atc=$atcStatement->fetch(PDO::FETCH_ASSOC);
    if(!$atc)clearanceReply(false,['message'=>'atc_control_session_required'],403);
    $callsign=strtoupper(trim((string)($_POST['callsign']??'')));
    if($callsign===''||!preg_match('/^[A-Z0-9_-]{2,24}$/',$callsign))clearanceReply(false,['message'=>'invalid_callsign'],422);
    $pilotStatement=$pdo->prepare("SELECT p.session_token FROM pilot_positions p JOIN user_sessions s ON s.token=p.session_token WHERE UPPER(p.callsign)=:callsign AND s.is_active=1 AND s.is_spectator=0 AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND) LIMIT 1");
    $pilotStatement->execute(['callsign'=>$callsign]);$pilot=$pilotStatement->fetch(PDO::FETCH_ASSOC);
    if(!$pilot)clearanceReply(false,['message'=>'pilot_not_online'],404);
    $ownerStatement=$pdo->prepare("SELECT atc_session_id FROM atc_assumed_aircraft WHERE pilot_session_token=:token LIMIT 1");
    $ownerStatement->execute(['token'=>(string)$pilot['session_token']]);$owner=$ownerStatement->fetch(PDO::FETCH_ASSOC);
    if(!$owner||(int)$owner['atc_session_id']!==(int)$atc['id'])clearanceReply(false,['message'=>'aircraft_must_be_assumed'],409);
    $sid=mb_substr(strtoupper(trim((string)($_POST['cleared_sid']??''))),0,80,'UTF-8');
    $runway=strtoupper(trim((string)($_POST['cleared_departure_runway']??'')));
    $runway=preg_replace('/^(?:RWY?|RUNWAY)\s*/','',$runway);
    if($runway!==''){
        if(!preg_match('/^(\d{1,2})([LCR]?)$/',$runway,$runwayMatch)||(int)$runwayMatch[1]<1||(int)$runwayMatch[1]>36)clearanceReply(false,['message'=>'invalid_departure_runway'],422);
        $runway=sprintf('%02d',(int)$runwayMatch[1]).$runwayMatch[2];
    }
    $direct=mb_substr(strtoupper(trim((string)($_POST['cleared_direct']??''))),0,80,'UTF-8');
    $star=mb_substr(strtoupper(trim((string)($_POST['cleared_star']??''))),0,80,'UTF-8');
    $altitude=mb_substr(strtoupper(trim((string)($_POST['cleared_altitude']??''))),0,20,'UTF-8');
    if($runway===''&&$sid===''&&$direct===''&&$star===''&&$altitude==='')clearanceReply(false,['message'=>'clearance_fields_required'],422);
    $legacyType=$sid!==''?'SID':($direct!==''?'DIRECT':($star!==''?'STAR':'DIRECT'));
    $legacyValue=$sid!==''?$sid:($direct!==''?$direct:$star);
    $save=$pdo->prepare("INSERT INTO atc_aircraft_clearances (pilot_session_token,pilot_callsign,clearance_type,clearance_value,cleared_departure_runway,cleared_sid,cleared_direct,cleared_star,cleared_altitude,issued_by_user_id,issued_by_callsign) VALUES (:token,:callsign,:type,:value,:runway,:sid,:direct,:star,:altitude,:user_id,:atc_callsign) ON DUPLICATE KEY UPDATE pilot_callsign=VALUES(pilot_callsign),clearance_type=VALUES(clearance_type),clearance_value=VALUES(clearance_value),cleared_departure_runway=VALUES(cleared_departure_runway),cleared_sid=VALUES(cleared_sid),cleared_direct=VALUES(cleared_direct),cleared_star=VALUES(cleared_star),cleared_altitude=VALUES(cleared_altitude),issued_by_user_id=VALUES(issued_by_user_id),issued_by_callsign=VALUES(issued_by_callsign),updated_at=NOW()");
    $save->execute(['token'=>(string)$pilot['session_token'],'callsign'=>$callsign,'type'=>$legacyType,'value'=>$legacyValue,'runway'=>$runway,'sid'=>$sid,'direct'=>$direct,'star'=>$star,'altitude'=>$altitude,'user_id'=>(int)$atc['user_id'],'atc_callsign'=>(string)$atc['callsign']]);
    clearanceReply(true,['message'=>'clearance_saved','clearance'=>['departure_runway'=>$runway,'sid'=>$sid,'direct'=>$direct,'star'=>$star,'altitude'=>$altitude]]);
}catch(Throwable $error){clearanceReply(false,['message'=>'server_error'],500);}
