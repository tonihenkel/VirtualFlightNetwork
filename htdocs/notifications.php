<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/web_features_schema.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/division_schema.php';

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function notificationActivityValue(array $item): string
{
    $activityKey = (string)($item['activity_key'] ?? '');
    $value = (string)($item['activity_value'] ?? '');

    if ($activityKey === 'activity_award_unlocked' && preg_match('/^award_[a-z0-9_]+$/', $value)) {
        return t($value);
    }

    if ($activityKey === 'activity_database_reset') {
        return t('activity_database_reset_details');
    }

    if ($activityKey === 'activity_admin_user_updated') {
        $fieldLabels = [
            'rating_atc' => t('profile_atc_rating'),
            'rating_pilot' => t('profile_pilot_rating'),
            'rating_special' => t('profile_special_rating'),
            'op_permission' => t('notification_field_op_permission'),
        ];
        foreach ($fieldLabels as $field => $label) {
            $value = preg_replace(
                '/(^|;\\s*)' . preg_quote($field, '/') . '(?=:)/',
                '$1' . str_replace('$', '\\$', (string)$label),
                $value
            ) ?? $value;
        }
    }

    return $value;
}

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
    header('Location: index.php?type=error&message=login_required'); exit;
}
ensureWebFeatureSchema($pdo);
ensureDivisionManagementSchema($pdo);
$userId = (int)$_SESSION['web_user_id'];
$op = (int)($_SESSION['web_op_permission'] ?? 0);
$csrf = csrfToken('notifications');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfIsValid($_POST['csrf'] ?? null, 'notifications')) {
        http_response_code(400); exit(t('csrf_invalid'));
    }
    $pdo->prepare("UPDATE user_activity_log SET is_read=1 WHERE user_id=:uid")
        ->execute(['uid'=>$userId]);
    $stmt=$pdo->prepare("SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE recipient_user_id=:uid AND sender_user_id<>:uid");
    $stmt->execute(['uid'=>$userId]); $maxPm=(int)$stmt->fetchColumn();
    $pdo->prepare("INSERT INTO web_notification_state(user_id,last_private_message_id) VALUES(:uid,:mid)
        ON DUPLICATE KEY UPDATE last_private_message_id=VALUES(last_private_message_id)")
        ->execute(['uid'=>$userId,'mid'=>$maxPm]);
    header('Location: notifications.php'); exit;
}

