<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';

function atcDistanceNm(float $lat1,float $lon1,float $lat2,float $lon2): float {
    $dLat=deg2rad($lat2-$lat1);$dLon=deg2rad($lon2-$lon1);
    $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    return 3440.065*2*atan2(sqrt($a),sqrt(1-$a));
}

try {
    $pdo=new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if(empty($_SESSION['web_user_id'])||!validateVfnWebSession($pdo)){http_response_code(401);throw new RuntimeException('login_required');}
    ensureAtcSchema($pdo);$token=(string)($_SESSION['atc_session_token']??'');
    $sessionStmt=$pdo->prepare("SELECT a.*,u.op_permission FROM atc_sessions a INNER JOIN users u ON u.id=a.user_id WHERE a.user_id=:uid AND a.session_token=:token AND a.is_active=1 AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1");
    $sessionStmt->execute(['uid'=>(int)$_SESSION['web_user_id'],'token'=>$token]);$session=$sessionStmt->fetch(PDO::FETCH_ASSOC);
    if(!$session){http_response_code(409);throw new RuntimeException('atc_session_inactive');}
    $pdo->exec("DELETE aa FROM atc_assumed_aircraft aa LEFT JOIN atc_sessions a ON a.id=aa.atc_session_id WHERE a.id IS NULL OR a.is_active=0 OR a.last_seen_at<DATE_SUB(NOW(),INTERVAL 45 SECOND)");
    $center=null;
    $airportStmt=$pdo->prepare("SELECT latitude_deg,longitude_deg,name FROM airports WHERE ident=:c1 OR icao_code=:c2 OR gps_code=:c3 LIMIT 1");
    $airportStmt->execute(['c1'=>$session['station_code'],'c2'=>$session['station_code'],'c3'=>$session['station_code']]);
    if($airport=$airportStmt->fetch(PDO::FETCH_ASSOC))$center=['latitude'=>(float)$airport['latitude_deg'],'longitude'=>(float)$airport['longitude_deg'],'name'=>(string)$airport['name']];
    $hideInvisibleRequested=(string)($_COOKIE['vfn_atc_hide_invisible']??'1')!=='0';
    $invisibleCondition=($hideInvisibleRequested||(int)$session['op_permission']<1)
        ? 'AND s.is_invisible=0'
        : 'AND (s.is_invisible=0 OR u.op_permission<='.(int)$session['op_permission'].')';
    $stmt=$pdo->query("SELECT p.session_token,p.callsign,p.aircraft_icao,p.latitude,p.longitude,p.altitude,p.heading,p.airspeed,p.vertical_speed,p.transponder,p.transponder_mode,p.on_ground,s.is_invisible,u.op_permission,COALESCE(NULLIF(TRIM(u.real_name),''),u.username) AS pilot_name,fp.departure_airport,fp.arrival_airport,fp.route_text,aa.atc_session_id AS assumed_session_id,aa.atc_callsign AS assumed_by,ac.clearance_type,ac.clearance_value,ac.cleared_departure_runway,ac.cleared_sid,ac.cleared_direct,ac.cleared_star,ac.cleared_altitude FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token INNER JOIN users u ON u.id=p.user_id LEFT JOIN pilot_flightplans fp ON fp.session_token=p.session_token LEFT JOIN atc_assumed_aircraft aa ON aa.pilot_session_token=p.session_token LEFT JOIN atc_aircraft_clearances ac ON ac.pilot_session_token=p.session_token WHERE s.is_active=1 AND s.is_spectator=0 $invisibleCondition AND p.last_update>=DATE_SUB(NOW(),INTERVAL 15 SECOND)");
    $ranges=['airport_info'=>35,'airport_delivery'=>25,'airport_ground'=>25,'airport_tower'=>60,'terminal_approach'=>180,'terminal_departure'=>180,'enroute_center'=>99999];
    $range=(float)($ranges[$session['map_profile']]??60);$traffic=[];
    $features=readAtisScopeFeatures($session);
    $station=strtoupper(trim((string)$session['station_code']));
    $airportCoordinates=[];
    $airportCodes=[];
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){foreach(['departure_airport','arrival_airport'] as $field){$code=strtoupper(trim((string)($row[$field]??'')));if($code!==''&&$code!=='ZZZZ')$airportCodes[$code]=true;}}
    if($airportCodes){foreach(array_chunk(array_keys($airportCodes),400) as $codes){$marks=implode(',',array_fill(0,count($codes),'?'));$airportQuery=$pdo->prepare("SELECT ident,icao_code,gps_code,latitude_deg,longitude_deg FROM airports WHERE UPPER(ident) IN ($marks) OR UPPER(icao_code) IN ($marks) OR UPPER(gps_code) IN ($marks)");$airportQuery->execute(array_merge($codes,$codes,$codes));foreach($airportQuery->fetchAll(PDO::FETCH_ASSOC) as $airport){foreach(['ident','icao_code','gps_code'] as $field){$code=strtoupper(trim((string)($airport[$field]??'')));if($code!=='')$airportCoordinates[$code]=[(float)$airport['latitude_deg'],(float)$airport['longitude_deg']];}}}}
    $insideScope=static function(float $latitude,float $longitude)use($features):bool{foreach($features as $feature){if(pointInAtisGeometry($longitude,$latitude,$feature['geometry']??[]))return true;}return false;};
    foreach($rows as $row){
        $distance=$center?atcDistanceNm($center['latitude'],$center['longitude'],(float)$row['latitude'],(float)$row['longitude']):null;
        $assumedByMe=(int)($row['assumed_session_id']??0)===(int)$session['id'];
        $aircraftInside=$features?$insideScope((float)$row['latitude'],(float)$row['longitude']):($distance!==null&&$distance<=$range);
        $airportInside=false;
        foreach(['departure_airport','arrival_airport'] as $field){$code=strtoupper(trim((string)($row[$field]??'')));if($code===$station){$airportInside=true;break;}if(isset($airportCoordinates[$code])&&$features&&$insideScope($airportCoordinates[$code][0],$airportCoordinates[$code][1])){$airportInside=true;break;}}
        if(!$assumedByMe&&!$aircraftInside&&!$airportInside)continue;
        $row['distance_nm']=$distance;$row['assumed_by_me']=$assumedByMe?1:0;$row['is_assumed']=!empty($row['assumed_session_id'])?1:0;unset($row['session_token'],$row['assumed_session_id']);$traffic[]=$row;
    }
    echo json_encode(['success'=>true,'center'=>$center,'range_nm'=>$range,'traffic'=>$traffic],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){if(http_response_code()<400)http_response_code(500);echo json_encode(['success'=>false,'message'=>$error->getMessage()]);}
