<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config.php';

$query=trim((string)($_GET['q']??''));
if(mb_strlen($query)<2){echo json_encode(['success'=>true,'players'=>[]]);exit;}
try{
    $pdo=new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $stmt=$pdo->prepare("SELECT id,username,real_name,country_code FROM users WHERE is_active=1 AND (username LIKE :q1 OR real_name LIKE :q2 OR email LIKE :q3) ORDER BY username LIMIT 15");
    $like='%'.$query.'%';$stmt->execute(['q1'=>$like,'q2'=>$like,'q3'=>$like]);
    echo json_encode(['success'=>true,'players'=>$stmt->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(500);echo json_encode(['success'=>false,'message'=>'server_error']);}
