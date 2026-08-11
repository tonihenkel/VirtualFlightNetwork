<?php
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/division_schema.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/chat_system.php';

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$logged = validateVfnWebSession($pdo);
ensureDivisionManagementSchema($pdo);
$userId = (int)($_SESSION['web_user_id'] ?? 0);
$op = (int)($_SESSION['web_op_permission'] ?? 0);
if (empty($_SESSION['gca_admin_csrf'])) {
    $_SESSION['gca_admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['gca_admin_csrf'];
$notice = '';

if ($logged && $_SERVER['REQUEST_METHOD'] === 'POST'
    && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle_requests') {
        $divisionCode = strtoupper(trim((string)($_POST['division_code'] ?? '')));
        if (canManageDivisionGcaSettings($pdo, $userId, $op, $divisionCode)) {
            $stmt = $pdo->prepare(
                'UPDATE divisions SET gca_requests_enabled=:enabled WHERE code=:code'
            );
            $stmt->execute([
                'enabled' => empty($_POST['enabled']) ? 0 : 1,
                'code' => $divisionCode,
            ]);
            $notice = t('gca_settings_saved');
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $find = $pdo->prepare('SELECT * FROM guest_controller_approvals WHERE id=:id LIMIT 1');
        $find->execute(['id' => $id]);
        $request = $find->fetch(PDO::FETCH_ASSOC);
        $allowedActions = ['pending', 'approve', 'reject', 'revoke', 'delete'];
        if ($request && in_array($action, $allowedActions, true)
            && canManageDivisionGca($pdo, $userId, $op, (string)$request['division_code'])) {
            $note = trim(mb_substr((string)($_POST['review_note'] ?? ''), 0, 2000));
            if ($action === 'delete') {
                $pdo->prepare('DELETE FROM guest_controller_approvals WHERE id=:id')
                    ->execute(['id' => $id]);
                $status = 'deleted';
            } else {
                $status = $action === 'approve' ? 'approved'
                    : ($action === 'reject' ? 'rejected'
                    : ($action === 'revoke' ? 'revoked' : 'pending'));
                $save = $pdo->prepare(
                    'UPDATE guest_controller_approvals
                     SET status=:status,review_note=:note,reviewed_by_user_id=:reviewer,
                         reviewed_at=NOW() WHERE id=:id'
                );
                $save->execute([
                    'status' => $status,
                    'note' => $note,
                    'reviewer' => $userId,
                    'id' => $id,
                ]);
            }
            logActivity(
                $pdo,
                (int)$request['user_id'],
                'gca_' . $status,
                'activity_gca_' . $status,
                (string)$request['division_code'],
                $userId
            );
            insertChatMessage(
                $pdo,
                null,
                (int)$request['user_id'],
                $userId,
                'VFN STAFF',
                'system',
                '[PM] GCA ' . $request['division_code'] . ': '
                    . t('gca_status_' . $status) . ($note !== '' ? ' - ' . $note : '')
            );
            $notice = t('gca_saved');
        }
    }
}

$divisionWhere = '';
$divisionParams = [];
if ($op < 1) {
    $divisionWhere = " WHERE EXISTS(
        SELECT 1 FROM division_staff ds
        WHERE ds.division_code=d.code AND ds.user_id=:settings_user AND ds.is_active=1
    )";
    $divisionParams['settings_user'] = $userId;
}
$divisionStmt = $pdo->prepare(
    'SELECT d.code,d.name,d.gca_requests_enabled FROM divisions d'
    . $divisionWhere . ' ORDER BY d.name'
);
$divisionStmt->execute($divisionParams);
$managedDivisions = $divisionStmt->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];
if ($op < 1) {
    $where[] = "EXISTS(SELECT 1 FROM division_staff ds
        WHERE ds.user_id=:manager AND ds.division_code=g.division_code
          AND ds.role_code IN ('DIR','ADIR') AND ds.is_active=1)";
    $params['manager'] = $userId;
}
$sql = "SELECT g.*,u.username,u.real_name,u.rating_atc,d.name division_name,
               COALESCE(NULLIF(TRIM(r.real_name),''),r.username) reviewer_name
        FROM guest_controller_approvals g
        JOIN users u ON u.id=g.user_id
        JOIN divisions d ON d.code=g.division_code
        LEFT JOIN users r ON r.id=g.reviewed_by_user_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY FIELD(g.status,'pending','approved','rejected','revoked'),g.requested_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($_SESSION['language'] ?? 'en'); ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars(t('gca_admin_title')); ?></title>
