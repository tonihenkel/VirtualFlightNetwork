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
    $atcStatement=$pdo->prepare("SELECT id,user_id,callsign,is_trainer FROM atc_sessions WHERE user_id=:user_id AND session_token=:token AND is_active=1 AND (is_spectator=0 OR is_trainer=1) AND can_control=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1");
    $atcStatement->execute(['user_id'=>(int)$_SESSION['web_user_id'],'token'=>(string)($_SESSION['atc_session_token']??'')]);
    $atc=$atcStatement->fetch(PDO::FETCH_ASSOC);
    if(!$atc)clearanceReply(false,['message'=>'atc_control_session_required'],403);
    $callsign=strtoupper(trim((string)($_POST['callsign']??'')));
    if($callsign===''||!preg_match('/^[A-Z0-9_-]{2,24}$/',$callsign))clearanceReply(false,['message'=>'invalid_callsign'],422);
    $pilotStatement=$pdo->prepare("SELECT p.session_token FROM pilot_positions p JOIN user_sessions s ON s.token=p.session_token WHERE UPPER(p.callsign)=:callsign AND s.is_active=1 AND s.is_spectator=0 AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND) LIMIT 1");
    $pilotStatement->execute(['callsign'=>$callsign]);$pilot=$pilotStatement->fetch(PDO::FETCH_ASSOC);
    if(!$pilot){
        $trainingStatement=$pdo->prepare("SELECT ta.id FROM atc_training_aircraft ta INNER JOIN atc_sessions creator ON creator.id=ta.trainer_session_id WHERE UPPER(ta.callsign)=:callsign AND creator.is_active=1 AND creator.is_trainer=1 AND creator.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) LIMIT 1");
        $trainingStatement->execute(['callsign'=>$callsign]);$trainingId=(int)$trainingStatement->fetchColumn();
        if($trainingId>0)$pilot=['session_token'=>'training:'.$trainingId];
    }
    if(!$pilot)clearanceReply(false,['message'=>'pilot_not_online'],404);
    $ownerStatement=$pdo->prepare("SELECT atc_session_id FROM atc_assumed_aircraft WHERE pilot_session_token=:token LIMIT 1");
    $ownerStatement->execute(['token'=>(string)$pilot['session_token']]);$owner=$ownerStatement->fetch(PDO::FETCH_ASSOC);
    if((int)($atc['is_trainer']??0)!==1&&(!$owner||(int)$owner['atc_session_id']!==(int)$atc['id']))clearanceReply(false,['message'=>'aircraft_must_be_assumed'],409);
    if(strtolower(trim((string)($_POST['action']??'')))==='delete'){
        $delete=$pdo->prepare("DELETE FROM atc_aircraft_clearances WHERE pilot_session_token=:token");
        $delete->execute(['token'=>(string)$pilot['session_token']]);
        clearanceReply(true,['message'=>'clearance_deleted']);
    }
    $sid=mb_substr(strtoupper(trim((string)($_POST['cleared_sid']??''))),0,80,'UTF-8');
    $runway=strtoupper(trim((string)($_POST['cleared_departure_runway']??'')));
    $runway=preg_replace('/^(?:RWY?|RUNWAY)\s*/','',$runway);
    if($runway!==''){
        if(!preg_match('/^(\d{1,2})([LCR]?)$/',$runway,$runwayMatch)||(int)$runwayMatch[1]<1||(int)$runwayMatch[1]>36)clearanceReply(false,['message'=>'invalid_departure_runway'],422);
        $runway=sprintf('%02d',(int)$runwayMatch[1]).$runwayMatch[2];
    }
    $landingRunway=mb_substr(strtoupper(trim((string)($_POST['cleared_landing_runway']??''))),0,24,'UTF-8');
    if($landingRunway!==''&&!preg_match('/^[A-Z0-9][A-Z0-9 .\\/-]{0,23}$/',$landingRunway))clearanceReply(false,['message'=>'invalid_landing_runway'],422);
    $gate=mb_substr(strtoupper(trim((string)($_POST['cleared_gate']??''))),0,40,'UTF-8');
    if($gate!==''&&!preg_match('/^[A-Z0-9][A-Z0-9 .\\/-]{0,39}$/',$gate))clearanceReply(false,['message'=>'invalid_gate'],422);
    $taxiRoute=mb_substr(strtoupper(trim((string)($_POST['cleared_taxi_route']??''))),0,500,'UTF-8');
    $taxiRoute=(string)preg_replace('/\\s+/u',' ',$taxiRoute);
    if($taxiRoute!==''&&!preg_match('/^[A-Z0-9][A-Z0-9 .,>\\/-]{0,499}$/',$taxiRoute))clearanceReply(false,['message'=>'invalid_taxi_route'],422);
    $direct=mb_substr(strtoupper(trim((string)($_POST['cleared_direct']??''))),0,80,'UTF-8');
    if($direct!==''&&preg_match('/^\d{1,3}$/',$direct)){
        $heading=(int)$direct;
        if($heading>259)clearanceReply(false,['message'=>'invalid_heading'],422);
        $direct=str_pad((string)$heading,3,'0',STR_PAD_LEFT);
    }elseif($direct!==''&&!preg_match('/^[A-Z0-9]{2,80}$/',$direct)){
        clearanceReply(false,['message'=>'invalid_direct'],422);
    }
    $star=mb_substr(strtoupper(trim((string)($_POST['cleared_star']??''))),0,80,'UTF-8');
    $altitude=mb_substr(strtoupper(trim((string)($_POST['cleared_altitude']??''))),0,20,'UTF-8');
    if($runway===''&&$landingRunway===''&&$gate===''&&$taxiRoute===''&&$sid===''&&$direct===''&&$star===''&&$altitude===''){
        $delete=$pdo->prepare("DELETE FROM atc_aircraft_clearances WHERE pilot_session_token=:token");
        $delete->execute(['token'=>(string)$pilot['session_token']]);
        clearanceReply(true,['message'=>'clearance_deleted','clearance'=>[
            'departure_runway'=>'','landing_runway'=>'','gate'=>'','taxi_route'=>'','sid'=>'','direct'=>'','star'=>'','altitude'=>''
        ]]);
    }
    $legacyType=$sid!==''?'SID':($direct!==''?'DIRECT':($star!==''?'STAR':($taxiRoute!==''?'TAXI':'DIRECT')));
    $legacyValue=mb_substr($sid!==''?$sid:($direct!==''?$direct:($star!==''?$star:$taxiRoute)),0,80,'UTF-8');
    $save=$pdo->prepare("INSERT INTO atc_aircraft_clearances (pilot_session_token,pilot_callsign,clearance_type,clearance_value,cleared_departure_runway,cleared_landing_runway,cleared_gate,cleared_taxi_route,cleared_sid,cleared_direct,cleared_star,cleared_altitude,issued_by_user_id,issued_by_callsign) VALUES (:token,:callsign,:type,:value,:runway,:landing_runway,:gate,:taxi_route,:sid,:direct,:star,:altitude,:user_id,:atc_callsign) ON DUPLICATE KEY UPDATE pilot_callsign=VALUES(pilot_callsign),clearance_type=VALUES(clearance_type),clearance_value=VALUES(clearance_value),cleared_departure_runway=VALUES(cleared_departure_runway),cleared_landing_runway=VALUES(cleared_landing_runway),cleared_gate=VALUES(cleared_gate),cleared_taxi_route=VALUES(cleared_taxi_route),cleared_sid=VALUES(cleared_sid),cleared_direct=VALUES(cleared_direct),cleared_star=VALUES(cleared_star),cleared_altitude=VALUES(cleared_altitude),issued_by_user_id=VALUES(issued_by_user_id),issued_by_callsign=VALUES(issued_by_callsign),updated_at=NOW()");
    $save->execute(['token'=>(string)$pilot['session_token'],'callsign'=>$callsign,'type'=>$legacyType,'value'=>$legacyValue,'runway'=>$runway,'landing_runway'=>$landingRunway,'gate'=>$gate,'taxi_route'=>$taxiRoute,'sid'=>$sid,'direct'=>$direct,'star'=>$star,'altitude'=>$altitude,'user_id'=>(int)$atc['user_id'],'atc_callsign'=>(string)$atc['callsign']]);
    clearanceReply(true,['message'=>'clearance_saved','clearance'=>['departure_runway'=>$runway,'landing_runway'=>$landingRunway,'gate'=>$gate,'taxi_route'=>$taxiRoute,'sid'=>$sid,'direct'=>$direct,'star'=>$star,'altitude'=>$altitude]]);
}catch(Throwable $error){clearanceReply(false,['message'=>'server_error'],500);}
