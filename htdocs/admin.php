<?php
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';

$pdo = null;
$adminUser = null;
$accessDenied = false;
$loginRequired = false;

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    if (empty($_SESSION['web_user_id'])) {
        $loginRequired = true;
    } else {
        $adminStmt = $pdo->prepare(
            "SELECT id, username, real_name, op_permission
             FROM users
             WHERE id = :user_id
             LIMIT 1"
        );

        $adminStmt->execute([
            'user_id' => (int)$_SESSION['web_user_id']
        ]);

        $adminUser =
            $adminStmt->fetch(PDO::FETCH_ASSOC);

        if (!$adminUser || (int)$adminUser['op_permission'] <= 1) {
            $accessDenied = true;
        } else {
            $_SESSION['web_op_permission'] =
                (int)$adminUser['op_permission'];
        }
    }
} catch (Throwable $e) {
    $accessDenied = true;
}

$adminOpPermission =
    (int)($adminUser['op_permission'] ?? 0);

$canViewAllFrequencies =
    $adminOpPermission > 3;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars(t('admin_title')); ?> - <?php echo htmlspecialchars($projectName); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            color: #d7e8ff;
            background:
                radial-gradient(circle at 20% 10%, rgba(0, 132, 255, 0.18), transparent 32%),
                linear-gradient(135deg, #07101d 0%, #071822 45%, #041016 100%);
        }

        .admin-shell {
            width: min(1500px, calc(100% - 48px));
            margin: 34px auto 42px;
        }

        .admin-hero {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: flex-end;
            margin-bottom: 22px;
        }

        .admin-title {
            margin: 0 0 7px;
            font-size: 31px;
            letter-spacing: 0.3px;
            color: #ffffff;
        }

        .admin-subtitle {
            margin: 0;
            color: #9eb9d7;
            line-height: 1.5;
        }

        .admin-badge {
            padding: 10px 14px;
            border: 1px solid rgba(0, 132, 255, 0.45);
            border-radius: 8px;
            color: #00ffcc;
            background: rgba(0, 17, 28, 0.7);
            white-space: nowrap;
        }

        .admin-card {
            border: 1px solid rgba(64, 139, 198, 0.38);
            border-radius: 8px;
            background: rgba(5, 15, 25, 0.88);
            box-shadow: 0 18px 70px rgba(0, 0, 0, 0.34);
            overflow: hidden;
        }

        .admin-tabs {
            display: flex;
            gap: 1px;
            background: rgba(18, 51, 78, 0.6);
            border-bottom: 1px solid rgba(64, 139, 198, 0.38);
        }

        .admin-tab {
            flex: 1;
            min-height: 52px;
            border: 0;
            color: #b9d3ef;
            background: rgba(13, 29, 43, 0.9);
            cursor: pointer;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .admin-tab.is-active {
            color: #ffffff;
            background: linear-gradient(180deg, #0e5fba 0%, #114990 100%);
        }

        .admin-panel {
            display: none;
            padding: 22px;
        }

        .admin-panel.is-active {
            display: block;
        }

        .admin-grid {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 20px;
        }

        .admin-box {
            border: 1px solid rgba(64, 139, 198, 0.36);
            border-radius: 8px;
            background: #101a24;
            padding: 18px;
        }

        .admin-box h2,
        .admin-box h3 {
            margin: 0 0 12px;
            color: #ffffff;
        }

        .admin-box p {
            margin: 0 0 16px;
            color: #9eb9d7;
            line-height: 1.5;
        }

        .admin-form-row {
            display: flex;
            gap: 10px;
            align-items: stretch;
            margin-bottom: 12px;
        }

        .admin-input {
            width: 100%;
            min-height: 42px;
            padding: 0 12px;
            border: 1px solid rgba(64, 139, 198, 0.52);
            border-radius: 5px;
            color: #d7e8ff;
            background: #07111b;
            outline: none;
        }

        .admin-input:focus {
            border-color: #118cff;
            box-shadow: 0 0 0 2px rgba(17, 140, 255, 0.18);
        }

        select.admin-input option {
            color: #d7e8ff;
            background: #07111b;
        }

        .admin-device-select {
            flex: 1;
            display: grid;
            gap: 6px;
            color: #9eb9d7;
        }

        .admin-button {
            min-height: 42px;
            padding: 0 16px;
            border: 1px solid rgba(64, 139, 198, 0.62);
            border-radius: 5px;
            color: #ffffff;
            background: #16324c;
            cursor: pointer;
            white-space: nowrap;
        }

        .admin-button.primary {
            border-color: #118cff;
            background: linear-gradient(180deg, #126ed6 0%, #0d4fa0 100%);
        }

        .admin-button:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .frequency-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-height: 34px;
            margin-top: 12px;
        }

        .frequency-chip {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 7px 9px;
            border: 1px solid rgba(17, 140, 255, 0.55);
            border-radius: 5px;
            background: rgba(17, 140, 255, 0.12);
            color: #d7e8ff;
        }

        .frequency-chip button {
            border: 0;
            color: #85d7ff;
            background: transparent;
            cursor: pointer;
        }

        .monitor-table {
            width: 100%;
            border-collapse: collapse;
        }

        .monitor-table th,
        .monitor-table td {
            padding: 10px 9px;
            border-bottom: 1px solid rgba(64, 139, 198, 0.2);
            text-align: left;
            vertical-align: top;
            color: #c9ddf2;
        }

        .monitor-table th {
            color: #76bfff;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .monitor-table .frequency {
            color: #00ffcc;
            font-weight: 700;
        }

        .monitor-table .sender {
            color: #55aaff;
            font-weight: 700;
        }

        .monitor-table .announcement {
            color: #ff6f6f;
            font-weight: 700;
        }

        .activity-list {
            display: grid;
            gap: 12px;
        }

        .activity-item {
            border: 1px solid rgba(64, 139, 198, 0.28);
            border-radius: 8px;
            padding: 13px 15px;
            background: rgba(7, 17, 27, 0.88);
        }

        .activity-top {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 8px;
            color: #ffffff;
            font-weight: 700;
        }

        .activity-meta {
            color: #8fb3d5;
            font-size: 13px;
            line-height: 1.5;
        }

        .empty-state {
            padding: 24px;
            text-align: center;
            color: #8fb3d5;
            border: 1px dashed rgba(64, 139, 198, 0.35);
            border-radius: 8px;
        }

        .voice-status {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .voice-status div {
            padding: 14px;
            border: 1px solid rgba(64, 139, 198, 0.28);
            border-radius: 8px;
            background: rgba(7, 17, 27, 0.88);
        }

        .voice-levels {
            display: grid;
            gap: 10px;
            margin: 12px 0;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .voice-meter {
            padding: 12px;
            border: 1px solid rgba(64, 139, 198, 0.28);
            border-radius: 8px;
            background: #07111b;
        }

        .voice-meter-label {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            color: #9eb9d7;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .voice-meter-track {
            height: 14px;
            overflow: hidden;
            border: 1px solid rgba(64, 139, 198, 0.38);
            border-radius: 3px;
            background: repeating-linear-gradient(
                90deg,
                #111a23 0,
                #111a23 10px,
                #07111b 10px,
                #07111b 13px
            );
        }

        .voice-meter-fill {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #36ff78 0%, #b7fff2 74%, #ffdc54 100%);
            transition: width 80ms linear;
        }

        .voice-meter.is-active .voice-meter-label {
            color: #00ffcc;
        }

        #voiceTransmitButton.is-transmitting {
            border-color: #ff5252;
            background: linear-gradient(180deg, #d73333 0%, #8d1818 100%);
        }

        .notice {
            padding: 18px;
            border: 1px solid rgba(255, 89, 89, 0.45);
            border-radius: 8px;
            color: #ffd5d5;
            background: rgba(91, 18, 18, 0.42);
        }

        @media (max-width: 1050px) {
            .admin-grid,
            .voice-status,
            .voice-levels {
                grid-template-columns: 1fr;
            }

            .admin-hero {
                align-items: flex-start;
                flex-direction: column;
            }

            .admin-tabs {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="admin-shell">
    <?php if ($loginRequired): ?>
            <div class="notice">
                <?php echo htmlspecialchars(t('admin_login_required')); ?>
            </div>
    <?php elseif ($accessDenied): ?>
        <div class="notice">
            <?php echo htmlspecialchars(t('admin_access_denied')); ?>
        </div>
    <?php else: ?>
        <section class="admin-hero">
            <div>
                <h1 class="admin-title"><?php echo htmlspecialchars(t('admin_title')); ?></h1>
                <p class="admin-subtitle"><?php echo htmlspecialchars(t('admin_subtitle')); ?></p>
            </div>
            <div class="admin-badge">
                OP-Level <?php echo (int)$adminOpPermission; ?>
            </div>
        </section>

        <section class="admin-card">
            <div class="admin-tabs" role="tablist">
                <button class="admin-tab is-active" type="button" data-tab="chat">
                    <?php echo htmlspecialchars(t('admin_tab_chat')); ?>
                </button>
                <button class="admin-tab" type="button" data-tab="activity">
                    <?php echo htmlspecialchars(t('admin_tab_activity')); ?>
                </button>
                <button class="admin-tab" type="button" data-tab="voice">
                    <?php echo htmlspecialchars(t('admin_tab_voice')); ?>
                </button>
            </div>

            <div class="admin-panel is-active" id="admin-panel-chat">
                <div class="admin-grid">
                    <aside class="admin-box">
                        <h2><?php echo htmlspecialchars(t('admin_monitor_title')); ?></h2>
                        <p><?php echo htmlspecialchars(t('admin_monitor_text')); ?></p>

                        <label for="frequencyInput"><?php echo htmlspecialchars(t('admin_frequency')); ?></label>
                        <div class="admin-form-row">
                            <input id="frequencyInput"
                                   class="admin-input"
                                   type="text"
                                   inputmode="decimal"
                                   placeholder="122.800">
                            <button class="admin-button primary" type="button" id="addFrequencyButton">
                                <?php echo htmlspecialchars(t('admin_add_frequency')); ?>
                            </button>
                        </div>

                        <div class="admin-form-row">
                            <button class="admin-button" type="button" id="addUnicomButton">
                                <?php echo htmlspecialchars(t('admin_add_unicom')); ?>
                            </button>
                            <button class="admin-button"
                                    type="button"
                                    id="toggleAllButton"
                                    <?php echo $canViewAllFrequencies ? '' : 'disabled'; ?>>
                                <?php echo htmlspecialchars(t('admin_all_frequencies')); ?>
                            </button>
                        </div>

                        <?php if (!$canViewAllFrequencies): ?>
                            <p><?php echo htmlspecialchars(t('admin_permission_all_required')); ?></p>
                        <?php endif; ?>

                        <h3><?php echo htmlspecialchars(t('admin_monitored_frequencies')); ?></h3>
                        <div class="frequency-list" id="frequencyList"></div>
                    </aside>

                    <section class="admin-box">
                        <h2><?php echo htmlspecialchars(t('admin_live_messages')); ?></h2>
                        <div id="messageEmpty" class="empty-state">
                            <?php echo htmlspecialchars(t('admin_no_messages')); ?>
                        </div>
                        <table class="monitor-table" id="messageTable" hidden>
                            <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('admin_time')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_frequency')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_sender')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_type')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_message')); ?></th>
                            </tr>
                            </thead>
                            <tbody id="messageRows"></tbody>
                        </table>
                    </section>
                </div>
            </div>

            <div class="admin-panel" id="admin-panel-activity">
                <div class="admin-box">
                    <h2><?php echo htmlspecialchars(t('admin_staff_activity_title')); ?></h2>
                    <p><?php echo htmlspecialchars(t('admin_staff_activity_text')); ?></p>
                    <div id="activityList" class="activity-list">
                        <div class="empty-state"><?php echo htmlspecialchars(t('admin_loading')); ?></div>
                    </div>
                </div>
            </div>

            <div class="admin-panel" id="admin-panel-voice">
                <div class="admin-box">
                    <h2><?php echo htmlspecialchars(t('admin_voice_title')); ?></h2>
                    <p><?php echo htmlspecialchars(t('admin_voice_text')); ?></p>

                    <div class="admin-form-row">
                        <input id="voiceFrequencyInput"
                               class="admin-input"
                               type="text"
                               inputmode="decimal"
                               placeholder="122.800">
                        <button class="admin-button primary" type="button" id="voiceConnectButton">
                            <?php echo htmlspecialchars(t('admin_voice_connect')); ?>
                        </button>
                    </div>

                    <div class="admin-form-row">
                        <label class="admin-device-select">
                            <span><?php echo htmlspecialchars(t('admin_voice_input_device')); ?></span>
                            <select id="voiceInputDeviceSelect" class="admin-input">
                                <option value=""><?php echo htmlspecialchars(t('admin_voice_device_default')); ?></option>
                            </select>
                        </label>
                        <label class="admin-device-select">
                            <span><?php echo htmlspecialchars(t('admin_voice_output_device')); ?></span>
                            <select id="voiceOutputDeviceSelect" class="admin-input">
                                <option value=""><?php echo htmlspecialchars(t('admin_voice_device_default')); ?></option>
                            </select>
                        </label>
                    </div>

                    <div class="admin-form-row">
                        <button class="admin-button" type="button" id="voiceRefreshDevicesButton">
                            <?php echo htmlspecialchars(t('admin_voice_refresh_devices')); ?>
                        </button>
                        <button class="admin-button primary" type="button" id="voiceTransmitButton">
                            <?php echo htmlspecialchars(t('admin_voice_push_to_talk')); ?>
                        </button>
                    </div>

                    <div class="voice-levels">
                        <div class="voice-meter" id="voiceTxMeter">
                            <div class="voice-meter-label">
                                <span><?php echo htmlspecialchars(t('admin_voice_tx_level')); ?></span>
                                <span id="voiceTxPercent">0%</span>
                            </div>
                            <div class="voice-meter-track">
                                <div class="voice-meter-fill" id="voiceTxFill"></div>
                            </div>
                        </div>
                        <div class="voice-meter" id="voiceRxMeter">
                            <div class="voice-meter-label">
                                <span><?php echo htmlspecialchars(t('admin_voice_rx_level')); ?></span>
                                <span id="voiceRxPercent">0%</span>
                            </div>
                            <div class="voice-meter-track">
                                <div class="voice-meter-fill" id="voiceRxFill"></div>
                            </div>
                        </div>
                    </div>

                    <div class="voice-status">
                        <div>
                            <strong><?php echo htmlspecialchars(t('admin_voice_frequency')); ?></strong><br>
                            <span id="voiceFrequencyStatus">-</span>
                        </div>
                        <div>
                            <strong><?php echo htmlspecialchars(t('admin_voice_receiver')); ?></strong><br>
                            <span id="voiceReceiverStatus"><?php echo htmlspecialchars(t('admin_voice_browser_ready')); ?></span>
                        </div>
                        <div>
                            <strong><?php echo htmlspecialchars(t('admin_voice_service')); ?></strong><br>
                            <span><?php echo htmlspecialchars(t('admin_voice_placeholder')); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php require_once __DIR__ . '/includes/auth_modals.php'; ?>

<?php if (!$loginRequired && !$accessDenied): ?>
<script>
    const ADMIN_I18N = <?php echo json_encode([
        'noFrequencies' => t('admin_no_frequencies'),
        'invalidFrequency' => t('admin_invalid_frequency'),
        'noMessages' => t('admin_no_messages'),
        'serverError' => t('admin_server_error'),
        'noActivity' => t('admin_no_activity'),
        'allFrequenciesActive' => t('admin_all_frequencies_active'),
        'deviceDefault' => t('admin_voice_device_default'),
        'deviceInputPrefix' => t('admin_voice_device_input_prefix'),
        'deviceOutputPrefix' => t('admin_voice_device_output_prefix'),
        'devicePermissionHint' => t('admin_voice_device_permission_hint'),
        'voicePrepared' => t('admin_voice_browser_ready'),
        'voiceTransmitPrepared' => t('admin_voice_transmit_prepared')
    ], JSON_UNESCAPED_UNICODE); ?>;

    const CAN_VIEW_ALL_FREQUENCIES =
        <?php echo $canViewAllFrequencies ? 'true' : 'false'; ?>;

    let monitoredFrequencies =
        JSON.parse(localStorage.getItem('vfn_admin_monitor_frequencies') || '["122.800"]');

    let monitorAllFrequencies =
        localStorage.getItem('vfn_admin_monitor_all') === '1' && CAN_VIEW_ALL_FREQUENCIES;

    let lastMessageId = 0;
    let activityLoaded = false;
    let voiceAudioContext = null;
    let voiceAnalyser = null;
    let voiceLevelData = null;
    let voiceMediaStream = null;
    let voiceMeterAnimation = null;
    let voiceTransmitting = false;

    function normalizeFrequency(value)
    {
        const cleaned = String(value || '').trim().replace(',', '.');
        const number = Number(cleaned);

        if (!Number.isFinite(number) || number < 118 || number > 136.975) {
            return null;
        }

        return number.toFixed(3);
    }

    function saveMonitorState()
    {
        localStorage.setItem(
            'vfn_admin_monitor_frequencies',
            JSON.stringify(monitoredFrequencies)
        );
        localStorage.setItem(
            'vfn_admin_monitor_all',
            monitorAllFrequencies ? '1' : '0'
        );
    }

    function renderFrequencies()
    {
        const list = document.getElementById('frequencyList');
        list.innerHTML = '';

        if (monitorAllFrequencies) {
            const chip = document.createElement('span');
            chip.className = 'frequency-chip';
            chip.textContent = ADMIN_I18N.allFrequenciesActive;
            list.appendChild(chip);
            return;
        }

        if (monitoredFrequencies.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'empty-state';
            empty.textContent = ADMIN_I18N.noFrequencies;
            list.appendChild(empty);
            return;
        }

        monitoredFrequencies.forEach(function(frequency) {
            const chip = document.createElement('span');
            chip.className = 'frequency-chip';
            chip.textContent = frequency;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = 'x';
            remove.addEventListener('click', function() {
                monitoredFrequencies =
                    monitoredFrequencies.filter(item => item !== frequency);
                lastMessageId = 0;
                document.getElementById('messageRows').innerHTML = '';
                saveMonitorState();
                renderFrequencies();
                pollMessages();
            });

            chip.appendChild(remove);
            list.appendChild(chip);
        });
    }

    function appendMessages(messages)
    {
        const table = document.getElementById('messageTable');
        const empty = document.getElementById('messageEmpty');
        const rows = document.getElementById('messageRows');

        const sortedMessages =
            messages
                .slice()
                .sort(function(a, b) {
                    return Number(a.id) - Number(b.id);
                });

        sortedMessages.forEach(function(message) {
            lastMessageId = Math.max(lastMessageId, Number(message.id));

            const row = document.createElement('tr');
            const typeClass =
                String(message.text || '').indexOf('[ANNOUNCEMENT]') === 0
                    ? 'announcement'
                    : '';

            row.innerHTML =
                '<td>' + escapeHtml(message.time) + '</td>' +
                '<td class="frequency">' + escapeHtml(message.frequency) + '</td>' +
                '<td class="sender">' + escapeHtml(message.sender) + '</td>' +
                '<td>' + escapeHtml(message.type) + '</td>' +
                '<td class="' + typeClass + '">' + escapeHtml(message.text) + '</td>';

            rows.insertBefore(row, rows.firstChild);
        });

        while (rows.children.length > 180) {
            rows.removeChild(rows.lastChild);
        }

        table.hidden =
            rows.children.length === 0;

        empty.hidden =
            rows.children.length !== 0;
    }

    async function pollMessages()
    {
        if (!monitorAllFrequencies && monitoredFrequencies.length === 0) {
            return;
        }

        const params = new URLSearchParams();
        params.set('since_id', String(lastMessageId));
        params.set('frequencies', monitoredFrequencies.join(','));

        if (monitorAllFrequencies) {
            params.set('all', '1');
        }

        try {
            const response =
                await fetch('execute/admin_chat_monitor.php?' + params.toString());

            const data =
                await response.json();

            if (data.success) {
                appendMessages(data.messages || [], lastMessageId === 0);
            }
        } catch (error) {
            console.warn(ADMIN_I18N.serverError, error);
        }
    }

    async function loadActivities()
    {
        const list = document.getElementById('activityList');

        try {
            const response =
                await fetch('execute/admin_staff_activity.php');

            const data =
                await response.json();

            list.innerHTML = '';

            if (!data.success || !Array.isArray(data.items) || data.items.length === 0) {
                list.innerHTML = '<div class="empty-state">' + escapeHtml(ADMIN_I18N.noActivity) + '</div>';
                return;
            }

            data.items.forEach(function(item) {
                const node = document.createElement('div');
                node.className = 'activity-item';
                node.innerHTML =
                    '<div class="activity-top">' +
                    '<span>' + escapeHtml(item.title) + '</span>' +
                    '<span>' + escapeHtml(item.time) + '</span>' +
                    '</div>' +
                    '<div class="activity-meta">' +
                    escapeHtml(item.actor) + ' -> ' + escapeHtml(item.target) +
                    (item.detail ? '<br>' + escapeHtml(item.detail) : '') +
                    '</div>';
                list.appendChild(node);
            });
        } catch (error) {
            list.innerHTML = '<div class="empty-state">' + escapeHtml(ADMIN_I18N.serverError) + '</div>';
        }
    }

    function escapeHtml(value)
    {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.querySelectorAll('.admin-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            const tabName =
                tab.dataset.tab;

            document.querySelectorAll('.admin-tab').forEach(item => item.classList.remove('is-active'));
            document.querySelectorAll('.admin-panel').forEach(item => item.classList.remove('is-active'));

            tab.classList.add('is-active');
            document.getElementById('admin-panel-' + tabName).classList.add('is-active');

            if (tabName === 'activity' && !activityLoaded) {
                activityLoaded = true;
                loadActivities();
            }
        });
    });

    document.getElementById('addFrequencyButton').addEventListener('click', function() {
        const input = document.getElementById('frequencyInput');
        const frequency = normalizeFrequency(input.value);

        if (!frequency) {
            alert(ADMIN_I18N.invalidFrequency);
            return;
        }

        monitorAllFrequencies = false;

        if (!monitoredFrequencies.includes(frequency)) {
            monitoredFrequencies.push(frequency);
        }

        input.value = '';
        lastMessageId = 0;
        document.getElementById('messageRows').innerHTML = '';
        saveMonitorState();
        renderFrequencies();
        pollMessages();
    });

    document.getElementById('addUnicomButton').addEventListener('click', function() {
        monitorAllFrequencies = false;

        if (!monitoredFrequencies.includes('122.800')) {
            monitoredFrequencies.push('122.800');
        }

        lastMessageId = 0;
        document.getElementById('messageRows').innerHTML = '';
        saveMonitorState();
        renderFrequencies();
        pollMessages();
    });

    document.getElementById('toggleAllButton').addEventListener('click', function() {
        if (!CAN_VIEW_ALL_FREQUENCIES) {
            return;
        }

        monitorAllFrequencies =
            !monitorAllFrequencies;

        lastMessageId = 0;
        document.getElementById('messageRows').innerHTML = '';
        saveMonitorState();
        renderFrequencies();
        pollMessages();
    });

    async function refreshVoiceDevices()
    {
        const inputSelect =
            document.getElementById('voiceInputDeviceSelect');

        const outputSelect =
            document.getElementById('voiceOutputDeviceSelect');

        function resetDeviceSelects()
        {
            inputSelect.innerHTML =
                '<option value="">' + escapeHtml(ADMIN_I18N.deviceDefault) + '</option>';
            outputSelect.innerHTML =
                '<option value="">' + escapeHtml(ADMIN_I18N.deviceDefault) + '</option>';
        }

        function appendDeviceOptions(devices)
        {
            resetDeviceSelects();

            let inputCount =
                0;
            let outputCount =
                0;

            devices.forEach(function(device) {
                if (device.kind === 'audioinput') {
                    inputCount++;

                    const option =
                        document.createElement('option');

                    option.value =
                        device.deviceId;

                    option.textContent =
                        ADMIN_I18N.deviceInputPrefix + ' ' +
                        (device.label || (ADMIN_I18N.deviceDefault + ' ' + inputCount));

                    inputSelect.appendChild(option);
                    return;
                }

                if (device.kind === 'audiooutput') {
                    outputCount++;

                    const option =
                        document.createElement('option');

                    option.value =
                        device.deviceId;

                    option.textContent =
                        ADMIN_I18N.deviceOutputPrefix + ' ' +
                        (device.label || (ADMIN_I18N.deviceDefault + ' ' + outputCount));

                    outputSelect.appendChild(option);
                }
            });

            return inputCount + outputCount;
        }

        resetDeviceSelects();

        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
                document.getElementById('voiceReceiverStatus').textContent =
                    ADMIN_I18N.devicePermissionHint;
                return;
            }

            let devices =
                await navigator.mediaDevices.enumerateDevices();

            let deviceCount =
                appendDeviceOptions(devices);

            try {
                const permissionStream =
                    await navigator.mediaDevices.getUserMedia({ audio: true });

                permissionStream.getTracks().forEach(function(track) {
                    track.stop();
                });

                devices =
                    await navigator.mediaDevices.enumerateDevices();

                deviceCount =
                    appendDeviceOptions(devices);
            } catch (permissionError) {
                if (deviceCount === 0) {
                    document.getElementById('voiceReceiverStatus').textContent =
                        ADMIN_I18N.devicePermissionHint;
                    return;
                }
            }

            document.getElementById('voiceReceiverStatus').textContent =
                ADMIN_I18N.voicePrepared;
        } catch (error) {
            document.getElementById('voiceReceiverStatus').textContent =
                ADMIN_I18N.devicePermissionHint;
        }
    }

    function setVoiceMeter(kind, value, active)
    {
        const percent =
            Math.max(0, Math.min(100, Math.round(value)));

        const fill =
            document.getElementById(kind === 'tx' ? 'voiceTxFill' : 'voiceRxFill');

        const text =
            document.getElementById(kind === 'tx' ? 'voiceTxPercent' : 'voiceRxPercent');

        const meter =
            document.getElementById(kind === 'tx' ? 'voiceTxMeter' : 'voiceRxMeter');

        fill.style.width =
            percent + '%';
        text.textContent =
            percent + '%';
        meter.classList.toggle('is-active', !!active);
    }

    function stopVoiceInputMonitor()
    {
        if (voiceMeterAnimation) {
            cancelAnimationFrame(voiceMeterAnimation);
            voiceMeterAnimation = null;
        }

        if (voiceMediaStream) {
            voiceMediaStream.getTracks().forEach(function(track) {
                track.stop();
            });
            voiceMediaStream = null;
        }

        voiceAnalyser = null;
        voiceLevelData = null;
        setVoiceMeter('tx', 0, false);
    }

    function updateVoiceInputMeter()
    {
        if (!voiceAnalyser || !voiceLevelData) {
            return;
        }

        voiceAnalyser.getByteTimeDomainData(voiceLevelData);

        let sum =
            0;

        for (let index = 0; index < voiceLevelData.length; index++) {
            const centered =
                voiceLevelData[index] - 128;
            sum +=
                centered * centered;
        }

        const rms =
            Math.sqrt(sum / voiceLevelData.length);

        const level =
            Math.min(100, rms * 5.5);

        setVoiceMeter(
            'tx',
            voiceTransmitting ? level : 0,
            voiceTransmitting
        );

        voiceMeterAnimation =
            requestAnimationFrame(updateVoiceInputMeter);
    }

    async function startVoiceInputMonitor()
    {
        stopVoiceInputMonitor();

        const selectedInput =
            document.getElementById('voiceInputDeviceSelect').value;

        const constraints = {
            audio: selectedInput
                ? { deviceId: { exact: selectedInput } }
                : true
        };

        voiceMediaStream =
            await navigator.mediaDevices.getUserMedia(constraints);

        voiceAudioContext =
            voiceAudioContext || new (window.AudioContext || window.webkitAudioContext)();

        if (voiceAudioContext.state === 'suspended') {
            await voiceAudioContext.resume();
        }

        const source =
            voiceAudioContext.createMediaStreamSource(voiceMediaStream);

        voiceAnalyser =
            voiceAudioContext.createAnalyser();
        voiceAnalyser.fftSize =
            256;
        voiceLevelData =
            new Uint8Array(voiceAnalyser.frequencyBinCount);

        source.connect(voiceAnalyser);
        updateVoiceInputMeter();
    }

    async function setVoiceTransmitting(active)
    {
        const button =
            document.getElementById('voiceTransmitButton');

        voiceTransmitting =
            active;

        button.classList.toggle('is-transmitting', active);

        if (active) {
            try {
                await startVoiceInputMonitor();
                document.getElementById('voiceReceiverStatus').textContent =
                    ADMIN_I18N.voiceTransmitPrepared;
            } catch (error) {
                voiceTransmitting = false;
                button.classList.remove('is-transmitting');
                document.getElementById('voiceReceiverStatus').textContent =
                    ADMIN_I18N.devicePermissionHint;
            }
            return;
        }

        stopVoiceInputMonitor();
    }

    document.getElementById('voiceRefreshDevicesButton').addEventListener('click', refreshVoiceDevices);

    document.getElementById('voiceConnectButton').addEventListener('click', function() {
        const frequency =
            normalizeFrequency(document.getElementById('voiceFrequencyInput').value);

        if (!frequency) {
            alert(ADMIN_I18N.invalidFrequency);
            return;
        }

        document.getElementById('voiceFrequencyStatus').textContent =
            frequency;
    });

    const voiceTransmitButton =
        document.getElementById('voiceTransmitButton');

    voiceTransmitButton.addEventListener('mousedown', function() {
        setVoiceTransmitting(true);
    });
    voiceTransmitButton.addEventListener('mouseup', function() {
        setVoiceTransmitting(false);
    });
    voiceTransmitButton.addEventListener('mouseleave', function() {
        setVoiceTransmitting(false);
    });
    voiceTransmitButton.addEventListener('touchstart', function(event) {
        event.preventDefault();
        setVoiceTransmitting(true);
    });
    voiceTransmitButton.addEventListener('touchend', function(event) {
        event.preventDefault();
        setVoiceTransmitting(false);
    });

    renderFrequencies();
    pollMessages();
    refreshVoiceDevices();
    setInterval(pollMessages, 3000);
</script>
<?php endif; ?>
</body>
</html>
