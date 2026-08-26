<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/atc_schema.php';

function onlineActionExpiry(string $duration): array
{
    $duration = strtolower(trim($duration));
    if ($duration === 'permanent') return [null, 'permanent'];
    if (!preg_match('/^(\d+)(min|h|d|w|mo|y)$/', $duration, $match)) {
        throw new RuntimeException('moderation_invalid_duration');
    }
    $value = max(1, (int)$match[1]);
    $units = ['min'=>'minutes','h'=>'hours','d'=>'days','w'=>'weeks','mo'=>'months','y'=>'years'];
    $limits = ['min'=>525600,'h'=>8760,'d'=>3650,'w'=>520,'mo'=>120,'y'=>10];
    if ($value > $limits[$match[2]]) throw new RuntimeException('moderation_invalid_duration');
    return [(new DateTimeImmutable())->modify("+$value {$units[$match[2]]}")->format('Y-m-d H:i:s'), $duration];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('method_not_allowed'); }
    $pdo = createAdminPdo();
    $actor = requireAdminUser($pdo, 1);
    $csrf = (string)($_POST['csrf'] ?? '');
    if (empty($_SESSION['admin_csrf']) || !hash_equals((string)$_SESSION['admin_csrf'], $csrf)) {
        http_response_code(403); throw new RuntimeException('invalid_csrf');
    }
    $targetId = (int)($_POST['target_user_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($targetId < 1 || !in_array($action, ['warning','kick','ban'], true)
        || $reason === '' || mb_strlen($reason) > 255) {
        http_response_code(422); throw new RuntimeException('moderation_invalid_request');
    }
    $targetStmt = $pdo->prepare('SELECT id,op_permission FROM users WHERE id=:id LIMIT 1');
    $targetStmt->execute(['id'=>$targetId]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$target || (int)$target['id']===(int)$actor['id']
        || (int)$target['op_permission'] >= (int)$actor['op_permission']) {
        http_response_code(403); throw new RuntimeException('moderation_not_allowed');
    }

    $pdo->beginTransaction();
    $activityKey = '';
    $activityValue = $reason;
    if ($action === 'warning') {
        $pdo->prepare(
            'INSERT INTO user_warnings(user_id,issued_by_user_id,reason,expires_at)
             VALUES(:user,:actor,:reason,NULL)'
        )->execute(['user'=>$targetId,'actor'=>$actor['id'],'reason'=>$reason]);
        $activityKey = 'activity_warning_issued';
        $activityValue .= ' [permanent]';
    } elseif ($action === 'ban') {
        [$expires, $label] = onlineActionExpiry((string)($_POST['duration'] ?? ''));
        $pdo->prepare(
            'UPDATE users SET is_banned=1,ban_reason=:reason,ban_expires_at=:expires,
             banned_at=NOW(),banned_by_user_id=:actor WHERE id=:user'
        )->execute(['reason'=>$reason,'expires'=>$expires,'actor'=>$actor['id'],'user'=>$targetId]);
        $activityKey = 'activity_banned';
        $activityValue .= " [$label]";
    } else {
        $activityKey = 'activity_kicked';
    }
    if ($action !== 'warning') {
        $pdo->prepare("UPDATE pilot_flights SET status='aborted',completed_at=NOW()
                       WHERE user_id=:user AND status='active'")->execute(['user'=>$targetId]);
        $pdo->prepare('UPDATE user_sessions SET is_active=0,last_seen=NOW()
                       WHERE user_id=:user AND is_active=1')->execute(['user'=>$targetId]);
        $pdo->prepare('DELETE FROM pilot_positions WHERE user_id=:user')->execute(['user'=>$targetId]);
        $pdo->prepare('UPDATE atc_sessions SET is_active=0,disconnected_at=NOW()
                       WHERE user_id=:user AND is_active=1')->execute(['user'=>$targetId]);
        archiveAtcSessions($pdo, 'a.user_id=:history_user AND a.is_active=0', ['history_user'=>$targetId]);
    }
    $pdo->prepare(
        'INSERT INTO user_activity_log(user_id,actor_user_id,activity_type,activity_key,activity_value)
         VALUES(:user,:actor,:type,:activity_key,:activity_value)'
    )->execute(['user'=>$targetId,'actor'=>$actor['id'],'type'=>$action,
        'activity_key'=>$activityKey,'activity_value'=>$activityValue]);
    $pdo->commit();
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$error->getMessage()], JSON_UNESCAPED_UNICODE);
}
