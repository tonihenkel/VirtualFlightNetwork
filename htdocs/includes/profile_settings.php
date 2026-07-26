<?php
$settingsDivisions = $pdo
    ->query("SELECT code, name FROM divisions WHERE is_active = 1 ORDER BY name")
    ->fetchAll(PDO::FETCH_ASSOC);

$twoFactorStmt = $pdo->prepare(
    "SELECT method FROM user_two_factor WHERE user_id = :user_id LIMIT 1"
);
$twoFactorStmt->execute(['user_id' => $profileUserId]);
$twoFactorMethod = (string)($twoFactorStmt->fetchColumn() ?: 'off');

$transferStmt = $pdo->prepare(
    "SELECT requested_division_code, reason, status, created_at
     FROM division_transfer_requests
     WHERE user_id = :user_id
     ORDER BY id DESC LIMIT 1"
);
$transferStmt->execute(['user_id' => $profileUserId]);
$latestTransfer = $transferStmt->fetch(PDO::FETCH_ASSOC);
$csrf = (string)$_SESSION['profile_settings_csrf'];
$totpSecret = (string)($_SESSION['profile_totp_setup_secret'] ?? '');
$totpUri =
    'otpauth://totp/VirtualFlightNetwork:'
    . rawurlencode((string)$profileUser['username'])
    . '?secret=' . rawurlencode($totpSecret)
    . '&issuer=VirtualFlightNetwork&digits=6&period=30';
$settingsMessageKey = trim((string)($_GET['message'] ?? ''));
$settingsMessageType = (string)($_GET['type'] ?? '');
?>

