<?php
require_once __DIR__ . '/execute/admin_auth.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/web_features_schema.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$pdo = createAdminPdo();
$adminUser = requireAdminUser($pdo, 4);
ensureWebFeatureSchema($pdo);

$targetId = max(1, (int)($_GET['id'] ?? $_POST['user_id'] ?? 0));
$message = '';
$error = '';
$csrf = csrfToken('admin_user');
$countries = require __DIR__ . '/includes/countries.php';
$divisions = $pdo->query("SELECT code, name FROM divisions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$loadUser = static function () use ($pdo, $targetId): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, username, email, real_name, country_code, division_code,
                rating_pilot, rating_atc, rating_special, op_permission,
                email_verified, is_active, created_at, last_login
         FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$target = $loadUser();
if (!$target) {
    http_response_code(404);
    exit('User not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfIsValid($_POST['csrf'] ?? null, 'admin_user')) {
        $error = t('csrf_invalid');
    } elseif ((int)$target['op_permission'] >= (int)$adminUser['op_permission']
        && (int)$target['id'] !== (int)$adminUser['id']) {
        $error = t('admin_user_rank_denied');
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'note') {
                $note = trim((string)($_POST['note'] ?? ''));
                if ($note === '') {
                    throw new RuntimeException('note_required');
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO staff_user_notes (user_id, author_user_id, note_text)
                     VALUES (:user_id, :author_id, :note_text)"
                );
                $stmt->execute([
                    'user_id' => $targetId,
                    'author_id' => (int)$adminUser['id'],
                    'note_text' => mb_substr($note, 0, 4000)
                ]);
                logActivity($pdo, $targetId, 'admin', 'activity_staff_note_added', '', (int)$adminUser['id']);
                $message = t('admin_user_note_saved');
            } elseif ($action === 'update') {
                $username = trim((string)($_POST['username'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $realName = trim((string)($_POST['real_name'] ?? ''));
                $country = strtoupper(trim((string)($_POST['country_code'] ?? '')));
                $division = strtoupper(trim((string)($_POST['division_code'] ?? '')));
                $pilot = min(9, max(0, (int)($_POST['rating_pilot'] ?? 0)));
                $atc = min(9, max(0, (int)($_POST['rating_atc'] ?? 0)));
                $special = min(5, max(0, (int)($_POST['rating_special'] ?? 0)));
                $requestedOp = min(5, max(0, (int)($_POST['op_permission'] ?? 0)));
                $active = !empty($_POST['is_active']) ? 1 : 0;

                if (mb_strlen($username) < 3 || mb_strlen($username) > 50
                    || !filter_var($email, FILTER_VALIDATE_EMAIL)
                    || mb_strlen($realName) < 2 || !isset($countries[$country])) {
                    throw new RuntimeException('invalid_fields');
                }
                $validDivision = false;
                foreach ($divisions as $item) {
                    if (strtoupper((string)$item['code']) === $division) {
                        $validDivision = true;
                        break;
                    }
                }
                if (!$validDivision) {
                    throw new RuntimeException('invalid_division');
                }
                if ((int)$adminUser['op_permission'] < 5) {
                    $requestedOp = (int)$target['op_permission'];
                    $special = (int)$target['rating_special'];
                }
                if ((int)$target['id'] === (int)$adminUser['id']) {
                    $requestedOp = (int)$target['op_permission'];
                    $active = 1;
                }
                if ($requestedOp >= (int)$adminUser['op_permission']
                    && $requestedOp !== (int)$target['op_permission']) {
                    throw new RuntimeException('rank_denied');
                }

                $changes = [];
                $newValues = [
                    'username' => $username, 'email' => $email, 'real_name' => $realName,
                    'country_code' => $country, 'division_code' => $division,
                    'rating_pilot' => $pilot, 'rating_atc' => $atc,
                    'rating_special' => $special, 'op_permission' => $requestedOp,
                    'is_active' => $active
                ];
                foreach ($newValues as $key => $value) {
                    if ((string)$target[$key] !== (string)$value) {
                        $changes[] = $key . ': ' . (string)$target[$key] . ' -> ' . (string)$value;
                    }
                }
                $stmt = $pdo->prepare(
                    "UPDATE users SET username=:username, email=:email, real_name=:real_name,
                     country_code=:country_code, division_code=:division_code,
                     rating_pilot=:rating_pilot, rating_atc=:rating_atc,
                     rating_special=:rating_special, op_permission=:op_permission,
                     is_active=:is_active WHERE id=:id"
                );
                $stmt->execute($newValues + ['id' => $targetId]);
                if (!$active) {
                    $stmt = $pdo->prepare(
                        "UPDATE user_sessions SET is_active=0, last_seen=NOW()
                         WHERE user_id=:user_id AND is_active=1"
                    );
                    $stmt->execute(['user_id' => $targetId]);
                }
                if ($changes) {
                    logActivity(
                        $pdo, $targetId, 'admin', 'activity_admin_user_updated',
                        implode('; ', $changes), (int)$adminUser['id']
                    );
                }
                $message = t('admin_user_saved');
                $target = $loadUser();
            }
        } catch (Throwable $e) {
            $error = t('admin_user_save_failed') . ' (' . $e->getMessage() . ')';
        }
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_activity_log WHERE user_id=:id");
$countStmt->execute(['id' => $targetId]);
$historyTotal = (int)$countStmt->fetchColumn();
$historyStmt = $pdo->prepare(
    "SELECT l.*, a.username AS actor_username
     FROM user_activity_log l LEFT JOIN users a ON a.id=l.actor_user_id
     WHERE l.user_id=:id ORDER BY l.created_at DESC LIMIT :limit OFFSET :offset"
);
$historyStmt->bindValue(':id', $targetId, PDO::PARAM_INT);
$historyStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$historyStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$historyStmt->execute();
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
$noteStmt = $pdo->prepare(
    "SELECT n.*, a.username AS author FROM staff_user_notes n
     LEFT JOIN users a ON a.id=n.author_user_id
     WHERE n.user_id=:id ORDER BY n.created_at DESC LIMIT 100"
);
$noteStmt->execute(['id' => $targetId]);
$notes = $noteStmt->fetchAll(PDO::FETCH_ASSOC);
$pages = max(1, (int)ceil($historyTotal / $perPage));
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($_SESSION['language'] ?? 'en'); ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h(t('admin_user_management')); ?></title>
<style>
body{margin:0;background:#07141f;color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1200px,calc(100% - 36px));margin:28px auto}.card{background:#0d1d2a;border:1px solid #285475;border-radius:8px;padding:22px;margin:18px 0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}label{display:grid;gap:6px;color:#9ec8e8}input,select,textarea{width:100%;box-sizing:border-box;background:#071521;color:#fff;border:1px solid #285475;border-radius:4px;padding:10px}button,.button{display:inline-block;background:#176dcc;color:#fff;border:0;border-radius:4px;padding:10px 15px;text-decoration:none;cursor:pointer}.ok{color:#55e9a9}.error{color:#ff8585}.history{display:grid;gap:9px}.entry{padding:11px;border:1px solid #23445d;border-radius:5px}.meta{color:#87a9c5;font-size:12px}.pager{display:flex;gap:6px;margin-top:14px}.pager a{padding:7px 10px;border:1px solid #285475;color:#9ed4ff;text-decoration:none}.pager .active{background:#176dcc;color:white}@media(max-width:700px){.grid{grid-template-columns:1fr}}
</style>
</head><body>
<?php require __DIR__ . '/includes/header.php'; ?>
<main class="shell">
<a class="button" href="admin.php"><?php echo h(t('admin_back')); ?></a>
<h1><?php echo h(t('admin_user_management')); ?>: <?php echo h((string)$target['username']); ?></h1>
<?php if ($message): ?><p class="ok"><?php echo h($message); ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?php echo h($error); ?></p><?php endif; ?>
<section class="card">
<form method="post">
<input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="user_id" value="<?php echo $targetId; ?>"><input type="hidden" name="action" value="update">
<div class="grid">
<label><?php echo h(t('register_username')); ?><input name="username" value="<?php echo h((string)$target['username']); ?>" required></label>
<label><?php echo h(t('register_email')); ?><input type="email" name="email" value="<?php echo h((string)$target['email']); ?>" required></label>
<label><?php echo h(t('register_realname')); ?><input name="real_name" value="<?php echo h((string)$target['real_name']); ?>" required></label>
<label><?php echo h(t('register_country')); ?><select name="country_code"><?php foreach($countries as $code=>$name): ?><option value="<?php echo h($code); ?>" <?php echo $code===$target['country_code']?'selected':''; ?>><?php echo h($code.' - '.$name); ?></option><?php endforeach; ?></select></label>
<label><?php echo h(t('profile_division')); ?><select name="division_code"><?php foreach($divisions as $d): ?><option value="<?php echo h($d['code']); ?>" <?php echo $d['code']===$target['division_code']?'selected':''; ?>><?php echo h($d['code'].' - '.$d['name']); ?></option><?php endforeach; ?></select></label>
<label><?php echo h(t('profile_pilot_rating')); ?><input type="number" min="0" max="9" name="rating_pilot" value="<?php echo (int)$target['rating_pilot']; ?>"></label>
<label><?php echo h(t('profile_atc_rating')); ?><input type="number" min="0" max="9" name="rating_atc" value="<?php echo (int)$target['rating_atc']; ?>"></label>
<label><?php echo h(t('admin_user_special_rating')); ?><input type="number" min="0" max="5" name="rating_special" value="<?php echo (int)$target['rating_special']; ?>" <?php echo (int)$adminUser['op_permission']<5?'disabled':''; ?>></label>
<label>OP-Level<input type="number" min="0" max="5" name="op_permission" value="<?php echo (int)$target['op_permission']; ?>" <?php echo (int)$adminUser['op_permission']<5?'disabled':''; ?>></label>
<label><span><?php echo h(t('admin_user_active')); ?></span><input type="checkbox" name="is_active" value="1" <?php echo (int)$target['is_active']===1?'checked':''; ?>></label>
</div><p><button type="submit"><?php echo h(t('admin_user_save')); ?></button></p>
</form></section>
<section class="card"><h2><?php echo h(t('admin_staff_notes')); ?></h2>
<form method="post"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="user_id" value="<?php echo $targetId; ?>"><input type="hidden" name="action" value="note">
<textarea name="note" rows="4" maxlength="4000" required></textarea><p><button><?php echo h(t('admin_note_add')); ?></button></p></form>
<div class="history"><?php foreach($notes as $note): ?><div class="entry"><?php echo nl2br(h($note['note_text'])); ?><div class="meta"><?php echo h(($note['author']??t('admin_system')).' · '.$note['created_at']); ?></div></div><?php endforeach; ?></div>
</section>
<section class="card"><h2><?php echo h(t('admin_private_history')); ?></h2><div class="history">
<?php foreach($history as $item): ?><div class="entry"><strong><?php echo h(t($item['activity_key'])); ?></strong><br><?php echo h((string)$item['activity_value']); ?><div class="meta"><?php echo h(($item['actor_username']??t('admin_system')).' · '.$item['created_at']); ?></div></div><?php endforeach; ?>
</div><nav class="pager"><?php for($i=1;$i<=$pages;$i++): ?><a class="<?php echo $i===$page?'active':''; ?>" href="?id=<?php echo $targetId; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a><?php endfor; ?></nav></section>
</main><?php require __DIR__ . '/includes/footer.php'; ?></body></html>
