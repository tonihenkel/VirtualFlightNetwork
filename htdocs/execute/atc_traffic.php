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
require_once __DIR__ . '/../includes/chat_system.php';
require_once __DIR__ . '/../includes/plugin_messages.php';

function atcDistanceNm(float $lat1,float $lon1,float $lat2,float $lon2): float {
    $dLat=deg2rad($lat2-$lat1);$dLon=deg2rad($lon2-$lon1);
    $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    return 3440.065*2*atan2(sqrt($a),sqrt(1-$a));
}

/** Returns the shortest distance from a position to a GeoJSON polygon edge. */
function atcGeometryEdgeDistanceNm(float $latitude,float $longitude,array $geometry): float {
    $type=(string)($geometry['type']??'');
    $coordinates=$geometry['coordinates']??[];
    $polygons=$type==='Polygon'?[$coordinates]:($type==='MultiPolygon'?$coordinates:[]);
    if(!$polygons)return INF;
    $cosLatitude=max(0.000001,cos(deg2rad($latitude)));
    $minimum=INF;
    foreach($polygons as $polygon){
        if(!is_array($polygon))continue;
        foreach($polygon as $ring){
            if(!is_array($ring)||count($ring)<2)continue;
            $count=count($ring);
            for($index=0;$index<$count;$index++){
                $first=$ring[$index]??null;
                $second=$ring[($index+1)%$count]??null;
                if(!is_array($first)||!is_array($second)||!isset($first[0],$first[1],$second[0],$second[1]))continue;
                $firstLongitude=(float)$first[0]-$longitude;
                $secondLongitude=(float)$second[0]-$longitude;
                while($firstLongitude>180)$firstLongitude-=360;
                while($firstLongitude< -180)$firstLongitude+=360;
                while($secondLongitude>180)$secondLongitude-=360;
                while($secondLongitude< -180)$secondLongitude+=360;
                while($secondLongitude-$firstLongitude>180)$secondLongitude-=360;
                while($secondLongitude-$firstLongitude< -180)$secondLongitude+=360;
                $x1=$firstLongitude*60*$cosLatitude;
                $y1=((float)$first[1]-$latitude)*60;
                $x2=$secondLongitude*60*$cosLatitude;
                $y2=((float)$second[1]-$latitude)*60;
                $dx=$x2-$x1;$dy=$y2-$y1;
                $lengthSquared=$dx*$dx+$dy*$dy;
                $factor=$lengthSquared>0?max(0,min(1,-($x1*$dx+$y1*$dy)/$lengthSquared)):0;
                $distance=hypot($x1+$factor*$dx,$y1+$factor*$dy);
                if($distance<$minimum)$minimum=$distance;
            }
        }
    }
    return $minimum;
}