<style>
body{margin:0;background:#071822;color:#d7e8ff;font-family:Arial}.wrap{width:min(1200px,calc(100% - 34px));margin:35px auto}.card{background:#0d2130;border:1px solid #24506b;border-radius:12px;padding:18px;margin:14px 0}.pending{border-left:5px solid #ffb020}.approved{border-left:5px solid #32d59b}.rejected,.revoked{border-left:5px solid #ef5865}.meta{color:#91b5ca}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}.division-setting{padding:10px 0;border-bottom:1px solid #234254}.division-setting strong{margin-right:auto}textarea{width:100%;box-sizing:border-box;background:#071822;color:#fff;border:1px solid #35647d;padding:9px}.btn{padding:9px 14px;border:0;border-radius:5px;background:#1677d2;color:#fff;cursor:pointer}.danger{background:#a93643}
</style></head><body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="wrap"><h1><?php echo htmlspecialchars(t('gca_admin_title')); ?></h1>
<p><?php echo htmlspecialchars(t('gca_admin_help')); ?></p>
<?php if (!$logged): ?><p><?php echo htmlspecialchars(t('login_required')); ?></p>
<?php else: ?>
<?php if ($notice): ?><div class="card approved"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
<?php if ($managedDivisions): ?><section class="card"><h2><?php echo htmlspecialchars(t('gca_request_settings')); ?></h2>
<?php foreach ($managedDivisions as $division): ?><form method="post" class="actions division-setting">
<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
<input type="hidden" name="action" value="toggle_requests">
<input type="hidden" name="division_code" value="<?php echo htmlspecialchars($division['code']); ?>">
<input type="hidden" name="enabled" value="<?php echo empty($division['gca_requests_enabled']) ? 1 : 0; ?>">
<strong><?php echo htmlspecialchars($division['code'] . ' · ' . $division['name']); ?></strong>
<span><?php echo htmlspecialchars(empty($division['gca_requests_enabled']) ? t('gca_requests_disabled') : t('gca_requests_enabled')); ?></span>
<button class="btn" type="submit"><?php echo htmlspecialchars(empty($division['gca_requests_enabled']) ? t('gca_requests_enable') : t('gca_requests_disable')); ?></button>
</form><?php endforeach; ?></section><?php endif; ?>
<?php foreach ($requests as $item): ?><article class="card <?php echo htmlspecialchars($item['status']); ?>">
<h2><?php echo htmlspecialchars(($item['real_name'] ?: $item['username']) . ' → ' . $item['division_code'] . ' · ' . t('gca_status_' . $item['status'])); ?></h2>
<p class="meta"><?php echo htmlspecialchars($item['division_name'] . ' · ATC rating ' . $item['rating_atc'] . ' · ' . $item['requested_at']); ?></p>
<p><?php echo nl2br(htmlspecialchars($item['request_message'] ?: '–')); ?></p>
<?php if ($item['review_note']): ?><p><strong><?php echo htmlspecialchars(t('gca_review_note')); ?>:</strong> <?php echo nl2br(htmlspecialchars($item['review_note'])); ?></p><?php endif; ?>
<?php if (canManageDivisionGca($pdo, $userId, $op, (string)$item['division_code'])): ?><form method="post">
<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
<textarea name="review_note" placeholder="<?php echo htmlspecialchars(t('gca_review_note')); ?>"><?php echo htmlspecialchars((string)$item['review_note']); ?></textarea>
<div class="actions"><button class="btn" name="action" value="approve"><?php echo htmlspecialchars(t('gca_approve')); ?></button>
<button class="btn danger" name="action" value="reject"><?php echo htmlspecialchars(t('gca_reject')); ?></button>
<?php if ($item['status'] === 'approved'): ?><button class="btn danger" name="action" value="revoke"><?php echo htmlspecialchars(t('gca_revoke')); ?></button><?php endif; ?>
<?php if ($item['status'] !== 'pending'): ?><button class="btn" name="action" value="pending"><?php echo htmlspecialchars(t('gca_reopen')); ?></button><?php endif; ?>
<button class="btn danger" name="action" value="delete" onclick="return confirm('<?php echo htmlspecialchars(t('gca_delete_confirm'), ENT_QUOTES); ?>')"><?php echo htmlspecialchars(t('gca_delete')); ?></button></div>
</form><?php endif; ?></article><?php endforeach; ?>
<?php endif; ?></main><?php include __DIR__ . '/includes/footer.php'; ?></body></html>
