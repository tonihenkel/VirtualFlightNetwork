<?php
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/division_schema.php';
require_once __DIR__ . '/includes/division_content.php';
require_once __DIR__ . '/includes/activity_log.php';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!validateVfnWebSession($pdo)) { header('Location: index.php'); exit; }
ensureDivisionManagementSchema($pdo);
$userId = (int)$_SESSION['web_user_id'];
$userStmt = $pdo->prepare("SELECT id, username, op_permission, division_code FROM users WHERE id = :id LIMIT 1");
$userStmt->execute(['id' => $userId]);
$viewer = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$viewer) { header('Location: index.php'); exit; }
$opPermission = (int)$viewer['op_permission'];
$_SESSION['web_op_permission'] = $opPermission;
if (empty($_SESSION['division_csrf'])) { $_SESSION['division_csrf'] = bin2hex(random_bytes(32)); }
$csrf = (string)$_SESSION['division_csrf'];

if ($opPermission >= 1) {
    $divisions = $pdo->query("SELECT * FROM divisions ORDER BY is_active DESC, name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        "SELECT d.* FROM divisions d INNER JOIN division_staff ds ON ds.division_code = d.code
         WHERE ds.user_id = :user_id
           AND ds.is_active = 1
           AND (ds.can_edit_content = 1 OR ds.role_code = 'DIR')
         ORDER BY d.name"
    );
    $stmt->execute(['user_id' => $userId]);
    $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if (!$divisions) { http_response_code(403); $accessDenied = true; } else { $accessDenied = false; }
$allowedCodes = array_column($divisions, 'code');
$selectedCode = strtoupper(trim((string)($_GET['code'] ?? $_POST['division_code'] ?? ($allowedCodes[0] ?? ''))));
if (!in_array($selectedCode, $allowedCodes, true)) { $selectedCode = (string)($allowedCodes[0] ?? ''); }
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$accessDenied) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid CSRF token.'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_division' && $opPermission >= 3) {
        $newCode = strtoupper(trim((string)($_POST['new_code'] ?? '')));
        $newName = trim((string)($_POST['new_name'] ?? ''));
        $newShortName = trim((string)($_POST['new_short_name'] ?? ''));
        $newLanguage = strtolower(trim((string)($_POST['new_language_code'] ?? 'en')));
        $newDescription = trim((string)($_POST['new_description'] ?? ''));
        if (!preg_match('/^[A-Z0-9-]{2,10}$/', $newCode) || $newName === '') {
            $message = t('division_create_invalid');
            $messageType = 'error';
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM divisions WHERE code = :code LIMIT 1');
            $exists->execute(['code' => $newCode]);
            if ($exists->fetchColumn()) {
                $message = t('division_create_exists');
                $messageType = 'error';
            } else {
                $defaultContent = sanitizeDivisionHtml(
                    '<section class="division-hero-card"><h1>%division_name%</h1><p>%division_description%</p></section>'
                    . '<section class="division-stat-grid"><article class="division-stat-card"><strong>%division_member_total%</strong><span>Members</span></article>'
                    . '<article class="division-stat-card"><strong>%division_flight_hours_total%</strong><span>Flight hours</span></article>'
                    . '<article class="division-stat-card"><strong>%division_flights_total%</strong><span>Flights</span></article></section>'
                );
                $insert = $pdo->prepare(
                    "INSERT INTO divisions
                        (code, name, short_name, language_code, description, website_content, is_active, join_enabled)
                     VALUES
                        (:code, :name, :short_name, :language_code, :description, :content, :is_active, :join_enabled)"
                );
                $insert->execute([
                    'code' => $newCode,
                    'name' => mb_substr($newName, 0, 100),
                    'short_name' => mb_substr($newShortName !== '' ? $newShortName : $newName, 0, 20),
                    'language_code' => mb_substr($newLanguage !== '' ? $newLanguage : 'en', 0, 10),
                    'description' => mb_substr($newDescription, 0, 10000),
                    'content' => $defaultContent,
                    'is_active' => isset($_POST['new_is_active']) ? 1 : 0,
                    'join_enabled' => isset($_POST['new_join_enabled']) ? 1 : 0
                ]);
                logActivity($pdo, $userId, 'staff', 'activity_division_created', $newCode, $userId);
                header('Location: admin_divisions.php?code=' . rawurlencode($newCode) . '&created=1');
                exit;
            }
        }
    } elseif ($action === 'delete_division' && $opPermission >= 3) {
        $memberStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE division_code = :code');
        $memberStmt->execute(['code' => $selectedCode]);
        if ((int)$memberStmt->fetchColumn() > 0) {
            $message = t('division_delete_has_members');
            $messageType = 'error';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM division_staff WHERE division_code = :code')->execute(['code' => $selectedCode]);
                $pdo->prepare('DELETE FROM division_content_revisions WHERE division_code = :code')->execute(['code' => $selectedCode]);
                $pdo->prepare(
                    'DELETE FROM division_transfer_requests
                     WHERE current_division_code = :code OR requested_division_code = :code'
                )->execute(['code' => $selectedCode]);
                $pdo->prepare('DELETE FROM divisions WHERE code = :code')->execute(['code' => $selectedCode]);
                logActivity($pdo, $userId, 'staff', 'activity_division_deleted', $selectedCode, $userId);
                $pdo->commit();
                header('Location: admin_divisions.php?deleted=1');
                exit;
            } catch (Throwable $deleteError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $deleteError;
            }
        }
    } elseif ($action === 'toggle_join' && canManageDivisionJoin($pdo, $userId, $opPermission, $selectedCode)) {
        $joinEnabled = isset($_POST['join_enabled']) ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE divisions SET join_enabled = :join_enabled WHERE code = :code');
        $stmt->execute(['join_enabled' => $joinEnabled, 'code' => $selectedCode]);
        logActivity(
            $pdo,
            $userId,
            'staff',
            $joinEnabled ? 'activity_division_join_opened' : 'activity_division_join_closed',
            $selectedCode,
            $userId
        );
        $message = $joinEnabled ? t('division_join_opened') : t('division_join_closed');
    } elseif ($action === 'save_content' && canEditDivisionContent($pdo, $userId, $opPermission, $selectedCode)) {
        $content = sanitizeDivisionHtml((string)($_POST['website_content'] ?? ''));
        $params = ['content' => $content, 'code' => $selectedCode];
        $sql = "UPDATE divisions SET website_content = :content WHERE code = :code";
        if ($opPermission >= 3) {
            $sql = "UPDATE divisions SET website_content = :content, name = :name, short_name = :short_name, language_code = :language_code, description = :description, is_active = :is_active WHERE code = :code";
            $params += [
                'name' => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100),
                'short_name' => mb_substr(trim((string)($_POST['short_name'] ?? '')), 0, 20),
                'language_code' => mb_substr(strtolower(trim((string)($_POST['language_code'] ?? 'en'))), 0, 10),
                'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 10000),
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
        }
        $revision = $pdo->prepare(
            "INSERT INTO division_content_revisions (division_code, website_content)
             SELECT code, website_content FROM divisions WHERE code = :code"
        );
        $revision->execute(['code' => $selectedCode]);
        $pdo->prepare($sql)->execute($params);
        logActivity($pdo, $userId, 'staff', 'activity_division_page_updated', $selectedCode, $userId);
        $message = t('division_saved');
    } elseif ($action === 'add_staff' && $opPermission >= 3) {
        $identity = trim((string)($_POST['staff_identity'] ?? ''));
        $find = $pdo->prepare("SELECT id, division_code FROM users WHERE username = :identity OR email = :identity LIMIT 1");
        $find->execute(['identity' => $identity]);
        $staffUser = $find->fetch(PDO::FETCH_ASSOC) ?: [];
        $staffUserId = (int)($staffUser['id'] ?? 0);
        $roles = divisionStaffRoles();
        $role = strtoupper((string)($_POST['role_code'] ?? 'STAFF'));
        if ($staffUserId < 1 || !isset($roles[$role])) {
            $message = t('division_staff_invalid'); $messageType = 'error';
        } elseif (strtoupper((string)($staffUser['division_code'] ?? '')) !== $selectedCode) {
            $message = t('division_staff_wrong_division'); $messageType = 'error';
        } else {
            $currentStaffStmt = $pdo->prepare(
                "SELECT role_code, role_title, is_active FROM division_staff
                 WHERE division_code = :code AND user_id = :user_id LIMIT 1"
            );
            $currentStaffStmt->execute(['code' => $selectedCode, 'user_id' => $staffUserId]);
            $currentStaff = $currentStaffStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $roleTitle = mb_substr(trim((string)($_POST['role_title'] ?? '')), 0, 100);
            $stmt = $pdo->prepare(
                "INSERT INTO division_staff (division_code,user_id,role_code,role_title,sort_order,can_edit_content,is_active,appointed_by_user_id)
                 VALUES (:code,:user_id,:role,:title,:sort_order,:can_edit,1,:actor)
                 ON DUPLICATE KEY UPDATE role_code=VALUES(role_code),role_title=VALUES(role_title),sort_order=VALUES(sort_order),can_edit_content=VALUES(can_edit_content),is_active=1,appointed_by_user_id=VALUES(appointed_by_user_id)"
            );
            $stmt->execute([
                'code' => $selectedCode, 'user_id' => $staffUserId, 'role' => $role,
                'title' => $roleTitle,
                'sort_order' => max(0, min(999, (int)($_POST['sort_order'] ?? 100))),
                'can_edit' => isset($_POST['can_edit_content']) ? 1 : 0, 'actor' => $userId
            ]);
            $activityKey = $currentStaff && (int)$currentStaff['is_active'] === 1
                ? 'activity_division_staff_role_changed'
                : 'activity_division_staff_added';
            $displayRole = $roleTitle !== '' ? $roleTitle : $roles[$role];
            logActivity(
                $pdo,
                $staffUserId,
                'staff',
                $activityKey,
                $selectedCode . ' · ' . $role . ' · ' . $displayRole,
                $userId
            );
            $message = t('division_staff_saved');
        }
    } elseif ($action === 'remove_staff' && $opPermission >= 3) {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $currentStaffStmt = $pdo->prepare(
            "SELECT user_id, role_code, role_title FROM division_staff
             WHERE id = :id AND division_code = :code AND is_active = 1 LIMIT 1"
        );
        $currentStaffStmt->execute(['id' => $staffId, 'code' => $selectedCode]);
        $removedStaff = $currentStaffStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($removedStaff) {
            $stmt = $pdo->prepare("UPDATE division_staff SET is_active = 0 WHERE id = :id AND division_code = :code");
            $stmt->execute(['id' => $staffId, 'code' => $selectedCode]);
            $activityValue = $selectedCode . ' · ' . (string)$removedStaff['role_code'];
            if ((string)$removedStaff['role_title'] !== '') {
                $activityValue .= ' · ' . (string)$removedStaff['role_title'];
            }
            logActivity(
                $pdo,
                (int)$removedStaff['user_id'],
                'staff',
                'activity_division_staff_removed',
                $activityValue,
                $userId
            );
            $message = t('division_staff_removed');
        }
    }
    $reload = $pdo->prepare("SELECT * FROM divisions WHERE code = :code LIMIT 1");
    $reload->execute(['code' => $selectedCode]);
    foreach ($divisions as &$divisionItem) {
        if ($divisionItem['code'] === $selectedCode) { $divisionItem = $reload->fetch(PDO::FETCH_ASSOC) ?: $divisionItem; break; }
    }
    unset($divisionItem);
}
$selectedDivision = null;
foreach ($divisions as $divisionItem) { if ($divisionItem['code'] === $selectedCode) { $selectedDivision = $divisionItem; break; } }
$canEditSelected = $selectedDivision
    ? canEditDivisionContent($pdo, $userId, $opPermission, $selectedCode)
    : false;
