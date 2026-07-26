<?php

require_once __DIR__ . '/ban_status.php';

$moderationCsrf = (string)$_SESSION['profile_moderation_csrf'];
$banStatus = getActiveBanStatus($pdo, (int)$profileUser['id']);
$moderationMessage = (string)($_GET['message'] ?? '');
$moderationMessageType =
    (string)($_GET['type'] ?? '') === 'success'
        ? 'success'
        : 'error';
$canUnban =
    $banStatus['active']
    && isset($viewerUser['op_permission'])
    && (int)$viewerUser['op_permission'] >= 4;
?>

<div class="profile-card">
    <h2><?php echo h(t('profile_moderation')); ?></h2>
    <p><?php echo h(t('moderation_description')); ?></p>

    <?php if (strpos($moderationMessage, 'moderation_') === 0): ?>
        <div class="moderation-status <?php echo h($moderationMessageType); ?>">
            <?php echo h(t($moderationMessage)); ?>
        </div>
    <?php endif; ?>

    <?php if ($banStatus['active']): ?>
        <div class="moderation-status banned">
            <?php echo h(t('moderation_currently_banned')); ?>:
            <?php echo h($banStatus['reason']); ?>
            <?php if ($banStatus['expires_at']): ?>
                (<?php echo h(date('d.m.Y H:i', strtotime($banStatus['expires_at']))); ?>)
            <?php else: ?>
                (<?php echo h(t('moderation_permanent')); ?>)
            <?php endif; ?>
        </div>

        <?php if ($canUnban): ?>
            <form method="post" action="execute/profile_moderation.php" class="moderation-unban">
                <input type="hidden" name="csrf" value="<?php echo h($moderationCsrf); ?>">
                <input type="hidden" name="target_user_id" value="<?php echo (int)$profileUser['id']; ?>">
                <input type="hidden" name="moderation_action" value="unban">
                <label><?php echo h(t('moderation_unban_reason')); ?></label>
                <textarea name="reason" maxlength="255" required></textarea>
                <button type="submit" class="moderation-button success">
                    <?php echo h(t('moderation_unban')); ?>
                </button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <div class="moderation-grid">
        <form method="post" action="execute/profile_moderation.php" class="moderation-box">
            <input type="hidden" name="csrf" value="<?php echo h($moderationCsrf); ?>">
            <input type="hidden" name="target_user_id" value="<?php echo (int)$profileUser['id']; ?>">
            <input type="hidden" name="moderation_action" value="kick">
            <h3><?php echo h(t('moderation_kick')); ?></h3>
            <p><?php echo h(t('moderation_kick_online_only')); ?></p>
            <label><?php echo h(t('moderation_reason')); ?></label>
            <textarea name="reason" maxlength="255" required></textarea>
            <button type="submit" class="moderation-button warning" <?php echo $isNetworkOnline ? '' : 'disabled'; ?>>
                <?php echo h(t('moderation_kick')); ?>
            </button>
        </form>

        <form method="post" action="execute/profile_moderation.php" class="moderation-box">
            <input type="hidden" name="csrf" value="<?php echo h($moderationCsrf); ?>">
            <input type="hidden" name="target_user_id" value="<?php echo (int)$profileUser['id']; ?>">
            <input type="hidden" name="moderation_action" value="ban">
            <h3><?php echo h(t('moderation_ban')); ?></h3>
            <label><?php echo h(t('moderation_reason')); ?></label>
            <textarea name="reason" maxlength="255" required></textarea>
            <label><?php echo h(t('moderation_duration')); ?></label>
            <div class="moderation-duration">
                <input type="number" name="duration_value" value="1" min="1">
                <select name="duration_unit">
                    <option value="minutes"><?php echo h(t('moderation_minutes')); ?></option>
                    <option value="hours"><?php echo h(t('moderation_hours')); ?></option>
                    <option value="days"><?php echo h(t('moderation_days')); ?></option>
                    <option value="weeks"><?php echo h(t('moderation_weeks')); ?></option>
                    <option value="months"><?php echo h(t('moderation_months')); ?></option>
                    <option value="years"><?php echo h(t('moderation_years')); ?></option>
                    <option value="permanent"><?php echo h(t('moderation_permanent')); ?></option>
                </select>
            </div>
            <button type="submit" class="moderation-button danger">
                <?php echo h(t('moderation_ban')); ?>
            </button>
        </form>
    </div>
</div>

<style>
.moderation-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin-top:20px}
.moderation-box{border:1px solid #24465f;background:#0b1b28;padding:18px;border-radius:8px}
.moderation-box label{display:block;margin:12px 0 6px;color:#9ec8e8}
.moderation-box textarea,.moderation-box input,.moderation-box select{box-sizing:border-box;width:100%;padding:10px;color:#fff;background:#071521;border:1px solid #285475;border-radius:4px}
.moderation-box textarea{min-height:90px;resize:vertical}
.moderation-duration{display:grid;grid-template-columns:100px 1fr;gap:8px}
.moderation-button{margin-top:14px;padding:11px 18px;border:0;border-radius:4px;color:#fff;cursor:pointer}
.moderation-button.warning{background:#d47a16}.moderation-button.danger{background:#c43c3c}
.moderation-button.success{background:#21885b}
.moderation-button:disabled{opacity:.45;cursor:not-allowed}
.moderation-status.banned{padding:12px;border:1px solid #d75151;background:rgba(180,35,35,.15);color:#ff8d8d;border-radius:5px}
.moderation-unban{margin:12px 0 20px;padding:16px;border:1px solid #2b9b67;background:rgba(30,150,90,.08);border-radius:5px}
.moderation-unban label{display:block;margin-bottom:6px;color:#9ec8e8}
.moderation-unban textarea{box-sizing:border-box;width:100%;min-height:70px;padding:10px;color:#fff;background:#071521;border:1px solid #285475;border-radius:4px;resize:vertical}
.moderation-status.success,.moderation-status.error{padding:12px;margin:12px 0;border-radius:5px}
.moderation-status.success{border:1px solid #2b9b67;background:rgba(30,150,90,.15);color:#65e5a5}
.moderation-status.error{border:1px solid #d75151;background:rgba(180,35,35,.15);color:#ff8d8d}
</style>
