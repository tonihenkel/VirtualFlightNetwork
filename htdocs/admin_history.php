<?php
require_once __DIR__ . '/execute/admin_auth.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_features_schema.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$pdo = createAdminPdo();
$staff = requireAdminUser($pdo, 4);
ensureWebFeatureSchema($pdo);

$search = trim((string)($_GET['search'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(target.username LIKE :search OR target.real_name LIKE :search
        OR actor.username LIKE :search OR l.activity_value LIKE :search)';
    $params['search'] = '%' . mb_substr($search, 0, 120) . '%';
}
if ($type !== '') {
    $where[] = 'l.activity_type=:type';
    $params['type'] = mb_substr($type, 0, 40);
}
$whereSql = implode(' AND ', $where);
$count = $pdo->prepare(
    "SELECT COUNT(*) FROM user_activity_log l
     LEFT JOIN users target ON target.id=l.user_id
     LEFT JOIN users actor ON actor.id=l.actor_user_id WHERE $whereSql"
);
$count->execute($params);
$total = (int)$count->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT l.*, target.username AS target_username, target.real_name AS target_name,
            actor.username AS actor_username
     FROM user_activity_log l
     LEFT JOIN users target ON target.id=l.user_id
     LEFT JOIN users actor ON actor.id=l.actor_user_id
     WHERE $whereSql ORDER BY l.created_at DESC LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$types = $pdo->query("SELECT DISTINCT activity_type FROM user_activity_log ORDER BY activity_type")
    ->fetchAll(PDO::FETCH_COLUMN);
$pages = max(1, (int)ceil($total / $perPage));
?>
<!doctype html><html lang="<?php echo h($_SESSION['language'] ?? 'en'); ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h(t('admin_private_history')); ?></title>
<style>
body{margin:0;background:#07141f;color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1450px,calc(100% - 36px));margin:28px auto}.card{background:#0d1d2a;border:1px solid #285475;border-radius:8px;padding:20px}.filters{display:grid;grid-template-columns:2fr 1fr auto;gap:10px;margin-bottom:18px}input,select,button,.button{background:#071521;color:#fff;border:1px solid #285475;border-radius:4px;padding:10px}.button,button{background:#176dcc;text-decoration:none;cursor:pointer}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #203b50;text-align:left;vertical-align:top}th{color:#7dbdff}.pager{display:flex;gap:5px;flex-wrap:wrap;margin-top:16px}.pager a{color:#9ed4ff;border:1px solid #285475;padding:7px 10px;text-decoration:none}.pager .active{background:#176dcc;color:#fff}@media(max-width:700px){.filters{grid-template-columns:1fr}.scroll{overflow:auto}}
</style></head><body><?php require __DIR__ . '/includes/header.php'; ?><main class="shell">
<p><a class="button" href="admin.php"><?php echo h(t('admin_back')); ?></a></p>
<h1><?php echo h(t('admin_private_history')); ?></h1>
<section class="card"><form class="filters" method="get">
<input name="search" value="<?php echo h($search); ?>" placeholder="<?php echo h(t('admin_search_messages')); ?>">
<select name="type"><option value=""><?php echo h(t('admin_filter_all')); ?></option><?php foreach($types as $value): ?><option <?php echo $value===$type?'selected':''; ?>><?php echo h($value); ?></option><?php endforeach; ?></select>
<button><?php echo h(t('admin_filter')); ?></button></form>
<div class="scroll"><table><thead><tr><th><?php echo h(t('admin_time')); ?></th><th><?php echo h(t('admin_type')); ?></th><th><?php echo h(t('admin_players_name')); ?></th><th><?php echo h(t('profile_checked_by')); ?></th><th><?php echo h(t('admin_message')); ?></th></tr></thead><tbody>
<?php foreach($items as $item): ?><tr><td><?php echo h($item['created_at']); ?></td><td><?php echo h(t($item['activity_key'])); ?><br><small><?php echo h($item['activity_type']); ?></small></td><td><a href="admin_user.php?id=<?php echo (int)$item['user_id']; ?>"><?php echo h($item['target_name']?:$item['target_username']?:'-'); ?></a></td><td><?php echo h($item['actor_username']?:t('admin_system')); ?></td><td><?php echo h((string)$item['activity_value']); ?></td></tr><?php endforeach; ?>
</tbody></table></div><nav class="pager"><?php for($i=1;$i<=$pages;$i++): ?><a class="<?php echo $i===$page?'active':''; ?>" href="?<?php echo h(http_build_query(['search'=>$search,'type'=>$type,'page'=>$i])); ?>"><?php echo $i; ?></a><?php endfor; ?></nav>
</section></main><?php require __DIR__ . '/includes/footer.php'; ?></body></html>
