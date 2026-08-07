<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';

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
    $center=null;
    $airportStmt=$pdo->prepare("SELECT latitude_deg,longitude_deg,name FROM airports WHERE ident=:c1 OR icao_code=:c2 OR gps_code=:c3 LIMIT 1");
    $airportStmt->execute(['c1'=>$session['station_code'],'c2'=>$session['station_code'],'c3'=>$session['station_code']]);
    if($airport=$airportStmt->fetch(PDO::FETCH_ASSOC))$center=['latitude'=>(float)$airport['latitude_deg'],'longitude'=>(float)$airport['longitude_deg'],'name'=>(string)$airport['name']];
    $stmt=$pdo->query("SELECT p.callsign,p.aircraft_icao,p.latitude,p.longitude,p.altitude,p.heading,p.airspeed,p.vertical_speed,p.transponder,p.transponder_mode,p.on_ground,s.is_invisible,u.op_permission,COALESCE(NULLIF(TRIM(u.real_name),''),u.username) AS pilot_name,fp.departure_airport,fp.arrival_airport FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token INNER JOIN users u ON u.id=p.user_id LEFT JOIN pilot_flightplans fp ON fp.session_token=p.session_token WHERE s.is_active=1 AND s.is_spectator=0 AND p.last_update>=DATE_SUB(NOW(),INTERVAL 15 SECOND)");
    $ranges=['airport_info'=>35,'airport_delivery'=>25,'airport_ground'=>25,'airport_tower'=>60,'terminal_approach'=>180,'terminal_departure'=>180,'enroute_center'=>99999];
    $range=(float)($ranges[$session['map_profile']]??60);$traffic=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        if((int)$row['is_invisible']===1&&((int)$session['op_permission']<=1||(int)$session['op_permission']<(int)$row['op_permission']))continue;
        $distance=$center?atcDistanceNm($center['latitude'],$center['longitude'],(float)$row['latitude'],(float)$row['longitude']):null;
        if($distance!==null&&$distance>$range)continue;
        $row['distance_nm']=$distance;$traffic[]=$row;
    }
    echo json_encode(['success'=>true,'center'=>$center,'range_nm'=>$range,'traffic'=>$traffic],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){if(http_response_code()<400)http_response_code(500);echo json_encode(['success'=>false,'message'=>$error->getMessage()]);}
