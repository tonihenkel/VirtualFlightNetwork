<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';

function normalizeTrainingHeading(float $heading):float {
    $heading=fmod($heading,360.0);
    return $heading<0?$heading+360.0:$heading;
}

function trainingHeadingDelta(float $from,float $to):float {
    return fmod(($to-$from)+540.0,360.0)-180.0;
}

function trainingBearing(float $lat1,float $lon1,float $lat2,float $lon2):float {
    $a=deg2rad($lat1);$b=deg2rad($lat2);$dlon=deg2rad($lon2-$lon1);
    return normalizeTrainingHeading(rad2deg(atan2(sin($dlon)*cos($b),cos($a)*sin($b)-sin($a)*cos($b)*cos($dlon))));
}

function trainingDistanceNm(float $lat1,float $lon1,float $lat2,float $lon2):float {
    $cos=max(.15,cos(deg2rad(($lat1+$lat2)/2.0)));
    $x=($lon2-$lon1)*60.0*$cos;$y=($lat2-$lat1)*60.0;
    return sqrt($x*$x+$y*$y);
}

function trainingRunwayIdent(string $value):string {
    $value=strtoupper(trim($value));
    return preg_match('/(?:RWY\\s*)?([0-3]?[0-9][LRC]?)/',$value,$match)?ltrim($match[1],'0'):'';
}

