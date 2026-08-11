<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/bug_report_schema.php';

function bugH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bugLabel(string $prefix, string $value): string
{
    $key = $prefix . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower($value));
    $translated = t($key);
    return $translated === $key ? ucfirst(str_replace('_', ' ', $value)) : $translated;
}

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
ensureBugReportSchema($pdo);

$loggedIn = !empty($_SESSION['web_user_id']) && validateVfnWebSession($pdo);
$userId = $loggedIn ? (int)$_SESSION['web_user_id'] : 0;
$user = null;
if ($loggedIn) {
    $userStmt = $pdo->prepare('SELECT id, username, real_name, op_permission FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$opPermission = (int)($user['op_permission'] ?? 0);
$isStaff = $opPermission >= 1;
$message = (string)($_SESSION['bug_report_flash'] ?? '');
unset($_SESSION['bug_report_flash']);
$error = '';

$categories = ['bug', 'performance', 'voice', 'multiplayer', 'data', 'translation', 'security', 'suggestion', 'other'];
$areas = ['website', 'xplane_plugin', 'voice_service', 'atc_client', 'admin_panel', 'database', 'other'];
$severities = ['low', 'normal', 'high', 'critical'];
$reproducibilities = ['always', 'often', 'sometimes', 'once', 'unknown'];
$statuses = bugReportStatuses();
$showNewForm = !empty($_GET['new']);

function loadBugReport(PDO $pdo, int $ticketId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT r.*, reporter.username reporter_username, reporter.real_name reporter_real_name,
                claimant.username claimant_username, claimant.real_name claimant_real_name
         FROM bug_reports r
         INNER JOIN users reporter ON reporter.id = r.reporter_user_id
         LEFT JOIN users claimant ON claimant.id = r.claimed_by_user_id
         WHERE r.id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $ticketId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function canManageBugReport(PDO $pdo, array $ticket, int $userId, int $opPermission): bool
{
    if ($opPermission < 1) {
        return false;
    }
    if ($opPermission >= 4 || (int)$ticket['claimed_by_user_id'] === $userId) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM bug_report_staff WHERE bug_report_id = :ticket AND user_id = :user LIMIT 1');
    $stmt->execute(['ticket' => (int)$ticket['id'], 'user' => $userId]);
    return (bool)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$loggedIn) {
        $error = t('bug_login_required');
    } elseif (!csrfIsValid($_POST['csrf'] ?? null, 'bug_reports')) {
        $error = t('csrf_invalid');
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $category = (string)($_POST['category'] ?? 'other');
                $area = (string)($_POST['affected_area'] ?? 'other');
                $severity = (string)($_POST['severity'] ?? 'normal');
                $reproducibility = (string)($_POST['reproducibility'] ?? 'sometimes');
                if (mb_strlen($title) < 5 || mb_strlen($title) > 160 || mb_strlen($description) < 20 || mb_strlen($description) > 10000) {
                    throw new RuntimeException('invalid_report');
                }
                if (!in_array($category, $categories, true)) { $category = 'other'; }
                if (!in_array($area, $areas, true)) { $area = 'other'; }
                if (!in_array($severity, $severities, true)) { $severity = 'normal'; }
                if (!in_array($reproducibility, $reproducibilities, true)) { $reproducibility = 'sometimes'; }
                $referenceUrl = trim((string)($_POST['reference_url'] ?? ''));
                if ($referenceUrl !== '' && filter_var($referenceUrl, FILTER_VALIDATE_URL) === false) {
                    throw new RuntimeException('invalid_url');
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO bug_reports
                     (reporter_user_id,title,category,affected_area,severity,environment,client_version,reproducibility,description,steps_to_reproduce,expected_result,actual_result,reference_url)
                     VALUES (:user,:title,:category,:area,:severity,:environment,:version,:reproducibility,:description,:steps,:expected,:actual,:url)"
                );
                $stmt->execute([
                    'user' => $userId,
                    'title' => $title,
                    'category' => $category,
                    'area' => $area,
                    'severity' => $severity,
                    'environment' => mb_substr(trim((string)($_POST['environment'] ?? '')), 0, 255),
                    'version' => mb_substr(trim((string)($_POST['client_version'] ?? '')), 0, 100),
                    'reproducibility' => $reproducibility,
                    'description' => $description,
                    'steps' => mb_substr(trim((string)($_POST['steps_to_reproduce'] ?? '')), 0, 10000),
                    'expected' => mb_substr(trim((string)($_POST['expected_result'] ?? '')), 0, 10000),
                    'actual' => mb_substr(trim((string)($_POST['actual_result'] ?? '')), 0, 10000),
                    'url' => mb_substr($referenceUrl, 0, 500),
                ]);
                $ticketId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO bug_report_events (bug_report_id,actor_user_id,event_type,new_value) VALUES (:ticket,:actor,'created','new')")
                    ->execute(['ticket' => $ticketId, 'actor' => $userId]);
                header('Location: bug_reports.php?id=' . $ticketId . '&created=1');
                exit;
            }

            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $ticket = loadBugReport($pdo, $ticketId);
            if (!$ticket) { throw new RuntimeException('not_found'); }
            $isReporter = (int)$ticket['reporter_user_id'] === $userId;
            $canManage = canManageBugReport($pdo, $ticket, $userId, $opPermission);

            if ($action === 'claim' && $isStaff) {
                $stmt = $pdo->prepare('UPDATE bug_reports SET claimed_by_user_id = :user, status = IF(status = \'new\', \'in_progress\', status) WHERE id = :id AND claimed_by_user_id IS NULL');
                $stmt->execute(['user' => $userId, 'id' => $ticketId]);
                if ($stmt->rowCount() < 1) { throw new RuntimeException('already_claimed'); }
                $pdo->prepare("INSERT INTO bug_report_events (bug_report_id,actor_user_id,event_type,new_value) VALUES (:ticket,:actor,'claimed',:value)")
                    ->execute(['ticket' => $ticketId, 'actor' => $userId, 'value' => (string)$userId]);
                $message = t('bug_claimed_success');
            } elseif ($action === 'release' && $isStaff && ((int)$ticket['claimed_by_user_id'] === $userId || $opPermission >= 4)) {
                $pdo->prepare('UPDATE bug_reports SET claimed_by_user_id = NULL WHERE id = :id')->execute(['id' => $ticketId]);
                $pdo->prepare("INSERT INTO bug_report_events (bug_report_id,actor_user_id,event_type,old_value) VALUES (:ticket,:actor,'released',:value)")
                    ->execute(['ticket' => $ticketId, 'actor' => $userId, 'value' => (string)$ticket['claimed_by_user_id']]);
                $message = t('bug_released_success');
            } elseif ($action === 'status' && $canManage) {
                $newStatus = (string)($_POST['status'] ?? '');
                if (!in_array($newStatus, $statuses, true)) { throw new RuntimeException('invalid_status'); }
                $pdo->prepare('UPDATE bug_reports SET status = :status, closed_at = :closed WHERE id = :id')->execute([
                    'status' => $newStatus,
                    'closed' => bugReportCanClose($newStatus) ? date('Y-m-d H:i:s') : null,
                    'id' => $ticketId,
                ]);
                $pdo->prepare("INSERT INTO bug_report_events (bug_report_id,actor_user_id,event_type,old_value,new_value) VALUES (:ticket,:actor,'status',:old,:new)")
                    ->execute(['ticket' => $ticketId, 'actor' => $userId, 'old' => $ticket['status'], 'new' => $newStatus]);
                $pdo->prepare("INSERT INTO user_activity_log (user_id,actor_user_id,activity_type,activity_key,activity_value) VALUES (:target,:actor,'bug_report','activity_bug_report_status',:value)")
                    ->execute(['target' => (int)$ticket['reporter_user_id'], 'actor' => $userId, 'value' => '#' . $ticketId . ' · ' . $newStatus]);
                $message = t('bug_status_success');
            } elseif ($action === 'add_staff' && $canManage) {
                $staffId = (int)($_POST['staff_user_id'] ?? 0);
                $check = $pdo->prepare('SELECT id FROM users WHERE id = :id AND op_permission >= 1 LIMIT 1');
                $check->execute(['id' => $staffId]);
                if (!$check->fetchColumn()) { throw new RuntimeException('invalid_staff'); }
                $pdo->prepare('INSERT IGNORE INTO bug_report_staff (bug_report_id,user_id,added_by_user_id) VALUES (:ticket,:staff,:actor)')
                    ->execute(['ticket' => $ticketId, 'staff' => $staffId, 'actor' => $userId]);
                $pdo->prepare("INSERT INTO bug_report_events (bug_report_id,actor_user_id,event_type,new_value) VALUES (:ticket,:actor,'staff_added',:value)")
                    ->execute(['ticket' => $ticketId, 'actor' => $userId, 'value' => (string)$staffId]);
                $message = t('bug_staff_added');
            } elseif ($action === 'remove_staff' && $canManage) {
                $staffId = (int)($_POST['staff_user_id'] ?? 0);
                $pdo->prepare('DELETE FROM bug_report_staff WHERE bug_report_id = :ticket AND user_id = :staff')
                    ->execute(['ticket' => $ticketId, 'staff' => $staffId]);
                $message = t('bug_staff_removed');
            } elseif ($action === 'reply' && ($isReporter || $isStaff)) {
                $reply = trim((string)($_POST['message'] ?? ''));
                if (mb_strlen($reply) < 2 || mb_strlen($reply) > 10000) { throw new RuntimeException('invalid_reply'); }
                $isInternal = $isStaff && !empty($_POST['is_internal']) ? 1 : 0;
                $pdo->prepare('INSERT INTO bug_report_posts (bug_report_id,author_user_id,message,is_internal) VALUES (:ticket,:author,:message,:internal)')
                    ->execute(['ticket' => $ticketId, 'author' => $userId, 'message' => $reply, 'internal' => $isInternal]);
                $pdo->prepare('UPDATE bug_reports SET updated_at = NOW() WHERE id = :id')->execute(['id' => $ticketId]);
                if ($isStaff && !$isInternal && !$isReporter) {
                    $pdo->prepare("INSERT INTO user_activity_log (user_id,actor_user_id,activity_type,activity_key,activity_value) VALUES (:target,:actor,'bug_report','activity_bug_report_reply',:value)")
                        ->execute(['target' => (int)$ticket['reporter_user_id'], 'actor' => $userId, 'value' => '#' . $ticketId . ' · ' . mb_substr($ticket['title'], 0, 180)]);
                }
                $message = t('bug_reply_success');
            } else {
                throw new RuntimeException('permission_denied');
            }

            if ($message !== '') {
                $_SESSION['bug_report_flash'] = $message;
                header('Location: bug_reports.php?id=' . $ticketId);
                exit;
            }
        } catch (Throwable $exception) {
            if ((string)($_POST['action'] ?? '') === 'create') {
                $showNewForm = true;
            }
            $errorKey = 'bug_error_' . preg_replace('/[^a-z0-9_]/', '', strtolower($exception->getMessage()));
            $translated = t($errorKey);
            $error = $translated === $errorKey ? t('bug_action_failed') : $translated;
        }
    }
}