try {
    $pdo=new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if(empty($_SESSION['web_user_id'])||!validateVfnWebSession($pdo)){http_response_code(401);throw new RuntimeException('login_required');}
    $token=(string)($_SESSION['atc_session_token']??'');
    $sessionStmt=$pdo->prepare("SELECT a.*,u.op_permission FROM atc_sessions a INNER JOIN users u ON u.id=a.user_id WHERE a.user_id=:uid AND a.session_token=:token AND a.is_active=1 AND a.last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1");
    $sessionStmt->execute(['uid'=>(int)$_SESSION['web_user_id'],'token'=>$token]);$session=$sessionStmt->fetch(PDO::FETCH_ASSOC);
    if(!$session){http_response_code(409);throw new RuntimeException('atc_session_inactive');}
    // Der Verkehrs-Poll läuft parallel zu Karte, Voice, Chat und ATIS. Nach der
    // Authentifizierung werden keine Sessiondaten mehr benötigt; die Sperre
    // darf deshalb nicht die übrigen Requests bis zum Ende dieser Abfrage blockieren.
    session_write_close();
    $pdo->exec("DELETE aa FROM atc_assumed_aircraft aa LEFT JOIN atc_sessions a ON a.id=aa.atc_session_id WHERE a.id IS NULL OR a.is_active=0");
    $center=null;
    $airportStmt=$pdo->prepare("SELECT latitude_deg,longitude_deg,name FROM airports WHERE ident=:c1 OR icao_code=:c2 OR gps_code=:c3 LIMIT 1");
    $airportStmt->execute(['c1'=>$session['station_code'],'c2'=>$session['station_code'],'c3'=>$session['station_code']]);
    if($airport=$airportStmt->fetch(PDO::FETCH_ASSOC))$center=['latitude'=>(float)$airport['latitude_deg'],'longitude'=>(float)$airport['longitude_deg'],'name'=>(string)$airport['name']];
    $hideInvisibleRequested=(string)($_COOKIE['vfn_atc_hide_invisible']??'1')!=='0';
    $invisibleCondition=($hideInvisibleRequested||(int)$session['op_permission']<1)
        ? 'AND s.is_invisible=0'
        : 'AND (s.is_invisible=0 OR u.op_permission<='.(int)$session['op_permission'].')';
    $stmt=$pdo->query("SELECT p.user_id,p.session_token,p.callsign,p.aircraft_icao,p.latitude,p.longitude,p.altitude,p.heading,p.airspeed,p.vertical_speed,p.transponder,p.transponder_mode,p.on_ground,s.is_invisible,s.plugin_language,u.preferred_language,u.op_permission,COALESCE(NULLIF(TRIM(u.real_name),''),u.username) AS pilot_name,fp.departure_airport,fp.arrival_airport,fp.route_text,aa.atc_session_id AS assumed_session_id,aa.atc_callsign AS assumed_by,ac.clearance_type,ac.clearance_value,ac.cleared_departure_runway,ac.cleared_landing_runway,ac.cleared_gate,ac.cleared_taxi_route,ac.cleared_sid,ac.cleared_direct,ac.cleared_star,ac.cleared_altitude FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token INNER JOIN users u ON u.id=p.user_id LEFT JOIN pilot_flightplans fp ON fp.session_token=p.session_token LEFT JOIN atc_assumed_aircraft aa ON aa.pilot_session_token=p.session_token LEFT JOIN atc_aircraft_clearances ac ON ac.pilot_session_token=p.session_token WHERE s.is_active=1 AND s.is_spectator=0 $invisibleCondition AND p.last_update>=DATE_SUB(NOW(),INTERVAL 15 SECOND)");
    $ranges=['airport_info'=>15,'airport_delivery'=>8,'airport_ground'=>5,'airport_tower'=>20,'terminal_approach'=>120,'terminal_departure'=>120,'enroute_center'=>99999];
    $range=(float)($ranges[$session['map_profile']]??60);$traffic=[];
    $features=readAtisScopeFeatures($session);
    $station=strtoupper(trim((string)$session['station_code']));
    $airportCoordinates=[];
    $airportCodes=[];
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $trainingRows=[];
    $trainingSelect="SELECT ta.*,aa.atc_session_id AS assumed_session_id,aa.atc_callsign AS assumed_by,
        ac.clearance_type,ac.clearance_value,ac.cleared_departure_runway,ac.cleared_landing_runway,
        ac.cleared_gate,ac.cleared_taxi_route,ac.cleared_sid,ac.cleared_direct,ac.cleared_star,ac.cleared_altitude
        FROM atc_training_aircraft ta
        LEFT JOIN atc_assumed_aircraft aa ON aa.pilot_session_token=CONCAT('training:',ta.id)
        LEFT JOIN atc_aircraft_clearances ac ON ac.pilot_session_token=CONCAT('training:',ta.id)";
    if((int)($session['is_trainer']??0)===1){
        $trainingStmt=$pdo->prepare($trainingSelect." WHERE ta.trainer_session_id=:sid ORDER BY ta.id");
        $trainingStmt->execute(['sid'=>(int)$session['id']]);
    }else{
        // Controllers and spectators both need the synthetic targets in their
        // radar picture. The creator session itself may remain invisible.
        $trainingStmt=$pdo->query($trainingSelect." INNER JOIN atc_sessions creator ON creator.id=ta.trainer_session_id WHERE creator.is_active=1 AND creator.is_trainer=1 AND creator.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) ORDER BY ta.id");
    }
    $trainingRows=$trainingStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){foreach(['departure_airport','arrival_airport'] as $field){$code=strtoupper(trim((string)($row[$field]??'')));if($code!==''&&$code!=='ZZZZ')$airportCodes[$code]=true;}}
    foreach($trainingRows as $row){foreach(['departure_airport','arrival_airport'] as $field){$code=strtoupper(trim((string)($row[$field]??'')));if($code!==''&&$code!=='ZZZZ')$airportCodes[$code]=true;}}
    if($airportCodes){foreach(array_chunk(array_keys($airportCodes),400) as $codes){$marks=implode(',',array_fill(0,count($codes),'?'));$airportQuery=$pdo->prepare("SELECT ident,icao_code,gps_code,name,municipality,latitude_deg,longitude_deg FROM airports WHERE ident IN ($marks) OR icao_code IN ($marks) OR gps_code IN ($marks)");$airportQuery->execute(array_merge($codes,$codes,$codes));foreach($airportQuery->fetchAll(PDO::FETCH_ASSOC) as $airport){foreach(['ident','icao_code','gps_code'] as $field){$code=strtoupper(trim((string)($airport[$field]??'')));if($code!=='')$airportCoordinates[$code]=[(float)$airport['latitude_deg'],(float)$airport['longitude_deg'],(string)($airport['name']??''),(string)($airport['municipality']??'')];}}}}
    $insideScope=static function(float $latitude,float $longitude)use($features):bool{foreach($features as $feature){if(pointInAtisGeometry($longitude,$latitude,$feature['geometry']??[]))return true;}return false;};
    $insideBufferedAircraftScope=static function(float $latitude,float $longitude)use($features):bool{
        foreach($features as $feature){
            $geometry=$feature['geometry']??[];
            if(pointInAtisGeometry($longitude,$latitude,$geometry)||atcGeometryEdgeDistanceNm($latitude,$longitude,$geometry)<=15)return true;
        }
        return false;
    };
    $localProfile=in_array((string)$session['map_profile'],['airport_info','airport_delivery','airport_ground','airport_tower'],true);
    $airportIsRelevant=static function(string $code)use($station,$localProfile,$airportCoordinates,$features,$insideScope):bool{
        $code=strtoupper(trim($code));
        if($code===''||$code==='ZZZZ')return false;
        if($localProfile)return $code===$station;
        return isset($airportCoordinates[$code])&&$features&&$insideScope($airportCoordinates[$code][0],$airportCoordinates[$code][1]);
    };
    foreach($rows as $row){
        $distance=$center?atcDistanceNm($center['latitude'],$center['longitude'],(float)$row['latitude'],(float)$row['longitude']):null;
        $assumedByMe=(int)($row['assumed_session_id']??0)===(int)$session['id'];
        $aircraftInside=$features?$insideBufferedAircraftScope((float)$row['latitude'],(float)$row['longitude']):($distance!==null&&$distance<=$range);
        if($assumedByMe&&!$aircraftInside){
            $pdo->prepare("DELETE FROM atc_assumed_aircraft WHERE pilot_session_token=:pilot_token AND atc_session_id=:atc_session_id")
                ->execute(['pilot_token'=>(string)$row['session_token'],'atc_session_id'=>(int)$session['id']]);
            $language=strtolower((string)($row['plugin_language']?:$row['preferred_language']?:'en'));
            $text=vfnPluginContactMessage($language, 'release', '122.800', (string)$session['callsign']);
            insertChatMessage($pdo,null,(int)$row['user_id'],(int)$session['user_id'],(string)$session['callsign'],'atc_contact',$text);
            $assumedByMe=false;
            $row['assumed_session_id']=null;
            $row['assumed_by']=null;
        }
        $departureRelevant=$airportIsRelevant((string)($row['departure_airport']??''));
        $arrivalRelevant=$airportIsRelevant((string)($row['arrival_airport']??''));
        $airportInside=$departureRelevant||$arrivalRelevant;
        if(!$assumedByMe&&!$aircraftInside&&($localProfile||!$airportInside))continue;
        $row['traffic_inbound']=$arrivalRelevant?1:0;
        $row['traffic_outbound']=$departureRelevant?1:0;
        $row['traffic_through']=(!$arrivalRelevant&&!$departureRelevant&&$aircraftInside)?1:0;
        $departureCode=strtoupper(trim((string)($row['departure_airport']??'')));
        $arrivalCode=strtoupper(trim((string)($row['arrival_airport']??'')));
        $row['departure_airport_name']=(string)($airportCoordinates[$departureCode][2]??'');
        $row['departure_municipality']=(string)($airportCoordinates[$departureCode][3]??'');
        $row['arrival_airport_name']=(string)($airportCoordinates[$arrivalCode][2]??'');
        $row['arrival_municipality']=(string)($airportCoordinates[$arrivalCode][3]??'');
        $row['distance_nm']=$distance;$row['assumed_by_me']=$assumedByMe?1:0;$row['is_assumed']=!empty($row['assumed_session_id'])?1:0;unset($row['user_id'],$row['session_token'],$row['plugin_language'],$row['preferred_language'],$row['assumed_session_id']);$traffic[]=$row;
    }
    if($trainingRows){
        foreach($trainingRows as $training){
            $distance=$center?atcDistanceNm($center['latitude'],$center['longitude'],(float)$training['latitude'],(float)$training['longitude']):null;
            $ownTraining=(int)$training['trainer_session_id']===(int)$session['id'];
            $insideTrainingScope=$features?$insideBufferedAircraftScope((float)$training['latitude'],(float)$training['longitude']):($distance!==null&&$distance<=$range);
            $departureRelevant=$airportIsRelevant((string)($training['departure_airport']??''));
            $arrivalRelevant=$airportIsRelevant((string)($training['arrival_airport']??''));
            $airportInside=$departureRelevant||$arrivalRelevant;
            if(!$ownTraining&&!$insideTrainingScope&&($localProfile||!$airportInside))continue;
            $onGround=((string)$training['placement_type']!=='air'
                && (float)$training['altitude']<=5.0)?1:0;
            $trainingTransponderStatus=strtolower((string)($training['transponder_status']??'standby'));
            $trainingTransponderMode=$trainingTransponderStatus==='ident'?4:($trainingTransponderStatus==='on'?2:1);
            $assumedByMe=(int)($training['assumed_session_id']??0)===(int)$session['id'];
            $traffic[]=[
                'callsign'=>(string)$training['callsign'],'aircraft_icao'=>(string)$training['aircraft_icao'],
                'latitude'=>(float)$training['latitude'],'longitude'=>(float)$training['longitude'],
                'altitude'=>(int)$training['altitude'],'heading'=>(int)$training['heading'],
                'airspeed'=>(int)$training['airspeed'],'vertical_speed'=>0,'transponder'=>(string)($training['transponder_code']??'7000'),
                'transponder_mode'=>$trainingTransponderMode,'transponder_status'=>$trainingTransponderStatus,
                'transponder_code'=>(string)($training['transponder_code']??'7000'),
                'on_ground'=>$onGround,'pilot_name'=>'TRAINING',
                'departure_airport'=>(string)($training['departure_airport']??'ZZZZ'),
                'arrival_airport'=>(string)($training['arrival_airport']??'ZZZZ'),
                'route_text'=>(string)($training['route_text']??''),
                'flight_rules'=>(string)($training['flight_rules']??'I'),
                'flight_type'=>(string)($training['flight_type']??'G'),
                'communication_mode'=>(string)($training['communication_mode']??'VOICE'),
                'alternate1_airport'=>(string)($training['alternate1_airport']??'ZZZZ'),
                'alternate2_airport'=>(string)($training['alternate2_airport']??'ZZZZ'),
                'cruising_level'=>(string)($training['cruising_level']??''),
                'cruising_speed'=>(string)($training['cruising_speed']??''),
                'remarks'=>(string)($training['remarks']??''),
                'assumed_by_me'=>$assumedByMe?1:0,'is_assumed'=>!empty($training['assumed_session_id'])?1:0,
                'assumed_by'=>(string)($training['assumed_by']??''),'distance_nm'=>$distance,
                'clearance_type'=>(string)($training['clearance_type']??''),
                'clearance_value'=>(string)($training['clearance_value']??''),
                'cleared_departure_runway'=>(string)($training['cleared_departure_runway']??''),
                'cleared_landing_runway'=>(string)($training['cleared_landing_runway']??''),
                'cleared_gate'=>(string)($training['cleared_gate']??''),
                'cleared_taxi_route'=>(string)($training['cleared_taxi_route']??''),
                'cleared_sid'=>(string)($training['cleared_sid']??''),
                'cleared_direct'=>(string)($training['cleared_direct']??''),
                'cleared_star'=>(string)($training['cleared_star']??''),
                'cleared_altitude'=>(string)($training['cleared_altitude']??''),
                'traffic_inbound'=>$arrivalRelevant?1:0,
                'traffic_outbound'=>$departureRelevant?1:0,
                'traffic_through'=>(!$arrivalRelevant&&!$departureRelevant&&$insideTrainingScope)?1:0,
                'is_training_aircraft'=>1,'training_aircraft_id'=>(int)$training['id'],
                'training_placement_type'=>(string)$training['placement_type'],
                'training_control_mode'=>(string)($training['control_mode']??'manual'),
                'training_motion_state'=>(string)($training['motion_state']??'parked'),
                'training_target_airspeed'=>(int)($training['target_airspeed']??0),
                'training_target_altitude'=>(int)($training['target_altitude']??0),
                'training_vertical_speed_fpm'=>(int)($training['vertical_speed_fpm']??1000)
            ];
        }
    }
    echo json_encode(['success'=>true,'center'=>$center,'range_nm'=>$range,'traffic'=>$traffic],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $error){if(http_response_code()<400)http_response_code(500);echo json_encode(['success'=>false,'message'=>$error->getMessage()]);}