/** Return guidance back to and along a real taxiway towards the cleared runway. */
function trainingTaxiwayGuidance(PDO $pdo,int $aircraftId,float $lat,float $lon,float $requestedHeading,string $motionState):?array {
    $clearedRunway='';
    $clearedTaxiRoute='';
    if($motionState==='taxi_out'){
        $clearance=$pdo->prepare("SELECT cleared_departure_runway,cleared_taxi_route FROM atc_aircraft_clearances WHERE pilot_session_token=:token LIMIT 1");
        $clearance->execute(['token'=>'training:'.$aircraftId]);
        $clearanceRow=$clearance->fetch(PDO::FETCH_ASSOC)?:[];
        $clearedRunway=trainingRunwayIdent((string)($clearanceRow['cleared_departure_runway']??''));
        $clearedTaxiRoute=strtoupper(trim((string)($clearanceRow['cleared_taxi_route']??'')));
        if($clearedRunway==='')return null;
    }
    $airports=$pdo->prepare("SELECT ident,icao_code,gps_code,latitude_deg,longitude_deg FROM airports WHERE latitude_deg BETWEEN :south AND :north AND longitude_deg BETWEEN :west AND :east ORDER BY ((latitude_deg-:lat)*(latitude_deg-:lat2)+(longitude_deg-:lon)*(longitude_deg-:lon2)) ASC LIMIT 8");
    $airports->execute(['south'=>$lat-.30,'north'=>$lat+.30,'west'=>$lon-.40,'east'=>$lon+.40,'lat'=>$lat,'lat2'=>$lat,'lon'=>$lon,'lon2'=>$lon]);
    $best=null;
    foreach($airports->fetchAll(PDO::FETCH_ASSOC) as $airport){
        $code=strtoupper(trim((string)($airport['icao_code']?:$airport['gps_code']?:$airport['ident'])));
        if($code==='')continue;
        $path=__DIR__.'/../data/airport_layouts/'.$code.'.json';
        if(!is_file($path))continue;
        $layout=json_decode((string)file_get_contents($path),true);
        $nodes=$layout['taxi_nodes']??[];$segments=$layout['taxiways']??[];
        if(!is_array($nodes)||!is_array($segments))continue;
        $runwayPoint=null;
        if($clearedRunway!==''){
            foreach(($layout['runways']??[]) as $runway){
                foreach(($runway['ends']??[]) as $end){
                    if(trainingRunwayIdent((string)($end['ident']??''))===$clearedRunway&&isset($end['point'][0],$end['point'][1])){
                        $runwayPoint=[(float)$end['point'][0],(float)$end['point'][1]];
                        break 2;
                    }
                }
            }
            if($runwayPoint===null)continue;
        }
        $cos=max(.15,cos(deg2rad($lat)));
        foreach($segments as $segment){
            if(stripos((string)($segment['class']??''),'runway')!==false)continue;
            $from=$nodes[(int)($segment['from']??-1)]['point']??null;
            $to=$nodes[(int)($segment['to']??-1)]['point']??null;
            if(!is_array($from)||!is_array($to)||count($from)<2||count($to)<2)continue;
            $ax=((float)$from[1]-$lon)*60.0*$cos;$ay=((float)$from[0]-$lat)*60.0;
            $bx=((float)$to[1]-$lon)*60.0*$cos;$by=((float)$to[0]-$lat)*60.0;
            $dx=$bx-$ax;$dy=$by-$ay;$length2=$dx*$dx+$dy*$dy;
            if($length2<.000001)continue;
            $t=max(0.0,min(1.0,-($ax*$dx+$ay*$dy)/$length2));
            $px=$ax+$t*$dx;$py=$ay+$t*$dy;$distance=sqrt($px*$px+$py*$py);
            if($distance>1.5)continue;
            $forward=trainingBearing((float)$from[0],(float)$from[1],(float)$to[0],(float)$to[1]);
            $reverse=normalizeTrainingHeading($forward+180.0);
            $direction=strtolower((string)($segment['direction']??'twoway'));
            $travelForward=$direction!=='twoway'||abs(trainingHeadingDelta($requestedHeading,$forward))<=abs(trainingHeadingDelta($requestedHeading,$reverse));
            if($runwayPoint!==null){
                $travelForward=trainingDistanceNm((float)$to[0],(float)$to[1],$runwayPoint[0],$runwayPoint[1])<=trainingDistanceNm((float)$from[0],(float)$from[1],$runwayPoint[0],$runwayPoint[1]);
            }
            $segmentHeading=$travelForward?$forward:$reverse;
            $headingPenalty=abs(trainingHeadingDelta($requestedHeading,$segmentHeading))*.00015;
            $goalPenalty=$runwayPoint===null?0.0:min(.25,trainingDistanceNm($lat,$lon,$runwayPoint[0],$runwayPoint[1])*.0001);
            $score=$distance+$headingPenalty+$goalPenalty;
            if($best!==null&&$score>=$best['score'])continue;

            // Aim ahead on the centre line instead of merely driving parallel to it.
            // This pulls an offset aircraft smoothly back onto the taxiway and also
            // avoids the sharp 90-degree course jumps previously seen at junctions.
            if($distance>.035){
                $aimLat=(float)$from[0]+((float)$to[0]-(float)$from[0])*$t;
                $aimLon=(float)$from[1]+((float)$to[1]-(float)$from[1])*$t;
                $best=['distance'=>$distance,'score'=>$score,'heading'=>trainingBearing($lat,$lon,$aimLat,$aimLon),'speed_limit'=>5.0,'airport'=>$code,'taxi_route'=>$clearedTaxiRoute];
                continue;
            }
            $length=sqrt($length2);
            $lookAhead=min(.08,max(.025,$length*.25));
            $aimT=max(0.0,min(1.0,$t+($travelForward?1.0:-1.0)*($lookAhead/$length)));
            $aimLat=(float)$from[0]+((float)$to[0]-(float)$from[0])*$aimT;
            $aimLon=(float)$from[1]+((float)$to[1]-(float)$from[1])*$aimT;
            $guidanceHeading=trainingBearing($lat,$lon,$aimLat,$aimLon);
            $remaining=$runwayPoint===null?INF:trainingDistanceNm($lat,$lon,$runwayPoint[0],$runwayPoint[1]);
            $best=['distance'=>$distance,'score'=>$score,'heading'=>$guidanceHeading,'speed_limit'=>$remaining<.08?0.0:12.0,'airport'=>$code,'runway'=>$clearedRunway,'taxi_route'=>$clearedTaxiRoute];
        }
    }
    return $best;
}