$ticketId = (int)($_GET['id'] ?? $_POST['ticket_id'] ?? 0);
$ticket = $ticketId > 0 ? loadBugReport($pdo, $ticketId) : null;
$canViewTicket = $ticket && $loggedIn && ($isStaff || (int)$ticket['reporter_user_id'] === $userId);
if ($ticket && !$canViewTicket) {
    http_response_code(403);
    $ticket = null;
    $error = t('bug_permission_denied');
}

$assignedStaff = [];
$posts = [];
$events = [];
$canManage = false;
if ($ticket) {
    $canManage = canManageBugReport($pdo, $ticket, $userId, $opPermission);
    $staffStmt = $pdo->prepare('SELECT u.id,u.username,u.real_name FROM bug_report_staff s INNER JOIN users u ON u.id=s.user_id WHERE s.bug_report_id=:id ORDER BY u.real_name,u.username');
    $staffStmt->execute(['id' => $ticketId]);
    $assignedStaff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
    $postsSql = "SELECT p.*,u.username,u.real_name,u.op_permission FROM bug_report_posts p INNER JOIN users u ON u.id=p.author_user_id WHERE p.bug_report_id=:id";
    if (!$isStaff) { $postsSql .= ' AND p.is_internal=0'; }
    $postsSql .= ' ORDER BY p.created_at,p.id';
    $postsStmt = $pdo->prepare($postsSql);
    $postsStmt->execute(['id' => $ticketId]);
    $posts = $postsStmt->fetchAll(PDO::FETCH_ASSOC);
    $eventStmt = $pdo->prepare('SELECT e.*,u.username,u.real_name FROM bug_report_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.bug_report_id=:id ORDER BY e.created_at,e.id');
    $eventStmt->execute(['id' => $ticketId]);
    $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
}

