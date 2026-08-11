<?php
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/division_schema.php';
require_once __DIR__ . '/includes/division_content.php';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensureDivisionManagementSchema($pdo);
$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$stmt = $pdo->prepare("SELECT * FROM divisions WHERE code = :code AND is_active = 1 LIMIT 1");
$stmt->execute(['code' => $code]);
$division = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$division) { http_response_code(404); }
$viewerId = validateVfnWebSession($pdo) ? (int)($_SESSION['web_user_id'] ?? 0) : 0;
$viewerOp = (int)($_SESSION['web_op_permission'] ?? 0);
$viewerDivision = '';
$viewerRating = 0;
if ($viewerId > 0) {
    $viewerStmt = $pdo->prepare('SELECT division_code,rating_atc FROM users WHERE id=:id LIMIT 1');
    $viewerStmt->execute(['id' => $viewerId]);
    $viewerData = $viewerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $viewerDivision = strtoupper((string)($viewerData['division_code'] ?? ''));
    $viewerRating = (int)($viewerData['rating_atc'] ?? 0);
}
if (empty($_SESSION['gca_csrf'])) $_SESSION['gca_csrf'] = bin2hex(random_bytes(32));
$gcaRequest = null;
if ($division && $viewerId > 0) {
    $gcaStmt = $pdo->prepare('SELECT * FROM guest_controller_approvals WHERE user_id=:uid AND division_code=:division LIMIT 1');
    $gcaStmt->execute(['uid' => $viewerId, 'division' => $code]);
    $gcaRequest = $gcaStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$canEdit = $division && $viewerId > 0 && canEditDivisionContent($pdo, $viewerId, $viewerOp, $code);
$content = $division ? sanitizeDivisionHtml((string)$division['website_content']) : '';
if ($division && $content === '') {
    $content = '<section class="division-hero-card"><h1>%division_name%</h1><p>%division_description%</p></section><section class="division-stat-grid"><article class="division-stat-card"><strong>%division_member_total%</strong><span>Members</span></article><article class="division-stat-card"><strong>%division_flight_hours_total%</strong><span>Flight hours</span></article><article class="division-stat-card"><strong>%division_flights_total%</strong><span>Flights</span></article><article class="division-stat-card"><strong>%division_active_pilots%</strong><span>Online pilots</span></article></section><h2>Division Staff</h2>%division_staff%';
}
?>
<!doctype html><html lang="<?php echo htmlspecialchars($_SESSION['language'] ?? 'en'); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo htmlspecialchars($division['name'] ?? t('division_not_found')); ?> - <?php echo htmlspecialchars($projectName); ?></title>
<style>
.division-identity{display:flex;align-items:center;gap:10px;margin-bottom:18px;color:#9fc4da;font-weight:700}.division-identity img{width:32px;max-height:23px;object-fit:cover}
body{margin:0;background:#071822;color:#d7e8ff;font-family:Arial,sans-serif}.division-shell{width:min(1200px,calc(100% - 40px));margin:35px auto 70px}.division-actions{text-align:right;margin-bottom:18px}.division-button{display:inline-block;padding:11px 18px;border:0;border-radius:8px;background:#1478d4;color:#fff;text-decoration:none;cursor:pointer}.division-content{line-height:1.65}.division-content h1{font-size:42px;color:#fff}.division-content h2{color:#fff;margin-top:35px}.division-hero-card,.division-stat-card{background:#0d2130;border:1px solid #24506b;border-radius:14px;padding:24px}.division-stat-grid,.division-staff-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin:20px 0}.division-stat-card{display:flex;flex-direction:column;gap:6px}.division-stat-card strong{font-size:24px;color:#38e3bd}.division-stat-card a{color:#67b9ff}.division-table-wrap{overflow:auto}.division-table{width:100%;border-collapse:collapse;background:#0d2130}.division-table th,.division-table td{padding:12px;border-bottom:1px solid #24465a;text-align:left}.division-table a{color:#67b9ff}.not-found{text-align:center;padding:100px 20px}.gca-card{margin:28px 0;padding:22px;background:#0d2130;border:1px solid #24506b;border-radius:14px}.gca-card textarea{width:100%;box-sizing:border-box;min-height:90px;background:#071822;color:#fff;border:1px solid #35647d;padding:10px}.gca-status{color:#38e3bd;font-weight:700}
</style></head><body><?php include __DIR__ . '/includes/header.php'; ?>
<?php if (!$division): ?><main class="not-found"><h1><?php echo htmlspecialchars(t('division_not_found')); ?></h1></main>
<?php else: ?><main class="division-shell"><?php if ($canEdit): ?><div class="division-actions"><a class="division-button" href="admin_divisions.php?code=<?php echo urlencode($code); ?>"><?php echo htmlspecialchars(t('division_edit')); ?></a></div><?php endif; ?><div class="division-identity"><img src="images/flags/<?php echo htmlspecialchars(strtolower($code)); ?>.png" alt=""><?php echo htmlspecialchars($division['name']); ?></div><div class="division-content"><?php echo renderDivisionContent($pdo, $division, $content); ?></div><?php if($viewerId>0 && $viewerDivision!==$code): ?><section class="gca-card"><h2><?php echo htmlspecialchars(t('gca_request_title')); ?></h2><p><?php echo htmlspecialchars(t('gca_request_help')); ?></p><?php if($gcaRequest): ?><p class="gca-status"><?php echo htmlspecialchars(t('gca_current_status').': '.t('gca_status_'.$gcaRequest['status'])); ?></p><?php endif; ?><?php if(empty($division['gca_requests_enabled'])): ?><p><?php echo htmlspecialchars(t('gca_requests_closed')); ?></p><?php elseif($viewerRating>=5 && (!$gcaRequest || $gcaRequest['status']!=='approved')): ?><form method="post" action="execute/gca_request.php"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['gca_csrf']); ?>"><input type="hidden" name="division_code" value="<?php echo htmlspecialchars($code); ?>"><textarea name="request_message" placeholder="<?php echo htmlspecialchars(t('gca_request_message')); ?>"></textarea><p><button class="division-button" type="submit"><?php echo htmlspecialchars(t('gca_request_submit')); ?></button></p></form><?php elseif($viewerRating<5): ?><p><?php echo htmlspecialchars(t('gca_rating_too_low')); ?></p><?php endif; ?></section><?php endif; ?><?php if($viewerId>0 && canManageDivisionGca($pdo,$viewerId,$viewerOp,$code)): ?><div class="division-actions"><a class="division-button" href="admin_gca.php"><?php echo htmlspecialchars(t('gca_manage')); ?></a></div><?php endif; ?></main><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; require_once __DIR__ . '/includes/auth_modals.php'; ?></body></html>