try {
    $pdo=new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if(empty($_SESSION['web_user_id'])||!validateVfnWebSession($pdo)){http_response_code(401);throw new RuntimeException('login_required');}
    ensureAtcSchema($pdo);
    $stmt=$pdo->prepare("SELECT * FROM atc_sessions WHERE user_id=:uid AND session_token=:token AND is_active=1 AND is_trainer=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) LIMIT 1");
    $stmt->execute(['uid'=>(int)$_SESSION['web_user_id'],'token'=>(string)($_SESSION['atc_session_token']??'')]);
    $session=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$session){http_response_code(403);throw new RuntimeException('atc_trainer_required');}
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);throw new RuntimeException('method_not_allowed');}
    $action=(string)($_POST['action']??'create');
    if(in_array($action,['control','step'],true)){
        $id=max(0,(int)($_POST['id']??0));
        $owned=$pdo->prepare("SELECT * FROM atc_training_aircraft WHERE id=:id AND trainer_session_id=:sid LIMIT 1");
        $owned->execute(['id'=>$id,'sid'=>(int)$session['id']]);
        $aircraft=$owned->fetch(PDO::FETCH_ASSOC);
        if(!$aircraft){http_response_code(404);throw new RuntimeException('atc_training_aircraft_not_found');}
        if($action==='control'){
            $mode=strtolower(trim((string)($_POST['control_mode']??$aircraft['control_mode'])));
            $state=strtolower(trim((string)($_POST['motion_state']??$aircraft['motion_state'])));
            $speed=max(0,min(650,(int)($_POST['target_airspeed']??$aircraft['target_airspeed'])));
            $altitude=max(0,min(60000,(int)($_POST['target_altitude']??$aircraft['target_altitude'])));
            $heading=max(0,min(359,(int)($_POST['heading']??($aircraft['target_heading']??$aircraft['heading']))));
            $vs=max(100,min(6000,(int)($_POST['vertical_speed_fpm']??$aircraft['vertical_speed_fpm'])));
            $states=['parked','pushback','taxi_out','taxi_in','takeoff','climb','cruise','descent','landing'];
            if(!in_array($mode,['manual','automatic'],true)||!in_array($state,$states,true)){http_response_code(422);throw new RuntimeException('atc_training_motion_invalid');}
            $defaults=['parked'=>[0,0],'pushback'=>[4,0],'taxi_out'=>[18,0],'taxi_in'=>[15,0],'takeoff'=>[155,3000],'climb'=>[250,max(5000,$altitude)],'cruise'=>[430,max(5000,$altitude)],'descent'=>[230,$altitude],'landing'=>[135,0]];
            if($mode==='automatic'&&isset($defaults[$state])){if(!isset($_POST['target_airspeed']))$speed=$defaults[$state][0];if(!isset($_POST['target_altitude']))$altitude=$defaults[$state][1];}
            $placement=(string)$aircraft['placement_type'];
            if((float)$aircraft['altitude']>5.0||in_array($state,['climb','cruise','descent'],true))$placement='air';
            $pdo->prepare("UPDATE atc_training_aircraft SET control_mode=:mode,motion_state=:state,target_airspeed=:speed,target_altitude=:altitude,target_heading=:heading,vertical_speed_fpm=:vs,placement_type=:placement,last_motion_at=NOW() WHERE id=:id AND trainer_session_id=:sid")
                ->execute(['mode'=>$mode,'state'=>$state,'speed'=>$speed,'altitude'=>$altitude,'heading'=>$heading,'vs'=>$vs,'placement'=>$placement,'id'=>$id,'sid'=>(int)$session['id']]);
            echo json_encode(['success'=>true,'updated'=>true]);exit;
        }
        $now=microtime(true);$last=!empty($aircraft['last_motion_at'])?strtotime((string)$aircraft['last_motion_at']):time();$dt=max(.2,min(5.0,$now-$last));
        $speed=(float)$aircraft['airspeed'];$targetSpeed=(float)$aircraft['target_airspeed'];$accel=in_array($aircraft['motion_state'],['takeoff','climb'],true)?12.0:5.0;
        $heading=(float)$aircraft['heading'];$targetHeading=(float)($aircraft['target_heading']??$heading);
        if($aircraft['control_mode']==='automatic'&&in_array($aircraft['motion_state'],['taxi_out','taxi_in'],true)){
            $guidance=trainingTaxiwayGuidance(
                $pdo,
                (int)$aircraft['id'],
                (float)$aircraft['latitude'],
                (float)$aircraft['longitude'],
                $targetHeading,
                (string)$aircraft['motion_state']
            );
            if($guidance===null){
                // Do not drive across grass/aprons when no connected taxi route or
                // no departure-runway clearance is available.
                $targetSpeed=0.0;$speed=0.0;
            }else{
                $targetHeading=(float)$guidance['heading'];
                $targetSpeed=min($targetSpeed,(float)($guidance['speed_limit']??$targetSpeed));
            }
        }
        $turnRate=$aircraft['motion_state']==='pushback'?8.0:(in_array($aircraft['motion_state'],['taxi_out','taxi_in'],true)?12.0:4.0);
        $turn=max(-$turnRate*$dt,min($turnRate*$dt,trainingHeadingDelta($heading,$targetHeading)));
        $heading=normalizeTrainingHeading($heading+$turn);
        $speed+=max(-$accel*$dt,min($accel*$dt,$targetSpeed-$speed));
        $alt=(float)$aircraft['altitude'];$targetAlt=(float)$aircraft['target_altitude'];$altStep=((float)$aircraft['vertical_speed_fpm']/60.0)*$dt;$alt+=max(-$altStep,min($altStep,$targetAlt-$alt));
        $distanceNm=$speed*$dt/3600.0;if($aircraft['motion_state']==='pushback')$distanceNm*=-1;
        $rad=deg2rad($heading);$lat=(float)$aircraft['latitude'];$lon=(float)$aircraft['longitude'];
        $lat+=($distanceNm*cos($rad))/60.0;$lon+=($distanceNm*sin($rad))/(60.0*max(.15,cos(deg2rad($lat))));
        $state=(string)$aircraft['motion_state'];$placement=(string)$aircraft['placement_type'];
        if($alt>5)$placement='air';
        if($state==='takeoff'&&$speed>=130){$placement='air';$state='climb';}
        if($state==='climb'&&abs($alt-$targetAlt)<25)$state='cruise';
        if($state==='landing'&&$alt<=5){$alt=0;$speed=min($speed,35);$placement='runway';$state='taxi_in';}
        if($state==='parked'){$speed=0;$placement='gate';}
        $pdo->prepare("UPDATE atc_training_aircraft SET latitude=:lat,longitude=:lon,altitude=:alt,airspeed=:speed,heading=:heading,placement_type=:placement,motion_state=:state,last_motion_at=NOW() WHERE id=:id AND trainer_session_id=:sid")
            ->execute(['lat'=>$lat,'lon'=>$lon,'alt'=>(int)round($alt),'speed'=>(int)round($speed),'heading'=>(int)round($heading),'placement'=>$placement,'state'=>$state,'id'=>$id,'sid'=>(int)$session['id']]);
        echo json_encode(['success'=>true,'aircraft'=>['id'=>$id,'latitude'=>$lat,'longitude'=>$lon,'altitude'=>(int)round($alt),'airspeed'=>(int)round($speed),'heading'=>(int)round($heading),'motion_state'=>$state,'placement_type'=>$placement]]);exit;
    }
    if(in_array($action,['delete','reset-assignment'],true)){
        $id=max(0,(int)($_POST['id']??0));
        $owned=$pdo->prepare("SELECT 1 FROM atc_training_aircraft WHERE id=:id AND trainer_session_id=:sid LIMIT 1");
        $owned->execute(['id'=>$id,'sid'=>(int)$session['id']]);
        if(!$owned->fetchColumn()){http_response_code(404);throw new RuntimeException('atc_training_aircraft_not_found');}
        $trainingToken='training:'.$id;
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM atc_handoff_requests WHERE pilot_session_token=:token")->execute(['token'=>$trainingToken]);
        $pdo->prepare("DELETE FROM atc_assumed_aircraft WHERE pilot_session_token=:token")->execute(['token'=>$trainingToken]);
        if($action==='reset-assignment'){
            $pdo->commit();
            echo json_encode(['success'=>true,'reset'=>true]);exit;
        }
        $pdo->prepare("DELETE FROM atc_aircraft_clearances WHERE pilot_session_token=:token")->execute(['token'=>$trainingToken]);
        $delete=$pdo->prepare("DELETE FROM atc_training_aircraft WHERE id=:id AND trainer_session_id=:sid");
        $delete->execute(['id'=>$id,'sid'=>(int)$session['id']]);
        $pdo->commit();
        echo json_encode(['success'=>true,'deleted'=>$delete->rowCount()>0]);exit;
    }
    if($action==='update'||$action==='update-all'||$action==='update-radios'){
        $id=max(0,(int)($_POST['id']??0));
        $field=(string)($_POST['field']??'');
        $owned=$pdo->prepare("SELECT * FROM atc_training_aircraft WHERE id=:id AND trainer_session_id=:sid LIMIT 1");
        $owned->execute(['id'=>$id,'sid'=>(int)$session['id']]);
        $current=$owned->fetch(PDO::FETCH_ASSOC);
        if(!$current){http_response_code(404);throw new RuntimeException('atc_training_aircraft_not_found');}
        if($action==='update-radios'){
            $normalizeFrequency=static function($raw):string{
                $raw=str_replace(',','.',trim((string)$raw));
                if(!preg_match('/^\d{3}(?:\.\d{1,3})?$/',$raw)){throw new RuntimeException('atc_training_frequency_invalid');}
                $frequency=(float)$raw;
                if($frequency<118.000||$frequency>136.975){throw new RuntimeException('atc_training_frequency_invalid');}
                return number_format(round($frequency,3),3,'.','');
            };
            $com1=$normalizeFrequency($_POST['com1']??'');
            $com2=$normalizeFrequency($_POST['com2']??'');
            $pdo->prepare("UPDATE atc_training_aircraft SET com1_frequency=:com1,com2_frequency=:com2 WHERE id=:id AND trainer_session_id=:sid")
                ->execute(['com1'=>$com1,'com2'=>$com2,'id'=>$id,'sid'=>(int)$session['id']]);
            echo json_encode(['success'=>true,'updated'=>true,'com1'=>$com1,'com2'=>$com2]);exit;
        }
        if($action==='update-all'){
            $callsign=strtoupper(trim((string)($_POST['callsign']??'')));
            $aircraft=strtoupper(trim((string)($_POST['aircraft_icao']??'')));
            $type=strtolower(trim((string)($_POST['placement_type']??'')));
            $altitude=(int)($_POST['altitude']??0);
            $heading=(int)($_POST['heading']??0);
            $transponderStatus=strtolower(trim((string)($_POST['transponder_status']??'')));
            $transponderCode=trim((string)($_POST['transponder_code']??''));
            if(!preg_match('/^[A-Z0-9-]{2,16}$/',$callsign)||!preg_match('/^[A-Z0-9-]{2,12}$/',$aircraft)
                ||!in_array($type,['runway','taxiway','gate','air'],true)||$altitude<0||$altitude>60000||$heading<0||$heading>359
                ||!in_array($transponderStatus,['standby','on','ident'],true)||!preg_match('/^[0-7]{4}$/',$transponderCode)){
                http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');
            }
            if($type!=='air')$altitude=0;
            $duplicate=$pdo->prepare("SELECT 1 FROM atc_training_aircraft ta INNER JOIN atc_sessions s ON s.id=ta.trainer_session_id WHERE ta.id<>:id AND ta.callsign=:callsign AND s.is_active=1 AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) LIMIT 1");
            $duplicate->execute(['id'=>$id,'callsign'=>$callsign]);
            $livePilot=$pdo->prepare("SELECT 1 FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token WHERE UPPER(p.callsign)=:callsign AND s.is_active=1 AND s.is_spectator=0 AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND) LIMIT 1");
            $livePilot->execute(['callsign'=>$callsign]);
            if($duplicate->fetchColumn()||$livePilot->fetchColumn()){http_response_code(409);throw new RuntimeException('atc_training_callsign_exists');}
            $update=$pdo->prepare("UPDATE atc_training_aircraft SET callsign=:callsign,aircraft_icao=:aircraft,placement_type=:type,altitude=:altitude,heading=:heading,target_heading=:heading,airspeed=:airspeed,transponder_status=:transponder_status,transponder_code=:transponder_code WHERE id=:id AND trainer_session_id=:sid");
            $update->execute(['callsign'=>$callsign,'aircraft'=>$aircraft,'type'=>$type,'altitude'=>$altitude,'heading'=>$heading,'airspeed'=>$type==='air'?180:0,'transponder_status'=>$transponderStatus,'transponder_code'=>$transponderCode,'id'=>$id,'sid'=>(int)$session['id']]);
            echo json_encode(['success'=>true,'updated'=>true]);exit;
        }
        if($field==='callsign'){
            $value=strtoupper(trim((string)($_POST['value']??'')));
            if(!preg_match('/^[A-Z0-9-]{2,16}$/',$value)){http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');}
            $duplicate=$pdo->prepare("SELECT 1 FROM atc_training_aircraft ta INNER JOIN atc_sessions s ON s.id=ta.trainer_session_id WHERE ta.id<>:id AND ta.callsign=:callsign AND s.is_active=1 AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) LIMIT 1");
            $duplicate->execute(['id'=>$id,'callsign'=>$value]);
            $livePilot=$pdo->prepare("SELECT 1 FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token WHERE UPPER(p.callsign)=:callsign AND s.is_active=1 AND s.is_spectator=0 AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND) LIMIT 1");
            $livePilot->execute(['callsign'=>$value]);
            if($duplicate->fetchColumn()||$livePilot->fetchColumn()){http_response_code(409);throw new RuntimeException('atc_training_callsign_exists');}
            $pdo->prepare("UPDATE atc_training_aircraft SET callsign=:value WHERE id=:id AND trainer_session_id=:sid")->execute(['value'=>$value,'id'=>$id,'sid'=>(int)$session['id']]);
        }elseif($field==='aircraft_icao'){
            $value=strtoupper(trim((string)($_POST['value']??'')));
            if(!preg_match('/^[A-Z0-9-]{2,12}$/',$value)){http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');}
            $pdo->prepare("UPDATE atc_training_aircraft SET aircraft_icao=:value WHERE id=:id AND trainer_session_id=:sid")->execute(['value'=>$value,'id'=>$id,'sid'=>(int)$session['id']]);
        }elseif($field==='placement_type'){
            $value=strtolower(trim((string)($_POST['value']??'')));
            $altitude=(int)($_POST['altitude']??0);
            if(!in_array($value,['runway','taxiway','gate','air'],true)||$altitude<0||$altitude>60000){http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');}
            if($value!=='air')$altitude=0;
            $pdo->prepare("UPDATE atc_training_aircraft SET placement_type=:value,altitude=:altitude,airspeed=:airspeed,transponder_status=:transponder_status WHERE id=:id AND trainer_session_id=:sid")->execute(['value'=>$value,'altitude'=>$altitude,'airspeed'=>$value==='air'?180:0,'transponder_status'=>$value==='air'?'on':'standby','id'=>$id,'sid'=>(int)$session['id']]);
        }elseif($field==='heading'){
            $value=(int)($_POST['value']??-1);
            if($value<0||$value>359){http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');}
            $pdo->prepare("UPDATE atc_training_aircraft SET heading=:value,target_heading=:value WHERE id=:id AND trainer_session_id=:sid")->execute(['value'=>$value,'id'=>$id,'sid'=>(int)$session['id']]);
        }elseif($field==='transponder_status'){
            $value=strtolower(trim((string)($_POST['value']??'')));
            if(!in_array($value,['standby','on','ident'],true)){http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');}
            $pdo->prepare("UPDATE atc_training_aircraft SET transponder_status=:value WHERE id=:id AND trainer_session_id=:sid")->execute(['value'=>$value,'id'=>$id,'sid'=>(int)$session['id']]);
        }elseif($field==='transponder_code'){
            $value=trim((string)($_POST['value']??''));
            if(!preg_match('/^[0-7]{4}$/',$value)){http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');}
            $pdo->prepare("UPDATE atc_training_aircraft SET transponder_code=:value WHERE id=:id AND trainer_session_id=:sid")->execute(['value'=>$value,'id'=>$id,'sid'=>(int)$session['id']]);
        }else{http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');}
        echo json_encode(['success'=>true,'updated'=>true]);exit;
    }
    $callsign=strtoupper(trim((string)($_POST['callsign']??'')));
    $aircraft=strtoupper(trim((string)($_POST['aircraft_icao']??'A320')));
    $type=strtolower(trim((string)($_POST['placement_type']??'gate')));
    $latitude=(float)($_POST['latitude']??999);$longitude=(float)($_POST['longitude']??999);
    $altitude=(int)($_POST['altitude']??0);$heading=(int)($_POST['heading']??0);
    $transponderStatus=strtolower(trim((string)($_POST['transponder_status']??($type==='air'?'on':'standby'))));
    $transponderCode=trim((string)($_POST['transponder_code']??'7000'));
    if(!preg_match('/^[A-Z0-9-]{2,16}$/',$callsign)||!preg_match('/^[A-Z0-9-]{2,12}$/',$aircraft)
        ||!in_array($type,['runway','taxiway','gate','air'],true)||$latitude< -90||$latitude>90||$longitude< -180||$longitude>180
        ||$altitude< -1000||$altitude>60000||$heading<0||$heading>359||!in_array($transponderStatus,['standby','on','ident'],true)||!preg_match('/^[0-7]{4}$/',$transponderCode)){http_response_code(422);throw new RuntimeException('atc_training_aircraft_invalid');}
    if($type!=='air')$altitude=0;
    $activeCallsign=$pdo->prepare(
        "SELECT 1 FROM atc_training_aircraft ta
         INNER JOIN atc_sessions s ON s.id=ta.trainer_session_id
         WHERE ta.callsign=:callsign AND s.is_active=1
           AND s.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)
         LIMIT 1"
    );
    $activeCallsign->execute(['callsign'=>$callsign]);
    if($activeCallsign->fetchColumn()){http_response_code(409);throw new RuntimeException('atc_training_callsign_exists');}
    $livePilot=$pdo->prepare("SELECT 1 FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token WHERE UPPER(p.callsign)=:callsign AND s.is_active=1 AND s.is_spectator=0 AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND) LIMIT 1");
    $livePilot->execute(['callsign'=>$callsign]);
    if($livePilot->fetchColumn()){http_response_code(409);throw new RuntimeException('atc_training_callsign_exists');}
    $insert=$pdo->prepare("INSERT INTO atc_training_aircraft(trainer_session_id,callsign,aircraft_icao,placement_type,latitude,longitude,altitude,heading,target_heading,airspeed,transponder_code,transponder_status) VALUES(:sid,:callsign,:aircraft,:type,:lat,:lon,:altitude,:heading,:heading,:airspeed,:transponder_code,:transponder_status)");
    try{$insert->execute(['sid'=>(int)$session['id'],'callsign'=>$callsign,'aircraft'=>$aircraft,'type'=>$type,'lat'=>$latitude,'lon'=>$longitude,'altitude'=>$altitude,'heading'=>$heading,'airspeed'=>$type==='air'?180:0,'transponder_code'=>$transponderCode,'transponder_status'=>$transponderStatus]);}
    catch(PDOException $error){if((string)$error->getCode()==='23000'){http_response_code(409);throw new RuntimeException('atc_training_callsign_exists');}throw $error;}
    echo json_encode(['success'=>true,'id'=>(int)$pdo->lastInsertId()]);
}catch(Throwable $error){if(http_response_code()<400)http_response_code(500);echo json_encode(['success'=>false,'message'=>$error->getMessage()]);}