$staffUsers = [];
if ($isStaff) {
    $staffUsers = $pdo->query('SELECT id,username,real_name,op_permission FROM users WHERE op_permission >= 1 ORDER BY op_permission DESC,real_name,username')->fetchAll(PDO::FETCH_ASSOC);
}

$filterStatus = (string)($_GET['status'] ?? 'active');
$search = trim((string)($_GET['q'] ?? ''));
$params = $isStaff ? [] : ['user' => $userId];
$where = $isStaff ? '1=1' : 'r.reporter_user_id = :user';
if ($filterStatus === 'active') {
    $where .= " AND r.status NOT IN ('closed','rejected')";
} elseif (in_array($filterStatus, $statuses, true)) {
    $where .= ' AND r.status = :status';
    $params['status'] = $filterStatus;
}
if ($search !== '') {
    $where .= ' AND (r.title LIKE :search_title OR reporter.username LIKE :search_username OR reporter.real_name LIKE :search_real_name OR CAST(r.id AS CHAR) = :exact)';
    $searchLike = '%' . mb_substr($search, 0, 100) . '%';
    $params['search_title'] = $searchLike;
    $params['search_username'] = $searchLike;
    $params['search_real_name'] = $searchLike;
    $params['exact'] = ltrim($search, '#');
}
$listStmt = $pdo->prepare(
    "SELECT r.*,reporter.username reporter_username,reporter.real_name reporter_real_name,claimant.username claimant_username,claimant.real_name claimant_real_name
     FROM bug_reports r INNER JOIN users reporter ON reporter.id=r.reporter_user_id LEFT JOIN users claimant ON claimant.id=r.claimed_by_user_id
     WHERE $where ORDER BY FIELD(r.severity,'critical','high','normal','low'),r.updated_at DESC LIMIT 200"
);
$listStmt->execute($params);
$tickets = $listStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="<?php echo bugH($_SESSION['language'] ?? 'en'); ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo bugH(t('bug_reports_title')); ?> - <?php echo bugH($projectName); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:linear-gradient(135deg,#07101d,#071822 48%,#041016);color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1500px,calc(100% - 32px));margin:30px auto 50px}.hero{display:flex;justify-content:space-between;align-items:end;gap:18px}.hero h1{margin:0;color:#fff}.muted{color:#9eb9d7;line-height:1.5}.notice{padding:12px 15px;margin:15px 0;border:1px solid #287151;background:#102d27;color:#8bf1bf;border-radius:7px}.notice.error{border-color:#8a3945;background:#32161c;color:#ffadb6}.grid{display:grid;grid-template-columns:380px 1fr;gap:18px;margin-top:20px}.card{border:1px solid #285475;border-radius:9px;background:#0b1824;overflow:hidden}.pad{padding:18px}.ticket-list{max-height:72vh;overflow:auto}.ticket{display:block;margin:10px;padding:13px;border:1px solid #294b66;border-left:7px solid #5790bd;border-radius:7px;background:#0c1d2a;color:#d7e8ff;text-decoration:none}.ticket:hover{background:#11283a}.ticket.status-new{border-left-color:#5ac8fa}.ticket.status-open{border-left-color:#3da5ff}.ticket.status-in_progress{border-left-color:#ffb020}.ticket.status-waiting_user{border-left-color:#b983ff}.ticket.status-testing{border-left-color:#00d4c7}.ticket.status-resolved{border-left-color:#38d582}.ticket.status-closed{border-left-color:#65798a}.ticket.status-rejected{border-left-color:#ef5a67}.ticket-title{font-weight:700;color:#fff}.meta{font-size:12px;color:#8fb2ce;margin-top:7px}.status-header{padding:18px 20px;border-bottom:1px solid #33566f;background:#12324b}.status-header.status-new{background:#124c67}.status-header.status-open{background:#124a78}.status-header.status-in_progress{background:#6b470b}.status-header.status-waiting_user{background:#4d2d73}.status-header.status-testing{background:#075a59}.status-header.status-resolved{background:#135f42}.status-header.status-closed{background:#354753}.status-header.status-rejected{background:#672630}.status-header h2{margin:0 0 6px;color:#fff}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.full{grid-column:1/-1}label{display:block;margin-bottom:6px;color:#a8c7df;font-size:13px}input,select,textarea{width:100%;padding:11px;border:1px solid #315a78;border-radius:5px;background:#071521;color:#fff}textarea{min-height:120px;resize:vertical}button,.button{display:inline-block;padding:10px 14px;border:1px solid #3183c5;border-radius:5px;background:#176dcc;color:#fff;text-decoration:none;cursor:pointer}button.secondary,.button.secondary{background:#173047;border-color:#365f7d}button.danger{background:#7a2834;border-color:#b84e5c}.actions{display:flex;flex-wrap:wrap;gap:9px;align-items:end}.detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.detail{padding:10px;background:#081621;border:1px solid #203e54;border-radius:6px}.detail strong{display:block;color:#86b9dd;font-size:12px;margin-bottom:4px}.description{white-space:pre-wrap;line-height:1.55}.post{margin:12px 0;padding:14px;border:1px solid #294b66;border-radius:7px;background:#091a27}.post.staff{border-left:5px solid #1d8be0}.post.internal{border-left:5px solid #f0a32a;background:#29200f}.post-head{display:flex;justify-content:space-between;color:#8eb2cd;font-size:12px;margin-bottom:9px}.post-body{white-space:pre-wrap;line-height:1.5}.staff-chip{display:inline-flex;gap:7px;align-items:center;margin:4px;padding:7px 10px;border:1px solid #315a78;border-radius:18px;background:#10283a}.filters{display:flex;gap:8px}.filters input{min-width:0}.language-hint{border-left:4px solid #f0a32a;padding:10px 12px;background:#2c220c;color:#ffd783;margin:14px 0}.login-card{text-align:center;padding:50px 20px}.event{font-size:12px;color:#819eb5;padding:5px 0;border-bottom:1px dotted #294154}@media(max-width:1000px){.grid{grid-template-columns:1fr}.ticket-list{max-height:none}.detail-grid{grid-template-columns:1fr 1fr}}@media(max-width:650px){.form-grid,.detail-grid{grid-template-columns:1fr}.full{grid-column:auto}.hero{display:block}.filters{display:grid}}
</style>
</head><body>
<?php require __DIR__ . '/includes/header.php'; ?>
<main class="shell">
<div class="hero"><div><h1><?php echo bugH(t('bug_reports_title')); ?></h1><p class="muted"><?php echo bugH(t('bug_reports_intro')); ?></p></div><?php if ($loggedIn): ?><a class="button" href="bug_reports.php?new=1"><?php echo bugH(t('bug_new_report')); ?></a><?php endif; ?></div>
<?php if (!empty($_GET['created'])): ?><div class="notice"><?php echo bugH(t('bug_created_success')); ?></div><?php endif; ?>
<?php if ($message !== ''): ?><div class="notice"><?php echo bugH($message); ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="notice error"><?php echo bugH($error); ?></div><?php endif; ?>
<?php if (!$loggedIn): ?><section class="card login-card"><h2><?php echo bugH(t('bug_login_required')); ?></h2><p class="muted"><?php echo bugH(t('bug_login_explanation')); ?></p><button type="button" onclick="openModal('loginModal')"><?php echo bugH(t('nav_login')); ?></button></section>
<?php elseif ($showNewForm): ?>
<section class="card pad"><h2><?php echo bugH(t('bug_new_report')); ?></h2><div class="language-hint"><?php echo bugH(t('bug_languages_hint')); ?></div>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?php echo bugH(csrfToken('bug_reports')); ?>"><input type="hidden" name="action" value="create">
<div class="full"><label><?php echo bugH(t('bug_title')); ?></label><input name="title" maxlength="160" value="<?php echo bugH($_POST['title'] ?? ''); ?>" required></div>
<div><label><?php echo bugH(t('bug_category')); ?></label><select name="category"><?php foreach($categories as $value): ?><option value="<?php echo bugH($value); ?>" <?php echo (string)($_POST['category'] ?? 'bug') === $value ? 'selected' : ''; ?>><?php echo bugH(bugLabel('bug_category',$value)); ?></option><?php endforeach; ?></select></div>
<div><label><?php echo bugH(t('bug_affected_area')); ?></label><select name="affected_area"><?php foreach($areas as $value): ?><option value="<?php echo bugH($value); ?>" <?php echo (string)($_POST['affected_area'] ?? 'website') === $value ? 'selected' : ''; ?>><?php echo bugH(bugLabel('bug_area',$value)); ?></option><?php endforeach; ?></select></div>
<div><label><?php echo bugH(t('bug_severity')); ?></label><select name="severity"><?php foreach($severities as $value): ?><option value="<?php echo bugH($value); ?>" <?php echo (string)($_POST['severity'] ?? 'normal') === $value ? 'selected' : ''; ?>><?php echo bugH(bugLabel('bug_severity',$value)); ?></option><?php endforeach; ?></select></div>
<div><label><?php echo bugH(t('bug_reproducibility')); ?></label><select name="reproducibility"><?php foreach($reproducibilities as $value): ?><option value="<?php echo bugH($value); ?>" <?php echo (string)($_POST['reproducibility'] ?? 'sometimes') === $value ? 'selected' : ''; ?>><?php echo bugH(bugLabel('bug_repro',$value)); ?></option><?php endforeach; ?></select></div>
<div><label><?php echo bugH(t('bug_environment')); ?></label><input name="environment" maxlength="255" value="<?php echo bugH($_POST['environment'] ?? ''); ?>" placeholder="Windows 11, Firefox, X-Plane 12 …"></div>
<div><label><?php echo bugH(t('bug_version')); ?></label><input name="client_version" maxlength="100" value="<?php echo bugH($_POST['client_version'] ?? ''); ?>" placeholder="Plugin / Browser / X-Plane"></div>
<div class="full"><label><?php echo bugH(t('bug_description')); ?></label><textarea name="description" minlength="20" maxlength="10000" required><?php echo bugH($_POST['description'] ?? ''); ?></textarea></div>
<div class="full"><label><?php echo bugH(t('bug_steps')); ?></label><textarea name="steps_to_reproduce" maxlength="10000"><?php echo bugH($_POST['steps_to_reproduce'] ?? ''); ?></textarea></div>
<div><label><?php echo bugH(t('bug_expected')); ?></label><textarea name="expected_result" maxlength="10000"><?php echo bugH($_POST['expected_result'] ?? ''); ?></textarea></div><div><label><?php echo bugH(t('bug_actual')); ?></label><textarea name="actual_result" maxlength="10000"><?php echo bugH($_POST['actual_result'] ?? ''); ?></textarea></div>
<div class="full"><label><?php echo bugH(t('bug_reference_url')); ?></label><input type="url" name="reference_url" maxlength="500" value="<?php echo bugH($_POST['reference_url'] ?? ''); ?>" placeholder="https://…"></div>
<div class="full actions"><button type="submit"><?php echo bugH(t('bug_submit')); ?></button><a class="button secondary" href="bug_reports.php"><?php echo bugH(t('bug_cancel')); ?></a></div></form></section>
<?php else: ?>
<div class="grid"><aside class="card"><div class="pad"><h2><?php echo bugH($isStaff ? t('bug_staff_queue') : t('bug_my_reports')); ?></h2><form class="filters"><input name="q" value="<?php echo bugH($search); ?>" placeholder="<?php echo bugH(t('bug_search')); ?>"><select name="status"><option value="active"><?php echo bugH(t('bug_filter_active')); ?></option><?php foreach($statuses as $value): ?><option value="<?php echo bugH($value); ?>" <?php echo $filterStatus===$value?'selected':''; ?>><?php echo bugH(bugLabel('bug_status',$value)); ?></option><?php endforeach; ?><option value="all" <?php echo $filterStatus==='all'?'selected':''; ?>><?php echo bugH(t('bug_filter_all')); ?></option></select><button>OK</button></form></div><div class="ticket-list"><?php if(!$tickets): ?><p class="pad muted"><?php echo bugH(t('bug_no_reports')); ?></p><?php endif; ?><?php foreach($tickets as $item): ?><a class="ticket status-<?php echo bugH($item['status']); ?>" href="bug_reports.php?id=<?php echo (int)$item['id']; ?>"><div class="ticket-title">#<?php echo (int)$item['id']; ?> · <?php echo bugH($item['title']); ?></div><div class="meta"><?php echo bugH(bugLabel('bug_status',$item['status'])); ?> · <?php echo bugH(bugLabel('bug_severity',$item['severity'])); ?> · <?php echo bugH($item['reporter_real_name'] ?: $item['reporter_username']); ?></div><div class="meta"><?php echo bugH($item['claimed_by_user_id'] ? t('bug_claimed_by').' '.($item['claimant_real_name']?:$item['claimant_username']) : t('bug_unclaimed')); ?> · <?php echo bugH($item['updated_at']); ?></div></a><?php endforeach; ?></div></aside>
<section><?php if(!$ticket): ?><div class="card pad"><h2><?php echo bugH(t('bug_select_report')); ?></h2><p class="muted"><?php echo bugH(t('bug_select_report_text')); ?></p></div><?php else: ?>
<article class="card"><header class="status-header status-<?php echo bugH($ticket['status']); ?>"><h2>#<?php echo (int)$ticket['id']; ?> · <?php echo bugH($ticket['title']); ?></h2><div><?php echo bugH(bugLabel('bug_status',$ticket['status'])); ?> · <?php echo bugH($ticket['reporter_real_name'] ?: $ticket['reporter_username']); ?> · <?php echo bugH($ticket['created_at']); ?></div></header><div class="pad">
<div class="detail-grid"><div class="detail"><strong><?php echo bugH(t('bug_category')); ?></strong><?php echo bugH(bugLabel('bug_category',$ticket['category'])); ?></div><div class="detail"><strong><?php echo bugH(t('bug_affected_area')); ?></strong><?php echo bugH(bugLabel('bug_area',$ticket['affected_area'])); ?></div><div class="detail"><strong><?php echo bugH(t('bug_severity')); ?></strong><?php echo bugH(bugLabel('bug_severity',$ticket['severity'])); ?></div><div class="detail"><strong><?php echo bugH(t('bug_reproducibility')); ?></strong><?php echo bugH(bugLabel('bug_repro',$ticket['reproducibility'])); ?></div><div class="detail"><strong><?php echo bugH(t('bug_environment')); ?></strong><?php echo bugH($ticket['environment'] ?: '–'); ?></div><div class="detail"><strong><?php echo bugH(t('bug_version')); ?></strong><?php echo bugH($ticket['client_version'] ?: '–'); ?></div><div class="detail"><strong><?php echo bugH(t('bug_claimed_by')); ?></strong><?php echo bugH($ticket['claimed_by_user_id'] ? ($ticket['claimant_real_name']?:$ticket['claimant_username']) : t('bug_unclaimed')); ?></div><div class="detail"><strong><?php echo bugH(t('bug_last_update')); ?></strong><?php echo bugH($ticket['updated_at']); ?></div></div>
<h3><?php echo bugH(t('bug_description')); ?></h3><div class="description"><?php echo bugH($ticket['description']); ?></div><?php foreach(['steps_to_reproduce'=>'bug_steps','expected_result'=>'bug_expected','actual_result'=>'bug_actual'] as $field=>$key): ?><?php if(trim((string)$ticket[$field])!==''): ?><h3><?php echo bugH(t($key)); ?></h3><div class="description"><?php echo bugH($ticket[$field]); ?></div><?php endif; ?><?php endforeach; ?><?php if($ticket['reference_url']!==''): ?><p><a class="button secondary" target="_blank" rel="noopener" href="<?php echo bugH($ticket['reference_url']); ?>"><?php echo bugH(t('bug_open_reference')); ?></a></p><?php endif; ?>
<?php if($isStaff): ?><hr><h3><?php echo bugH(t('bug_staff_management')); ?></h3><div class="actions"><?php if(!$ticket['claimed_by_user_id']): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo bugH(csrfToken('bug_reports')); ?>"><input type="hidden" name="action" value="claim"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticketId; ?>"><button><?php echo bugH(t('bug_claim')); ?></button></form><?php elseif((int)$ticket['claimed_by_user_id']===$userId||$opPermission>=4): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo bugH(csrfToken('bug_reports')); ?>"><input type="hidden" name="action" value="release"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticketId; ?>"><button class="secondary"><?php echo bugH(t('bug_release')); ?></button></form><?php endif; ?><?php if($canManage): ?><form method="post" class="actions"><input type="hidden" name="csrf" value="<?php echo bugH(csrfToken('bug_reports')); ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticketId; ?>"><div><label><?php echo bugH(t('bug_change_status')); ?></label><select name="status"><?php foreach($statuses as $value): ?><option value="<?php echo bugH($value); ?>" <?php echo $ticket['status']===$value?'selected':''; ?>><?php echo bugH(bugLabel('bug_status',$value)); ?></option><?php endforeach; ?></select></div><button><?php echo bugH(t('bug_save')); ?></button></form><?php endif; ?></div>
<div><?php foreach($assignedStaff as $member): ?><span class="staff-chip"><?php echo bugH($member['real_name']?:$member['username']); ?><?php if($canManage): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?php echo bugH(csrfToken('bug_reports')); ?>"><input type="hidden" name="action" value="remove_staff"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticketId; ?>"><input type="hidden" name="staff_user_id" value="<?php echo (int)$member['id']; ?>"><button class="danger" style="padding:2px 6px">×</button></form><?php endif; ?></span><?php endforeach; ?></div><?php if($canManage): ?><form method="post" class="actions"><input type="hidden" name="csrf" value="<?php echo bugH(csrfToken('bug_reports')); ?>"><input type="hidden" name="action" value="add_staff"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticketId; ?>"><select name="staff_user_id" style="width:auto"><?php foreach($staffUsers as $member): ?><option value="<?php echo (int)$member['id']; ?>">OP<?php echo (int)$member['op_permission']; ?> · <?php echo bugH($member['real_name']?:$member['username']); ?></option><?php endforeach; ?></select><button><?php echo bugH(t('bug_add_staff')); ?></button></form><?php endif; ?><?php endif; ?>
<hr><h3><?php echo bugH(t('bug_discussion')); ?></h3><?php foreach($posts as $post): ?><div class="post <?php echo (int)$post['op_permission']>=1?'staff ':''; ?><?php echo !empty($post['is_internal'])?'internal':''; ?>"><div class="post-head"><strong><?php echo bugH($post['real_name']?:$post['username']); ?><?php echo (int)$post['op_permission']>=1?' · Staff':''; ?></strong><span><?php echo bugH($post['created_at']); ?></span></div><?php if(!empty($post['is_internal'])): ?><div class="meta"><?php echo bugH(t('bug_internal_note')); ?></div><?php endif; ?><div class="post-body"><?php echo bugH($post['message']); ?></div></div><?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf" value="<?php echo bugH(csrfToken('bug_reports')); ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticketId; ?>"><label><?php echo bugH(t('bug_reply')); ?></label><textarea name="message" maxlength="10000" required></textarea><?php if($isStaff): ?><label><input type="checkbox" name="is_internal" value="1" style="width:auto"> <?php echo bugH(t('bug_internal_note')); ?></label><?php endif; ?><button><?php echo bugH(t('bug_send_reply')); ?></button></form>
<?php if($isStaff&&$events): ?><details style="margin-top:18px"><summary><?php echo bugH(t('bug_history')); ?></summary><?php foreach($events as $event): ?><div class="event"><?php echo bugH($event['created_at'].' · '.($event['real_name']?:$event['username']?:'SYSTEM').' · '.$event['event_type'].' · '.$event['old_value'].' → '.$event['new_value']); ?></div><?php endforeach; ?></details><?php endif; ?></div></article><?php endif; ?></section></div>
<?php endif; ?>
</main><?php require __DIR__ . '/includes/footer.php'; ?><?php if(!$loggedIn){ require __DIR__ . '/includes/auth_modals.php'; } ?></body></html>