$staff = [];
if ($selectedDivision) {
    $stmt = $pdo->prepare("SELECT ds.*,u.username,u.real_name,u.email FROM division_staff ds INNER JOIN users u ON u.id=ds.user_id WHERE ds.division_code=:code AND ds.is_active=1 ORDER BY ds.sort_order,ds.role_code");
    $stmt->execute(['code' => $selectedCode]); $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$placeholders = [
    '%division_code%','%division_name%','%division_short_name%','%division_description%',
    '%division_member_total%','%division_active_pilots%','%division_staff_total%',
    '%division_flights_total%','%division_flight_hours_total%','%division_flight_nm_total%',
    '%division_landings_total%','%division_top_pilot_name%','%division_top_pilot_hours%',
    '%division_members%','%division_staff%'
];
?>
<!doctype html><html lang="<?php echo htmlspecialchars($_SESSION['language'] ?? 'en'); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo htmlspecialchars(t('division_admin_title')); ?> - <?php echo htmlspecialchars($projectName); ?></title>
<style>
.editor.source-hidden{display:none}.source-editor{display:none;min-height:480px;box-sizing:border-box;font:14px/1.5 Consolas,monospace;tab-size:2}.source-editor.active{display:block}.button.active{background:#1478d4}.code-hint{color:#9ec7e4;font-size:.94rem}
body{margin:0;background:#071822;color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1500px,calc(100% - 42px));margin:30px auto 70px}.layout{display:grid;grid-template-columns:290px 1fr;gap:20px}.box{background:#0c1e2b;border:1px solid #24506b;border-radius:12px;padding:20px}.division-list a{display:block;padding:13px;margin:7px 0;border-radius:8px;color:#d7e8ff;text-decoration:none;background:#102b3c}.division-list a.active{background:#176fc0;color:#fff}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}input,textarea,select{width:100%;padding:11px;background:#071521;color:#e8f4ff;border:1px solid #28536e;border-radius:7px}textarea{min-height:90px}.toolbar{display:flex;flex-wrap:wrap;gap:7px;margin:15px 0}.button{border:1px solid #3173a0;background:#123852;color:#fff;border-radius:7px;padding:9px 13px;cursor:pointer;text-decoration:none}.button.primary{background:#1478d4}.button.danger{background:#8d2d35}.editor{min-height:390px;padding:20px;background:#fff;color:#17202a;border-radius:8px;overflow:auto}.placeholder-list{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}.placeholder-list button{font-family:monospace}.staff-row{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:center;padding:11px 0;border-bottom:1px solid #24465a}.message{padding:12px;border-radius:8px;background:#124b3c;margin-bottom:15px}.message.error{background:#6b2830}@media(max-width:900px){.layout{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}}
</style></head><body><?php include __DIR__ . '/includes/header.php'; ?><main class="shell"><h1><?php echo htmlspecialchars(t('division_admin_title')); ?></h1><p><?php echo htmlspecialchars(t('division_admin_intro')); ?></p>
<?php if ($accessDenied): ?><div class="box"><p><?php echo htmlspecialchars(t('admin_access_denied')); ?></p></div><?php else: ?><div class="layout"><aside class="box division-list"><h2><?php echo htmlspecialchars(t('division_overview')); ?></h2><?php foreach($divisions as $divisionItem): ?><a class="<?php echo $divisionItem['code']===$selectedCode?'active':''; ?>" href="admin_divisions.php?code=<?php echo urlencode($divisionItem['code']); ?>"><img src="images/flags/<?php echo htmlspecialchars(strtolower((string)$divisionItem['code'])); ?>.png" alt="" style="width:25px;max-height:18px;object-fit:cover;vertical-align:middle;margin-right:7px"><strong><?php echo htmlspecialchars($divisionItem['code']); ?></strong><br><?php echo htmlspecialchars($divisionItem['name']); ?><br><small><?php echo (int)($divisionItem['join_enabled'] ?? 1) === 1 ? htmlspecialchars(t('division_join_open')) : htmlspecialchars(t('division_join_closed_label')); ?></small></a><?php endforeach; ?><?php if($opPermission>=3): ?><hr><h2><?php echo htmlspecialchars(t('division_create_title')); ?></h2><form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="create_division"><label class="field"><?php echo htmlspecialchars(t('division_code')); ?><input name="new_code" required maxlength="10" pattern="[A-Za-z0-9-]{2,10}"></label><label class="field"><?php echo htmlspecialchars(t('division_name')); ?><input name="new_name" required maxlength="100"></label><label class="field"><?php echo htmlspecialchars(t('division_short_name')); ?><input name="new_short_name" maxlength="20"></label><label class="field"><?php echo htmlspecialchars(t('division_language')); ?><input name="new_language_code" value="en" maxlength="10"></label><label class="field"><?php echo htmlspecialchars(t('division_description')); ?><textarea name="new_description"></textarea></label><label><input style="width:auto" type="checkbox" name="new_is_active" checked> <?php echo htmlspecialchars(t('division_active')); ?></label><br><label><input style="width:auto" type="checkbox" name="new_join_enabled" checked> <?php echo htmlspecialchars(t('division_join_enabled')); ?></label><p><button class="button primary" type="submit"><?php echo htmlspecialchars(t('division_create')); ?></button></p></form><?php endif; ?></aside>
<section class="box"><?php if($message): ?><div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?><?php if($selectedDivision): ?><div style="display:flex;justify-content:space-between;gap:15px;align-items:center"><h2><img src="images/flags/<?php echo htmlspecialchars(strtolower($selectedCode)); ?>.png" alt="" style="width:30px;max-height:22px;object-fit:cover;vertical-align:middle;margin-right:8px"><?php echo htmlspecialchars($selectedDivision['code'].' – '.$selectedDivision['name']); ?></h2><a class="button" target="_blank" href="division.php?code=<?php echo urlencode($selectedCode); ?>"><?php echo htmlspecialchars(t('division_open_page')); ?></a></div><?php if(canManageDivisionJoin($pdo,$userId,$opPermission,$selectedCode)): ?><form method="post" class="message"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="toggle_join"><input type="hidden" name="division_code" value="<?php echo htmlspecialchars($selectedCode); ?>"><label><input style="width:auto" type="checkbox" name="join_enabled" <?php echo (int)($selectedDivision['join_enabled'] ?? 1)===1?'checked':''; ?>> <?php echo htmlspecialchars(t('division_join_enabled')); ?></label> <button class="button" type="submit"><?php echo htmlspecialchars(t('settings_save')); ?></button><br><small><?php echo htmlspecialchars(t('division_join_help')); ?></small></form><?php endif; ?><?php if($opPermission>=3): ?><form method="post" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('division_delete_confirm'), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="delete_division"><input type="hidden" name="division_code" value="<?php echo htmlspecialchars($selectedCode); ?>"><button class="button danger" type="submit"><?php echo htmlspecialchars(t('division_delete')); ?></button></form><?php endif; ?>
<?php if($canEditSelected): ?><form method="post" id="divisionForm"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="save_content"><input type="hidden" name="division_code" value="<?php echo htmlspecialchars($selectedCode); ?>"><textarea hidden name="website_content" id="websiteContentInput"></textarea>
<?php if($opPermission>=3): ?><div class="grid"><label class="field"><?php echo htmlspecialchars(t('division_name')); ?><input name="name" required maxlength="100" value="<?php echo htmlspecialchars($selectedDivision['name']); ?>"></label><label class="field"><?php echo htmlspecialchars(t('division_short_name')); ?><input name="short_name" maxlength="20" value="<?php echo htmlspecialchars($selectedDivision['short_name']); ?>"></label><label class="field"><?php echo htmlspecialchars(t('division_language')); ?><input name="language_code" maxlength="10" value="<?php echo htmlspecialchars($selectedDivision['language_code']); ?>"></label><label class="field"><span><?php echo htmlspecialchars(t('division_active')); ?></span><input style="width:auto" type="checkbox" name="is_active" <?php echo (int)$selectedDivision['is_active']===1?'checked':''; ?>></label><label class="field full"><?php echo htmlspecialchars(t('division_description')); ?><textarea name="description"><?php echo htmlspecialchars($selectedDivision['description']); ?></textarea></label></div><?php endif; ?>
<h3><?php echo htmlspecialchars(t('division_page_builder')); ?></h3><p class="code-hint"><?php echo htmlspecialchars(t('division_safe_code_hint')); ?></p><div class="toolbar"><button class="button" type="button" data-command="bold"><b>B</b></button><button class="button" type="button" data-command="italic"><i>I</i></button><button class="button" type="button" data-command="formatBlock" data-value="h2">H2</button><button class="button" type="button" data-command="insertUnorderedList">• List</button><button class="button" type="button" data-block="hero">Hero</button><button class="button" type="button" data-block="cards">Cards</button><button class="button" type="button" data-block="button">Button</button><button class="button" type="button" data-block="css">CSS Starter</button><button class="button" type="button" data-block="stats"><?php echo htmlspecialchars(t('division_block_stats')); ?></button><button class="button" type="button" data-block="staff"><?php echo htmlspecialchars(t('division_block_staff')); ?></button><button class="button" type="button" data-block="members"><?php echo htmlspecialchars(t('division_block_members')); ?></button><button class="button" id="toggleSourceButton" type="button">&lt;/&gt; <?php echo htmlspecialchars(t('division_html_source')); ?></button></div>
<div class="editor" id="divisionEditor" contenteditable="true"><?php echo sanitizeDivisionHtml((string)$selectedDivision['website_content']); ?></div><textarea class="source-editor" id="divisionSourceEditor" spellcheck="false"></textarea><h3><?php echo htmlspecialchars(t('division_placeholders')); ?></h3><div class="placeholder-list"><?php foreach($placeholders as $placeholder): ?><button class="button placeholder" type="button" data-placeholder="<?php echo htmlspecialchars($placeholder); ?>"><?php echo htmlspecialchars($placeholder); ?></button><?php endforeach; ?></div><p><button class="button primary" type="submit"><?php echo htmlspecialchars(t('division_save_publish')); ?></button></p></form><?php endif; ?>
<?php if($opPermission>=3): ?><hr><h2><?php echo htmlspecialchars(t('division_staff_management')); ?></h2><form method="post" class="grid"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="add_staff"><input type="hidden" name="division_code" value="<?php echo htmlspecialchars($selectedCode); ?>"><label class="field"><?php echo htmlspecialchars(t('division_staff_user')); ?><input name="staff_identity" placeholder="Username / Email" required></label><label class="field"><?php echo htmlspecialchars(t('division_staff_role')); ?><select name="role_code"><?php foreach(divisionStaffRoles() as $roleCode=>$roleName): ?><option value="<?php echo htmlspecialchars($roleCode); ?>"><?php echo htmlspecialchars($roleCode.' – '.$roleName); ?></option><?php endforeach; ?></select></label><label class="field"><?php echo htmlspecialchars(t('division_staff_custom_title')); ?><input name="role_title" maxlength="100"></label><label class="field"><?php echo htmlspecialchars(t('division_staff_order')); ?><input name="sort_order" type="number" value="100" min="0" max="999"></label><label class="field"><span><?php echo htmlspecialchars(t('division_staff_can_edit')); ?></span><input style="width:auto" type="checkbox" name="can_edit_content" checked></label><p><button class="button primary" type="submit"><?php echo htmlspecialchars(t('division_staff_add')); ?></button></p></form><div><?php foreach($staff as $staffItem): ?><div class="staff-row"><span><strong><?php echo htmlspecialchars($staffItem['role_code']); ?></strong> <?php echo htmlspecialchars($staffItem['role_title']); ?></span><span><?php echo htmlspecialchars($staffItem['real_name'] ?: $staffItem['username']); ?></span><form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="remove_staff"><input type="hidden" name="division_code" value="<?php echo htmlspecialchars($selectedCode); ?>"><input type="hidden" name="staff_id" value="<?php echo (int)$staffItem['id']; ?>"><button class="button danger" type="submit"><?php echo htmlspecialchars(t('remove')); ?></button></form></div><?php endforeach; ?></div><?php endif; ?>
<?php endif; ?></section></div><?php endif; ?></main><?php include __DIR__ . '/includes/footer.php'; require_once __DIR__ . '/includes/auth_modals.php'; ?>
<script>
const editor=document.getElementById('divisionEditor'),sourceEditor=document.getElementById('divisionSourceEditor'),toggleSource=document.getElementById('toggleSourceButton'),form=document.getElementById('divisionForm');
if(editor&&sourceEditor&&form){let sourceMode=false;document.querySelectorAll('[data-command]').forEach(b=>b.addEventListener('click',()=>{if(sourceMode)return;editor.focus();document.execCommand(b.dataset.command,false,b.dataset.value||null)}));
if(toggleSource)toggleSource.addEventListener('click',()=>{sourceMode=!sourceMode;if(sourceMode){sourceEditor.value=editor.innerHTML;editor.classList.add('source-hidden');sourceEditor.classList.add('active');sourceEditor.focus()}else{editor.innerHTML=sourceEditor.value;sourceEditor.classList.remove('active');editor.classList.remove('source-hidden')}toggleSource.classList.toggle('active',sourceMode)});
const insert=html=>{if(sourceMode){const start=sourceEditor.selectionStart,end=sourceEditor.selectionEnd;sourceEditor.setRangeText(html,start,end,'end');sourceEditor.focus();return}editor.focus();document.execCommand('insertHTML',false,html)};
document.querySelectorAll('[data-placeholder]').forEach(b=>b.addEventListener('click',()=>insert(b.dataset.placeholder)));
document.querySelectorAll('[data-block]').forEach(b=>b.addEventListener('click',()=>{const blocks={hero:'<header class="division-hero"><p class="division-kicker">%division_code%</p><h1>%division_name%</h1><p>%division_description%</p><a class="division-cta" href="#division-members">Meet the division</a></header>',cards:'<section class="custom-card-grid"><article class="custom-card"><h2>Heading</h2><p>Your content</p></article><article class="custom-card"><h2>Heading</h2><p>Your content</p></article></section>',button:'<button type="button" class="custom-button">Button text</button>',css:'<style>\n.division-hero{padding:64px 28px;text-align:center;border-radius:24px;background:linear-gradient(135deg,#06233d,#087ea4);color:#fff}\n.division-kicker{letter-spacing:.18em;text-transform:uppercase}\n.division-cta,.custom-button{display:inline-block;padding:12px 20px;border:0;border-radius:999px;background:#38e8c6;color:#052235;text-decoration:none;font-weight:700}\n.custom-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin:28px 0}\n.custom-card{padding:22px;border:1px solid #27617d;border-radius:16px;background:#0d2939;color:#eef8ff}\n</style>',stats:'<section class="division-stat-grid"><article class="division-stat-card"><strong>%division_member_total%</strong><span>Members</span></article><article class="division-stat-card"><strong>%division_flight_hours_total%</strong><span>Flight hours</span></article><article class="division-stat-card"><strong>%division_flights_total%</strong><span>Flights</span></article></section>',staff:'<section><h2>Division Staff</h2>%division_staff%</section>',members:'<section id="division-members"><h2>Members</h2>%division_members%</section>'};insert(blocks[b.dataset.block])}));form.addEventListener('submit',()=>document.getElementById('websiteContentInput').value=sourceMode?sourceEditor.value:editor.innerHTML)}
</script></body></html>
