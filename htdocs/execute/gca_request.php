<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/division_schema.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/chat_system.php';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!validateVfnWebSession($pdo)) { header('Location: ../index.php'); exit; }
ensureDivisionManagementSchema($pdo);
$userId = (int)($_SESSION['web_user_id'] ?? 0);
$code = strtoupper(trim((string)($_POST['division_code'] ?? '')));
$csrf = (string)($_POST['csrf'] ?? '');
if (!hash_equals((string)($_SESSION['gca_csrf'] ?? ''), $csrf)) {
    header('Location: ../division.php?code=' . urlencode($code) . '&gca=csrf'); exit;
}
$message = trim(mb_substr((string)($_POST['request_message'] ?? ''), 0, 2000));
$stmt = $pdo->prepare('SELECT division_code, rating_atc FROM users WHERE id=:id LIMIT 1');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$valid = $pdo->prepare('SELECT gca_requests_enabled FROM divisions WHERE code=:code AND is_active=1 LIMIT 1');
$valid->execute(['code' => $code]);
$gcaRequestsEnabled = $valid->fetchColumn();
if ($gcaRequestsEnabled === false || (int)$gcaRequestsEnabled !== 1 || $code === strtoupper((string)($user['division_code'] ?? '')) || (int)($user['rating_atc'] ?? 0) < 5) {
    header('Location: ../division.php?code=' . urlencode($code) . '&gca=invalid'); exit;
}
$insert = $pdo->prepare(
    "INSERT INTO guest_controller_approvals (user_id,division_code,status,request_message,review_note,reviewed_by_user_id,requested_at,reviewed_at)
     VALUES (:user_id,:division,'pending',:message,NULL,NULL,NOW(),NULL)
     ON DUPLICATE KEY UPDATE status='pending',request_message=VALUES(request_message),review_note=NULL,
       reviewed_by_user_id=NULL,requested_at=NOW(),reviewed_at=NULL"
);
$insert->execute(['user_id' => $userId, 'division' => $code, 'message' => $message]);
logActivity($pdo, $userId, 'gca_requested', 'activity_gca_requested', $code, $userId);
$leaders = $pdo->prepare("SELECT user_id FROM division_staff WHERE division_code=:division AND role_code IN ('DIR','ADIR') AND is_active=1");
$leaders->execute(['division' => $code]);
foreach ($leaders->fetchAll(PDO::FETCH_COLUMN) as $leaderId) {
    $leaderId = (int)$leaderId;
    logActivity($pdo, $leaderId, 'gca_requested', 'activity_gca_requested', $code, $userId);
    insertChatMessage($pdo, null, $leaderId, $userId, 'VFN SYSTEM', 'system', '[PM] New GCA request / Neuer GCA-Antrag: ' . $code);
}
header('Location: ../division.php?code=' . urlencode($code) . '&gca=submitted');