<style>
    .settings-section{background:#101b27;border:1px solid #24405c;border-radius:8px;padding:20px;margin-bottom:18px}
    .settings-section h2{margin-top:0}
    .settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .settings-field{display:grid;gap:6px;color:#9fb3cf}
    .settings-field input,.settings-field select,.settings-field textarea{box-sizing:border-box;width:100%;padding:11px;border:1px solid #2d5a82;border-radius:4px;background:#08131e;color:#e8f2ff}
    .settings-field textarea{min-height:90px;resize:vertical}
    .settings-button{margin-top:14px;padding:11px 18px;border:1px solid #168cff;border-radius:4px;background:#176dcc;color:#fff;cursor:pointer}
    .settings-note{color:#9fb3cf;font-size:13px;word-break:break-all}
    @media(max-width:760px){.settings-grid{grid-template-columns:1fr}}
</style>

<?php if ($settingsMessageKey !== ''): ?>
    <div class="settings-section" style="border-color:<?php echo $settingsMessageType === 'success' ? '#1f9d61' : '#c94b57'; ?>">
        <?php echo h(t($settingsMessageKey)); ?>
    </div>
<?php endif; ?>

<div class="settings-section">
    <h2><?php echo h(t('settings_personal_title')); ?></h2>
    <form method="post" action="execute/profile_settings.php">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="personal">
        <div class="settings-grid">
            <label class="settings-field">
                <?php echo h(t('settings_username')); ?>
                <input name="username" required maxlength="40" value="<?php echo h($profileUser['username']); ?>">
            </label>
            <label class="settings-field">
                <?php echo h(t('settings_real_name')); ?>
                <input name="real_name" required maxlength="100" value="<?php echo h($profileUser['real_name']); ?>">
            </label>
            <label class="settings-field">
                <?php echo h(t('settings_country')); ?>
                <select name="country_code" required>
                    <?php foreach ($countries as $code => $name): ?>
                        <option value="<?php echo h($code); ?>" <?php echo $countryCode === $code ? 'selected' : ''; ?>>
                            <?php echo h($name); ?> (<?php echo h($code); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="settings-field">
                <?php echo h(t('settings_current_password')); ?>
                <input type="password" name="current_password" required autocomplete="current-password">
            </label>
        </div>
        <button class="settings-button"><?php echo h(t('settings_save')); ?></button>
    </form>
</div>

<div class="settings-section">
    <h2><?php echo h(t('settings_division_title')); ?></h2>
    <?php if ($latestTransfer && $latestTransfer['status'] === 'pending'): ?>
        <p class="settings-note"><?php echo h(t('settings_division_pending')); ?>:
            <?php echo h($latestTransfer['requested_division_code']); ?></p>
    <?php else: ?>
        <form method="post" action="execute/profile_settings.php">
            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="division">
            <label class="settings-field">
                <?php echo h(t('settings_requested_division')); ?>
                <select name="division_code" required>
                    <?php foreach ($settingsDivisions as $item): ?>
                        <?php if (strtoupper($item['code']) !== $divisionCode): ?>
                            <option value="<?php echo h($item['code']); ?>">
                                <?php echo h($item['code'] . ' - ' . $item['name']); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="settings-field">
                <?php echo h(t('settings_division_reason')); ?>
                <textarea name="reason" required maxlength="500"></textarea>
            </label>
            <button class="settings-button"><?php echo h(t('settings_submit_request')); ?></button>
        </form>
    <?php endif; ?>
</div>

<div class="settings-section">
    <h2><?php echo h(t('settings_password_title')); ?></h2>
    <form method="post" action="execute/profile_settings.php">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="password">
        <div class="settings-grid">
            <label class="settings-field"><?php echo h(t('settings_current_password')); ?>
                <input type="password" name="current_password" required autocomplete="current-password">
            </label>
            <label class="settings-field"><?php echo h(t('settings_new_password')); ?>
                <input type="password" name="new_password" minlength="10" required autocomplete="new-password">
            </label>
            <label class="settings-field"><?php echo h(t('settings_repeat_password')); ?>
                <input type="password" name="repeat_password" minlength="10" required autocomplete="new-password">
            </label>
        </div>
        <button class="settings-button"><?php echo h(t('settings_change_password')); ?></button>
    </form>
</div>

<?php if ((int)($profileUser['op_permission'] ?? 0) >= 1): ?>
<div class="settings-section">
    <h2><?php echo h(t('settings_2fa_title')); ?></h2>
    <p class="settings-note"><?php echo h(t('settings_2fa_current')); ?>: <?php echo h(strtoupper($twoFactorMethod)); ?></p>
    <form method="post" action="execute/profile_settings.php">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="two_factor">
        <label class="settings-field"><?php echo h(t('settings_2fa_method')); ?>
            <select name="method" id="twoFactorMethod">
                <option value="off"><?php echo h(t('settings_2fa_off')); ?></option>
                <option value="totp"><?php echo h(t('settings_2fa_totp')); ?></option>
                <option value="email"><?php echo h(t('settings_2fa_email')); ?></option>
            </select>
        </label>
        <div id="totpSetup">
            <p class="settings-note"><?php echo h(t('settings_2fa_secret')); ?>: <strong><?php echo h($totpSecret); ?></strong></p>
            <p class="settings-note"><?php echo h($totpUri); ?></p>
            <label class="settings-field"><?php echo h(t('settings_2fa_code')); ?>
                <input name="totp_code" inputmode="numeric" maxlength="6">
            </label>
        </div>
        <label class="settings-field"><?php echo h(t('settings_current_password')); ?>
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <button class="settings-button"><?php echo h(t('settings_save_2fa')); ?></button>
    </form>
</div>
<script>
const twoFactorMethod = document.getElementById('twoFactorMethod');
const totpSetup = document.getElementById('totpSetup');
twoFactorMethod.value = <?php echo json_encode($twoFactorMethod); ?>;
function updateTotpSetup(){totpSetup.hidden = twoFactorMethod.value !== 'totp';}
twoFactorMethod.addEventListener('change', updateTotpSetup);
updateTotpSetup();
</script>
<?php endif; ?>
