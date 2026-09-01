<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/web_features_schema.php';

function afpH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
    header('Location: index.php?type=error&message=login_required');
    exit;
}

$adminStmt = $pdo->prepare(
    'SELECT id,op_permission FROM users WHERE id=:id LIMIT 1'
);
$adminStmt->execute(['id' => (int)$_SESSION['web_user_id']]);
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if ((int)($admin['op_permission'] ?? 0) < 1) {
    http_response_code(403);
    exit(afpH(t('admin_access_denied')));
}

ensureWebFeatureSchema($pdo);

$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$status = (string)($_GET['status'] ?? '');
$rules = (string)($_GET['rules'] ?? '');
$communication = (string)($_GET['communication'] ?? '');
$selected = (string)($_GET['selected'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

if (!in_array($status, ['', 'draft', 'filed', 'archived'], true)) $status = '';
if (!in_array($rules, ['', 'I', 'V', 'Y', 'Z'], true)) $rules = '';
if (!in_array($communication, ['', 'VOICE', 'RECEIVE_ONLY', 'TEXT_ONLY'], true)) $communication = '';
if (!in_array($selected, ['', '0', '1'], true)) $selected = '';

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = "(w.callsign LIKE :search OR u.username LIKE :search
        OR u.real_name LIKE :search OR w.departure_airport LIKE :search
        OR w.arrival_airport LIKE :search OR w.route_text LIKE :search
        OR w.remarks LIKE :search OR CAST(w.id AS CHAR)=:exact_id)";
    $params['search'] = '%' . $search . '%';
    $params['exact_id'] = $search;
}
if ($status !== '') {
    $where[] = 'w.status=:status';
    $params['status'] = $status;
}
if ($rules !== '') {
    $where[] = 'w.flight_rules=:rules';
    $params['rules'] = $rules;
}
if ($communication !== '') {
    $where[] = 'w.communication_mode=:communication';
    $params['communication'] = $communication;
}
if ($selected !== '') {
    $where[] = 'w.plugin_selected=:selected';
    $params['selected'] = (int)$selected;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM web_flightplans w INNER JOIN users u ON u.id=w.user_id WHERE $whereSql"
);
foreach ($params as $key => $value) {
    $countStmt->bindValue(':' . $key, $value, $key === 'selected' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$listStmt = $pdo->prepare(
    "SELECT w.*,u.username,u.real_name
     FROM web_flightplans w
     INNER JOIN users u ON u.id=w.user_id
     WHERE $whereSql
     ORDER BY w.updated_at DESC,w.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . $key, $value, $key === 'selected' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$listStmt->execute();
$plans = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$pageQuery = $_GET;
unset($pageQuery['page']);
function afpPageUrl(int $page, array $query): string
{
    $query['page'] = $page;
    return 'admin_flightplans.php?' . http_build_query($query);
}
?>
<!doctype html>
<html lang="<?php echo afpH($_SESSION['language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin · <?php echo afpH(t('nav_flightplans')); ?></title>
    <style>
        body{margin:0;background:#07141f;color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1550px,calc(100% - 32px));margin:26px auto}.head,.filters,.pager{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.head{justify-content:space-between}.card{margin:18px 0;padding:18px;background:#0d1d2a;border:1px solid #285475;border-radius:8px}.filters{display:grid;grid-template-columns:minmax(260px,2fr) repeat(4,minmax(140px,1fr)) auto auto}.filters input,.filters select{width:100%;padding:10px;background:#071521;color:#fff;border:1px solid #285475;border-radius:4px}.button,button{display:inline-block;padding:10px 14px;border:0;border-radius:4px;background:#176dcc;color:#fff;text-decoration:none;cursor:pointer}.secondary{background:#17344b}.summary{color:#91b8d5}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1180px}th,td{padding:10px;border-bottom:1px solid #203b50;text-align:left;vertical-align:top}th{color:#9ec8e8}.route{max-width:430px;white-space:normal;overflow-wrap:anywhere}.badge{display:inline-block;padding:3px 7px;border:1px solid #37617e;border-radius:999px;color:#bfe2ff}.selected{color:#3ef1b4}.muted{color:#7898af}.details{margin-top:6px}.details summary{cursor:pointer;color:#7fc6ff}.details-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;padding:10px;background:#071521}.details-grid .wide{grid-column:1/-1;white-space:pre-wrap;overflow-wrap:anywhere}.pager{margin-top:15px}.pager a{padding:7px 10px;border:1px solid #285475;color:#9ed4ff;text-decoration:none}.pager .active{background:#176dcc;color:#fff}@media(max-width:1000px){.filters{grid-template-columns:1fr 1fr}}@media(max-width:620px){.filters{grid-template-columns:1fr}.details-grid{grid-template-columns:1fr}.details-grid .wide{grid-column:auto}}
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header.php'; ?>
<main class="shell">
    <div class="head">
        <div><h1>Admin · <?php echo afpH(t('nav_flightplans')); ?></h1><p class="summary">Alle von Benutzern erstellten Flugpläne · <?php echo $total; ?> Treffer</p></div>
        <a class="button secondary" href="admin.php">← <?php echo afpH(t('admin_title')); ?></a>
    </div>
    <section class="card">
        <form method="get" class="filters">
            <input name="q" value="<?php echo afpH($search); ?>" placeholder="Callsign, Benutzer, Flughafen, Route oder ID">
            <select name="status"><option value="">Alle Status</option><?php foreach (['draft','filed','archived'] as $value): ?><option value="<?php echo $value; ?>" <?php echo $status === $value ? 'selected' : ''; ?>><?php echo afpH(t('flightplan_status_' . $value)); ?></option><?php endforeach; ?></select>
            <select name="rules"><option value="">Alle Flugregeln</option><?php foreach (['I','V','Y','Z'] as $value): ?><option <?php echo $rules === $value ? 'selected' : ''; ?>><?php echo $value; ?></option><?php endforeach; ?></select>
            <select name="communication"><option value="">Alle Kommunikationsarten</option><?php foreach (['VOICE','RECEIVE_ONLY','TEXT_ONLY'] as $value): ?><option value="<?php echo $value; ?>" <?php echo $communication === $value ? 'selected' : ''; ?>><?php echo afpH(t('flightplan_communication_' . strtolower($value))); ?></option><?php endforeach; ?></select>
            <select name="selected"><option value="">Plugin-Auswahl: alle</option><option value="1" <?php echo $selected === '1' ? 'selected' : ''; ?>>Im Plugin ausgewählt</option><option value="0" <?php echo $selected === '0' ? 'selected' : ''; ?>>Nicht ausgewählt</option></select>
            <button>Suchen</button><a class="button secondary" href="admin_flightplans.php">Zurücksetzen</a>
        </form>
    </section>
    <section class="card scroll">
        <table>
            <thead><tr><th>ID / Aktualisiert</th><th>Benutzer</th><th>Callsign</th><th>Flug</th><th>Art</th><th>Status</th><th>Route und Details</th></tr></thead>
            <tbody>
            <?php if (!$plans): ?><tr><td colspan="7">Keine Flugpläne gefunden.</td></tr><?php endif; ?>
            <?php foreach ($plans as $plan): ?>
                <tr>
                    <td>#<?php echo (int)$plan['id']; ?><br><span class="muted"><?php echo afpH($plan['updated_at']); ?></span><br><span class="muted">Erstellt: <?php echo afpH($plan['created_at']); ?></span></td>
                    <td><a href="admin_user.php?id=<?php echo (int)$plan['user_id']; ?>" class="selected"><?php echo afpH($plan['username']); ?></a><br><span class="muted"><?php echo afpH($plan['real_name']); ?></span></td>
                    <td><strong><?php echo afpH($plan['callsign']); ?></strong><?php if ((int)$plan['plugin_selected'] === 1): ?><br><span class="selected">● Plugin</span><?php endif; ?></td>
                    <td><strong><?php echo afpH($plan['departure_airport']); ?> → <?php echo afpH($plan['arrival_airport']); ?></strong><br><span class="muted"><?php echo afpH($plan['departure_time']); ?> · <?php echo afpH($plan['cruising_level']); ?> · <?php echo afpH($plan['cruising_speed']); ?></span></td>
                    <td><span class="badge"><?php echo afpH($plan['flight_rules']); ?></span> <span class="badge"><?php echo afpH($plan['flight_type']); ?></span><br><span class="muted"><?php echo afpH($plan['communication_mode']); ?></span></td>
                    <td><?php echo afpH(t('flightplan_status_' . $plan['status'])); ?></td>
                    <td class="route"><?php echo afpH($plan['route_text'] ?: '–'); ?><details class="details"><summary>Alle Angaben</summary><div class="details-grid"><div>Alternate 1: <?php echo afpH($plan['alternate1_airport']); ?></div><div>Alternate 2: <?php echo afpH($plan['alternate2_airport']); ?></div><div class="wide"><strong>Route:</strong><br><?php echo afpH($plan['route_text'] ?: '–'); ?></div><div class="wide"><strong>Bemerkungen:</strong><br><?php echo afpH($plan['remarks'] ?: '–'); ?></div></div></details></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($pages > 1): ?><nav class="pager"><?php for ($index = 1; $index <= $pages; $index++): ?><a class="<?php echo $index === $page ? 'active' : ''; ?>" href="<?php echo afpH(afpPageUrl($index, $pageQuery)); ?>"><?php echo $index; ?></a><?php endfor; ?></nav><?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
