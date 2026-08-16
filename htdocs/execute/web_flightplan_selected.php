<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_features_schema.php';

try {
    $token = trim((string)($_POST['token'] ?? ''));
    if ($token === '') throw new RuntimeException('token_required');
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    ensureWebFeatureSchema($pdo);
    $stmt=$pdo->prepare("SELECT user_id,is_spectator FROM user_sessions WHERE token=:token AND is_active=1 LIMIT 1");
    $stmt->execute(['token'=>$token]); $session=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$session) throw new RuntimeException('invalid_session');
    if((int)($session['is_spectator']??0)===1) throw new RuntimeException('spectator_disabled');
    $search=mb_substr(trim((string)($_POST['search']??'')),0,100);
    $action=trim((string)($_POST['action']??'load'));
    $base="SELECT id,callsign,flight_rules,flight_type,communication_mode,departure_time,departure_airport,arrival_airport,alternate1_airport,alternate2_airport,route_text,cruising_level,cruising_speed,remarks,updated_at FROM web_flightplans WHERE user_id=:uid";
    $params=['uid'=>(int)$session['user_id']];
    if($action==='list'){
        $sql="SELECT id,callsign,departure_airport,arrival_airport,updated_at FROM web_flightplans WHERE user_id=:uid";
        if($search!==''){
            $sql.=" AND (CAST(id AS CHAR)=:qid OR callsign LIKE :q1 OR departure_airport LIKE :q2 OR arrival_airport LIKE :q3 OR route_text LIKE :q4)";
            $like='%'.$search.'%';
            $params+=['qid'=>$search,'q1'=>$like,'q2'=>$like,'q3'=>$like,'q4'=>$like];
        }
        $stmt=$pdo->prepare($sql." ORDER BY updated_at DESC,id DESC LIMIT 50");
        $stmt->execute($params);
        $plans=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $response=['success'=>true,'count'=>count($plans)];
        foreach($plans as $index=>$item){
            $prefix='plan_'.$index.'_';
            $response[$prefix.'id']=(int)$item['id'];
            $response[$prefix.'callsign']=(string)$item['callsign'];
            $response[$prefix.'departure']=(string)$item['departure_airport'];
            $response[$prefix.'arrival']=(string)$item['arrival_airport'];
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    $requestedId=(int)($_POST['id']??0);
    if($requestedId>0){
        $stmt=$pdo->prepare($base." AND id=:id LIMIT 1");
        $stmt->execute(['uid'=>(int)$session['user_id'],'id'=>$requestedId]);
        $plan=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$plan) throw new RuntimeException('web_flightplan_not_found');
        echo json_encode(['success'=>true,'flightplan'=>$plan], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    if($search===''){
        $stmt=$pdo->prepare($base." AND plugin_selected=1 ORDER BY updated_at DESC LIMIT 1");
    }else{
        $stmt=$pdo->prepare($base." AND (CAST(id AS CHAR)=:qid OR callsign LIKE :q1 OR departure_airport LIKE :q2 OR arrival_airport LIKE :q3 OR route_text LIKE :q4) ORDER BY (CAST(id AS CHAR)=:qid_order) DESC,plugin_selected DESC,updated_at DESC LIMIT 1");
        $like='%'.$search.'%';
        $params+=['qid'=>$search,'q1'=>$like,'q2'=>$like,'q3'=>$like,'q4'=>$like,'qid_order'=>$search];
    }
    $stmt->execute($params); $plan=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$plan) throw new RuntimeException('no_selected_web_flightplan');
    echo json_encode(['success'=>true,'flightplan'=>$plan], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