$activity=$pdo->prepare("SELECT l.*,COALESCE(NULLIF(TRIM(a.real_name),''),a.username) actor_name FROM user_activity_log l
    LEFT JOIN users a ON a.id=l.actor_user_id WHERE l.user_id=:uid ORDER BY l.created_at DESC LIMIT 100");
$activity->execute(['uid'=>$userId]); $activities=$activity->fetchAll(PDO::FETCH_ASSOC);
$pm=$pdo->prepare("SELECT c.id,c.sender_user_id,c.sender_callsign,c.message_text,c.created_at,u.username
    FROM chat_messages c LEFT JOIN users u ON u.id=c.sender_user_id
    WHERE c.recipient_user_id=:uid AND c.sender_user_id<>:uid AND c.message_text LIKE '[PM]%'
    ORDER BY c.id DESC LIMIT 50");
$pm->execute(['uid'=>$userId]); $privateMessages=$pm->fetchAll(PDO::FETCH_ASSOC);
$pending=[];
if($op>1){$pending['division']=(int)$pdo->query("SELECT COUNT(*) FROM division_transfer_requests WHERE status='pending'")->fetchColumn();}
if($op>=4){$pending['appeals']=(int)$pdo->query("SELECT COUNT(*) FROM ban_appeal_requests WHERE status='pending'")->fetchColumn();}
if($op>=1){$pending['gca']=(int)$pdo->query("SELECT COUNT(*) FROM guest_controller_approvals WHERE status='pending'")->fetchColumn();}
else {
    $gcaPending=$pdo->prepare("SELECT COUNT(*) FROM guest_controller_approvals g WHERE g.status='pending' AND EXISTS(SELECT 1 FROM division_staff ds WHERE ds.user_id=:uid AND ds.division_code=g.division_code AND ds.role_code IN ('DIR','ADIR') AND ds.is_active=1)");
    $gcaPending->execute(['uid'=>$userId]); $pending['gca']=(int)$gcaPending->fetchColumn();
}
?>
<!doctype html><html lang="<?php echo h($currentLanguage); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo h(t('notifications_title')); ?></title>
<style>body{margin:0;background:#07141f;color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1200px,calc(100% - 36px));margin:30px auto}.head{display:flex;justify-content:space-between;align-items:center;gap:15px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.card{background:#0d1d2a;border:1px solid #285475;border-radius:8px;padding:18px}.item{padding:12px;border-bottom:1px solid #203b50}.item.unread{border-left:3px solid #ff4d4d}.meta{color:#89a4bb;font-size:12px;margin-top:5px}.button,button{background:#176dcc;color:#fff;border:0;border-radius:5px;padding:10px 14px;text-decoration:none;cursor:pointer}.pending{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}.badge{padding:12px;background:#351923;border:1px solid #9b3c4d;border-radius:6px;color:#ff9cac}@media(max-width:850px){.grid{grid-template-columns:1fr}.head{align-items:flex-start;flex-direction:column}}</style></head><body>
<?php require __DIR__.'/includes/header.php'; ?><main class="shell"><div class="head"><div><h1><?php echo h(t('notifications_title')); ?></h1><p><?php echo h(t('notifications_text')); ?></p></div><form method="post"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><button><?php echo h(t('notifications_mark_all_read')); ?></button></form></div>
<?php if($pending): ?><div class="pending"><?php if(!empty($pending['division'])):?><a class="badge" href="admin.php?tab=transfers"><?php echo h(t('notifications_division_pending')); ?>: <?php echo $pending['division'];?></a><?php endif;?><?php if(!empty($pending['appeals'])):?><a class="badge" href="admin.php?tab=moderation"><?php echo h(t('notifications_appeals_pending')); ?>: <?php echo $pending['appeals'];?></a><?php endif;?><?php if(!empty($pending['gca'])):?><a class="badge" href="admin_gca.php"><?php echo h(t('notifications_gca_pending')); ?>: <?php echo $pending['gca'];?></a><?php endif;?></div><?php endif;?>
<div class="grid"><section class="card"><h2><?php echo h(t('notifications_activity')); ?></h2><?php if(!$activities):?><p><?php echo h(t('notifications_empty'));?></p><?php endif;?><?php foreach($activities as $item):?><div class="item <?php echo empty($item['is_read'])?'unread':'';?>"><strong><?php echo h(t((string)$item['activity_key']));?></strong><div><?php echo h(notificationActivityValue($item));?></div><div class="meta"><?php echo h($item['actor_name']?:'SYSTEM');?> &middot; <?php echo h(date('d.m.Y H:i',strtotime($item['created_at'])));?></div></div><?php endforeach;?></section>
<section class="card"><h2><?php echo h(t('notifications_private_messages')); ?></h2><p><a class="button" href="messages.php"><?php echo h(t('notifications_open_messages'));?></a></p><?php if(!$privateMessages):?><p><?php echo h(t('notifications_empty'));?></p><?php endif;?><?php foreach($privateMessages as $item):?><div class="item"><strong><?php echo h($item['sender_callsign']);?></strong><div><?php echo h(preg_replace('/^\[PM\]\s*/','',$item['message_text']));?></div><div class="meta"><?php echo h(date('d.m.Y H:i',strtotime($item['created_at'])));?></div></div><?php endforeach;?></section></div></main><?php require __DIR__.'/includes/footer.php';?></body></html>
