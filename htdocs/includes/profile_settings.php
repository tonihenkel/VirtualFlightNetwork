<?php
require_once __DIR__ . '/division_schema.php';
$settingsDivisions = $pdo
    ->query("SELECT code, name, join_enabled FROM divisions WHERE is_active = 1 ORDER BY name")
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
    .avatar-settings-layout{display:grid;grid-template-columns:180px minmax(0,1fr);gap:22px;align-items:start}
    .avatar-current{width:160px;height:160px;border-radius:50%;overflow:hidden;border:3px solid #2d5a82;background:linear-gradient(135deg,#ffae4a,#16385c)}
    .avatar-current img{display:block;width:100%;height:100%;object-fit:cover}
    .avatar-editor{display:none;margin-top:16px;gap:12px}
    .avatar-editor.active{display:grid}
    .avatar-canvas-wrap{width:min(100%,420px);aspect-ratio:1;background:#06101d;border:1px solid #2d5a82;overflow:hidden;touch-action:none;cursor:grab}
    .avatar-canvas-wrap:active{cursor:grabbing}
    #avatarCanvas{display:block;width:100%;height:100%}
    .avatar-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:end}
    .avatar-actions .settings-button{margin-top:0}
    .settings-button.danger{background:#8f2631;border-color:#d55360}
    @media(max-width:760px){.settings-grid{grid-template-columns:1fr}}
    @media(max-width:760px){.avatar-settings-layout{grid-template-columns:1fr}}
</style>

<?php if ($settingsMessageKey !== ''): ?>
    <div class="settings-section" style="border-color:<?php echo $settingsMessageType === 'success' ? '#1f9d61' : '#c94b57'; ?>">
        <?php echo h(t($settingsMessageKey)); ?>
    </div>
<?php endif; ?>

<div class="settings-section">
    <h2><?php echo h(t('settings_avatar_title')); ?></h2>
    <div class="avatar-settings-layout">
        <div class="avatar-current">
            <?php if ($avatarUrl !== ''): ?>
                <img src="<?php echo h($avatarUrl); ?>" alt="<?php echo h(t('profile_avatar_alt')); ?>">
            <?php endif; ?>
        </div>
        <div>
            <p class="settings-note"><?php echo h(t('settings_avatar_help')); ?></p>
            <label class="settings-field">
                <?php echo h(t('settings_avatar_choose')); ?>
                <input id="avatarFile" type="file" accept="image/jpeg,image/png,image/webp">
            </label>
            <div id="avatarEditor" class="avatar-editor">
                <div id="avatarCanvasWrap" class="avatar-canvas-wrap">
                    <canvas id="avatarCanvas" width="512" height="512"></canvas>
                </div>
                <label class="settings-field">
                    <?php echo h(t('settings_avatar_zoom')); ?>
                    <input id="avatarZoom" type="range" min="1" max="3" step="0.01" value="1">
                </label>
                <div class="avatar-actions">
                    <button id="avatarSave" type="button" class="settings-button">
                        <?php echo h(t('settings_avatar_save')); ?>
                    </button>
                    <span id="avatarStatus" class="settings-note"></span>
                </div>
            </div>
            <?php if ($avatarUrl !== ''): ?>
                <form method="post" action="execute/profile_avatar.php"
                      onsubmit="return confirm(<?php echo h(json_encode(t('settings_avatar_delete_confirm'))); ?>);">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="settings-button danger"><?php echo h(t('settings_avatar_delete')); ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="settings-section">
    <h2><?php echo h(t('settings_map_title')); ?></h2>
    <p class="settings-note"><?php echo h(t('settings_map_waypoint_labels_help')); ?></p>
    <form method="post" action="execute/profile_settings.php">
        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="map_preferences">
        <label class="settings-field">
            <?php echo h(t('settings_map_waypoint_labels')); ?>
            <select name="map_waypoint_labels_mode">
                <option value="always" <?php echo ($profileUser['map_waypoint_labels_mode'] ?? 'always') === 'always' ? 'selected' : ''; ?>>
                    <?php echo h(t('settings_map_waypoint_labels_always')); ?>
                </option>
                <option value="hover" <?php echo ($profileUser['map_waypoint_labels_mode'] ?? 'always') === 'hover' ? 'selected' : ''; ?>>
                    <?php echo h(t('settings_map_waypoint_labels_hover')); ?>
                </option>
            </select>
        </label>
        <button class="settings-button"><?php echo h(t('settings_save')); ?></button>
    </form>
</div>

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
                            <option value="<?php echo h($item['code']); ?>" <?php echo (int)($item['join_enabled'] ?? 1) === 1 ? '' : 'disabled'; ?>>
                                <?php echo h(divisionFlagEmoji((string)$item['code']) . ' ' . $item['code'] . ' - ' . $item['name'] . ((int)($item['join_enabled'] ?? 1) === 1 ? '' : ' (' . t('division_closed') . ')')); ?>
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

<script>
(() => {
    const fileInput = document.getElementById('avatarFile');
    const editor = document.getElementById('avatarEditor');
    const canvas = document.getElementById('avatarCanvas');
    const wrap = document.getElementById('avatarCanvasWrap');
    const zoomInput = document.getElementById('avatarZoom');
    const saveButton = document.getElementById('avatarSave');
    const status = document.getElementById('avatarStatus');
    if (!fileInput || !canvas || !canvas.getContext) return;

    const context = canvas.getContext('2d');
    const csrf = <?php echo json_encode($csrf); ?>;
    const text = {
        loading: <?php echo json_encode(t('settings_avatar_loading')); ?>,
        saving: <?php echo json_encode(t('settings_avatar_saving')); ?>,
        failed: <?php echo json_encode(t('settings_avatar_failed')); ?>
    };
    let image = null;
    let baseScale = 1;
    let offsetX = 0;
    let offsetY = 0;
    let dragging = false;
    let lastX = 0;
    let lastY = 0;

    function clampOffsets() {
        if (!image) return;
        const scale = baseScale * Number(zoomInput.value);
        const width = image.naturalWidth * scale;
        const height = image.naturalHeight * scale;
        offsetX = Math.min(0, Math.max(canvas.width - width, offsetX));
        offsetY = Math.min(0, Math.max(canvas.height - height, offsetY));
    }

    function draw() {
        context.clearRect(0, 0, canvas.width, canvas.height);
        if (!image) return;
        clampOffsets();
        const scale = baseScale * Number(zoomInput.value);
        context.drawImage(
            image, offsetX, offsetY,
            image.naturalWidth * scale, image.naturalHeight * scale
        );
    }

    fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            status.textContent = text.failed;
            return;
        }
        status.textContent = text.loading;
        const objectUrl = URL.createObjectURL(file);
        const nextImage = new Image();
        nextImage.onload = () => {
            URL.revokeObjectURL(objectUrl);
            image = nextImage;
            baseScale = Math.max(
                canvas.width / image.naturalWidth,
                canvas.height / image.naturalHeight
            );
            zoomInput.value = '1';
            zoomInput.dataset.previous = '1';
            offsetX = (canvas.width - image.naturalWidth * baseScale) / 2;
            offsetY = (canvas.height - image.naturalHeight * baseScale) / 2;
            editor.classList.add('active');
            status.textContent = '';
            draw();
        };
        nextImage.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            status.textContent = text.failed;
        };
        nextImage.src = objectUrl;
    });

    zoomInput.addEventListener('input', () => {
        if (!image) return;
        const oldCenterX = (canvas.width / 2 - offsetX);
        const oldCenterY = (canvas.height / 2 - offsetY);
        const previous = Number(zoomInput.dataset.previous || 1);
        const ratio = Number(zoomInput.value) / previous;
        offsetX = canvas.width / 2 - oldCenterX * ratio;
        offsetY = canvas.height / 2 - oldCenterY * ratio;
        zoomInput.dataset.previous = zoomInput.value;
        draw();
    });

    wrap.addEventListener('pointerdown', event => {
        if (!image) return;
        dragging = true;
        lastX = event.clientX;
        lastY = event.clientY;
        wrap.setPointerCapture(event.pointerId);
    });
    wrap.addEventListener('pointermove', event => {
        if (!dragging) return;
        const factor = canvas.width / wrap.getBoundingClientRect().width;
        offsetX += (event.clientX - lastX) * factor;
        offsetY += (event.clientY - lastY) * factor;
        lastX = event.clientX;
        lastY = event.clientY;
        draw();
    });
    wrap.addEventListener('pointerup', () => { dragging = false; });
    wrap.addEventListener('pointercancel', () => { dragging = false; });

    saveButton.addEventListener('click', () => {
        if (!image) return;
        saveButton.disabled = true;
        status.textContent = text.saving;
        canvas.toBlob(async blob => {
            if (!blob) {
                saveButton.disabled = false;
                status.textContent = text.failed;
                return;
            }
            const body = new FormData();
            body.append('csrf', csrf);
            body.append('action', 'upload');
            body.append('avatar', blob, 'avatar.jpg');
            try {
                const response = await fetch('execute/profile_avatar.php', {
                    method: 'POST',
                    body
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error('upload_failed');
                location.href = 'profile.php?a=settings&type=success&message=settings_avatar_saved';
            } catch (error) {
                saveButton.disabled = false;
                status.textContent = text.failed;
            }
        }, 'image/jpeg', 0.9);
    });
})();
</script>
