<?php
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';

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

    if (
        empty($_SESSION['web_user_id'])
        || !validateVfnWebSession($pdo)
    ) {
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

$adminTodoLines = [];
if ($adminOpPermission >= 5) {
    $todoPath = __DIR__ . '/VFN_Master_TODO.md';
    $loadedTodoLines = @file($todoPath, FILE_IGNORE_NEW_LINES);
    if (is_array($loadedTodoLines)) {
        $adminTodoLines = $loadedTodoLines;
    }
}

function renderAdminTodoInline(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return preg_replace(
        '/`([^`]+)`/',
        '<code>$1</code>',
        $escaped
    ) ?? $escaped;
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$voiceSessionToken =
    '';

if (!$loginRequired && !$accessDenied && $pdo instanceof PDO && $adminUser) {
    try {
        $voiceSessionToken =
            (string)($_SESSION['web_voice_token'] ?? '');

        if ($voiceSessionToken !== '') {
            $voiceSessionStmt =
                $pdo->prepare(
                    "SELECT token
                     FROM user_sessions
                     WHERE user_id = :user_id
                       AND token = :token
                       AND is_active = 1
                     LIMIT 1"
                );

            $voiceSessionStmt->execute([
                'user_id' => (int)$adminUser['id'],
                'token' => $voiceSessionToken
            ]);

            $voiceSessionToken =
                (string)($voiceSessionStmt->fetchColumn() ?: '');
        }

        if ($voiceSessionToken === '') {
            $voiceSessionToken =
                bin2hex(random_bytes(32));

            $voiceCallsign =
                strtoupper(preg_replace(
                    '/[^A-Z0-9_]/',
                    '',
                    (string)($adminUser['username'] ?? 'WEBSTAFF')
                ));

            if ($voiceCallsign === '') {
                $voiceCallsign =
                    'WEBSTAFF';
            }

            $voiceSessionStmt =
                $pdo->prepare(
                    "INSERT INTO user_sessions
                        (
                            user_id,
                            token,
                            callsign,
                            is_active,
                            is_invisible
                        )
                     VALUES
                        (
                            :user_id,
                            :token,
                            :callsign,
                            1,
                            1
                        )"
                );

            $voiceSessionStmt->execute([
                'user_id' => (int)$adminUser['id'],
                'token' => $voiceSessionToken,
                'callsign' => $voiceCallsign
            ]);

            $_SESSION['web_voice_token'] =
                $voiceSessionToken;
        }
    } catch (Throwable $e) {
        $voiceSessionToken =
            '';
    }
}
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
            flex-wrap: wrap;
            gap: 1px;
            background: rgba(18, 51, 78, 0.6);
            border-bottom: 1px solid rgba(64, 139, 198, 0.38);
        }

        .admin-tab {
            flex: 1 1 145px;
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

        .todo-document {
            display: grid;
            gap: 7px;
            max-height: 72vh;
            overflow: auto;
            padding-right: 8px;
        }

        .todo-document h1,
        .todo-document h2,
        .todo-document h3,
        .todo-document h4 {
            margin: 22px 0 5px;
            color: #ffffff;
        }

        .todo-document h1:first-child {
            margin-top: 0;
        }

        .todo-line {
            color: #b9d3ef;
            line-height: 1.55;
        }

        .todo-check {
            display: flex;
            gap: 9px;
            align-items: flex-start;
            padding: 6px 9px;
            border-radius: 6px;
            background: rgba(8, 25, 38, 0.72);
        }

        .todo-check.is-complete {
            color: #79cfae;
        }

        .todo-check.is-partial {
            color: #ffd27b;
        }

        .todo-marker {
            flex: 0 0 20px;
            text-align: center;
            font-weight: 800;
            color: #5baeff;
        }

        .todo-document code {
            color: #7fd9ff;
            background: #07131e;
            padding: 2px 5px;
            border-radius: 4px;
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

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .filter-grid.players {
            grid-template-columns: 2fr repeat(4, minmax(130px, 1fr));
        }

        .filter-field {
            display: grid;
            gap: 5px;
            color: #9eb9d7;
            font-size: 12px;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        .player-status {
            display: inline-block;
            padding: 4px 7px;
            border-radius: 999px;
            color: #ffb8b8;
            background: rgba(180, 35, 35, 0.22);
            white-space: nowrap;
        }

        .player-status.active {
            color: #7dffb0;
            background: rgba(23, 155, 82, 0.20);
        }

        .result-count {
            margin: 0 0 12px;
            color: #8fb3d5;
        }

        .player-profile-link {
            color: #39a7ff;
            text-decoration: none;
        }

        .player-profile-link:hover,
        .player-profile-link:focus {
            color: #00f5c4;
            text-decoration: underline;
        }

        .filtered-chat-word {
            color: #ff515f;
            font-weight: 800;
            background: rgba(255, 40, 58, 0.12);
            border-radius: 3px;
            padding: 0 2px;
        }

        select.admin-input option {
            color: #d7e8ff;
            background: #07111b !important;
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

        .chat-translate-button {
            margin-top: 7px;
            padding: 5px 9px;
            min-height: 0;
            font-size: 12px;
            line-height: 1.2;
        }

        .chat-auto-translation {
            margin-top: 7px;
            padding: 7px 9px;
            border-left: 3px solid #29a8ff;
            color: #bfe3ff;
            background: rgba(20, 91, 145, 0.18);
            font-size: 13px;
            line-height: 1.35;
        }

        .chat-auto-translation strong {
            color: #65c7ff;
        }

        .admin-button.primary {
            border-color: #118cff;
            background: linear-gradient(180deg, #126ed6 0%, #0d4fa0 100%);
        }

        .admin-button:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .voice-continuous-option {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-height: 42px;
            padding: 0 13px;
            border: 1px solid rgba(64, 139, 198, 0.45);
            border-radius: 5px;
            color: #c9ddf2;
            background: rgba(7, 22, 35, 0.72);
            cursor: pointer;
            user-select: none;
        }

        .voice-continuous-option input {
            width: 17px;
            height: 17px;
            accent-color: #118cff;
        }

        .voice-test-source {
            margin-top: 22px;
            padding: 18px;
            border: 1px solid rgba(17, 140, 255, 0.38);
            border-radius: 7px;
            background: rgba(4, 17, 29, 0.55);
        }

        .voice-test-source h3 { margin: 0 0 8px; }
        .voice-test-source .filter-grid { margin-top: 14px; }
        .voice-test-source-status { margin: 12px 0 0; color: #9eb9d7; }

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

            .filter-grid,
            .filter-grid.players {
                grid-template-columns: 1fr;
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
        <p>
            <?php if ($adminOpPermission >= 4): ?>
                <a class="admin-button" href="admin_history.php"><?php echo htmlspecialchars(t('admin_private_history')); ?></a>
            <?php endif; ?>
            <a class="admin-button" href="flightplans.php"><?php echo htmlspecialchars(t('nav_flightplans')); ?></a>
            <a class="admin-button" href="moderation.php"><?php echo htmlspecialchars(t('moderation_center_title')); ?></a>
            <a class="admin-button" href="bug_reports.php"><?php echo htmlspecialchars(t('bug_staff_queue')); ?><?php if (($pendingBugReportCount ?? 0) > 0): ?> (<?php echo (int)$pendingBugReportCount; ?>)<?php endif; ?></a>
            <?php if ($adminOpPermission >= 1): ?><a class="admin-button" href="admin_gca.php"><?php echo htmlspecialchars(t('gca_admin_title')); ?></a><?php endif; ?>
            <?php if ($adminOpPermission >= 3): ?><a class="admin-button" href="admin_divisions.php"><?php echo htmlspecialchars(t('division_admin_title')); ?></a><?php endif; ?>
            <?php if ($adminOpPermission >= 5): ?><a class="admin-button" href="system_status.php"><?php echo htmlspecialchars(t('system_status_title')); ?></a><?php endif; ?>
        </p>

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
                <button class="admin-tab" type="button" data-tab="players">
                    <?php echo htmlspecialchars(t('admin_tab_players')); ?>
                </button>
                <button class="admin-tab" type="button" data-tab="transfers">
                    <?php echo htmlspecialchars(t('admin_tab_transfers')); ?>
                    <?php if (($pendingDivisionTransferCount ?? 0) > 0): ?>
                        <span class="header-notification-dot" id="transferTabDot"></span>
                    <?php endif; ?>
                </button>
                <?php if ($adminOpPermission >= 4): ?>
                    <button class="admin-tab" type="button" data-tab="moderation">
                        <?php echo htmlspecialchars(t('admin_tab_moderation')); ?>
                        <?php if (($pendingBanAppealCount ?? 0) > 0): ?>
                            <span class="header-notification-dot" id="moderationTabDot"></span>
                        <?php endif; ?>
                    </button>
                    <button class="admin-tab" type="button" data-tab="chat-filter">
                        <?php echo htmlspecialchars(t('admin_tab_chat_filter')); ?>
                    </button>
                <?php endif; ?>
                <?php if ($adminOpPermission >= 5): ?>
                    <button class="admin-tab" type="button" data-tab="configuration">
                        <?php echo htmlspecialchars(t('admin_tab_configuration')); ?>
                    </button>
                    <button class="admin-tab" type="button" data-tab="database-reset">
                        <?php echo htmlspecialchars(t('admin_tab_database_reset')); ?>
                    </button>
                    <button class="admin-tab" type="button" data-tab="todo">
                        <?php echo htmlspecialchars(t('admin_tab_todo')); ?>
                    </button>
                <?php endif; ?>
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

                        <h3><?php echo htmlspecialchars(t('admin_staff_chat_title')); ?></h3>
                        <label for="staffChatFrequency"><?php echo htmlspecialchars(t('admin_frequency')); ?></label>
                        <input id="staffChatFrequency" class="admin-input" type="text" inputmode="decimal" value="122.800">
                        <label for="staffChatScope"><?php echo htmlspecialchars(t('admin_staff_chat_scope')); ?></label>
                        <select id="staffChatScope" class="admin-input">
                            <option value="global"><?php echo htmlspecialchars(t('admin_staff_chat_scope_global')); ?></option>
                            <option value="regional"><?php echo htmlspecialchars(t('admin_staff_chat_scope_regional')); ?></option>
                        </select>
                        <div id="staffChatRegionFields" hidden>
                            <label for="staffChatReferencePilot"><?php echo htmlspecialchars(t('admin_staff_chat_reference')); ?></label>
                            <select id="staffChatReferencePilot" class="admin-input"></select>
                            <label for="staffChatRange"><?php echo htmlspecialchars(t('admin_staff_chat_range')); ?></label>
                            <input id="staffChatRange" class="admin-input" type="number" min="10" max="1000" value="200">
                        </div>
                        <label for="staffChatMessage"><?php echo htmlspecialchars(t('admin_message')); ?></label>
                        <textarea id="staffChatMessage" class="admin-input" maxlength="255" rows="4"></textarea>
                        <button class="admin-button primary" type="button" id="staffChatSendButton">
                            <?php echo htmlspecialchars(t('admin_staff_chat_send')); ?>
                        </button>
                        <p id="staffChatStatus"></p>

                        <?php if ($adminOpPermission >= 5): ?>
                            <h3><?php echo htmlspecialchars(t('admin_announcement_title')); ?></h3>
                            <p><?php echo htmlspecialchars(t('admin_announcement_text')); ?></p>
                            <label for="adminAnnouncementMessage">
                                <?php echo htmlspecialchars(t('admin_message')); ?>
                            </label>
                            <textarea id="adminAnnouncementMessage"
                                      class="admin-input"
                                      maxlength="220"
                                      rows="4"></textarea>
                            <button class="admin-button primary"
                                    type="button"
                                    id="adminAnnouncementSendButton">
                                <?php echo htmlspecialchars(t('admin_announcement_send')); ?>
                            </button>
                            <p id="adminAnnouncementStatus"></p>
                        <?php endif; ?>
                    </aside>

                    <section class="admin-box">
                        <h2><?php echo htmlspecialchars(t('admin_live_messages')); ?></h2>
                        <div class="filter-grid">
                            <label class="filter-field">
                                <?php echo htmlspecialchars(t('admin_search_messages')); ?>
                                <input class="admin-input" id="chatSearchInput" type="search">
                            </label>
                            <label class="filter-field">
                                <?php echo htmlspecialchars(t('admin_filter_user')); ?>
                                <input class="admin-input" id="chatUserFilter" type="search">
                            </label>
                            <label class="filter-field">
                                <?php echo htmlspecialchars(t('admin_filter_frequency')); ?>
                                <input class="admin-input" id="chatFrequencyFilter" type="text" inputmode="decimal" placeholder="122.800">
                            </label>
                            <label class="filter-field">
                                <?php echo htmlspecialchars(t('admin_filter_type')); ?>
                                <input class="admin-input" id="chatTypeFilter" type="search">
                            </label>
                            <label class="filter-field">
                                <?php echo htmlspecialchars(t('admin_filter_from')); ?>
                                <input class="admin-input" id="chatDateFrom" type="datetime-local">
                            </label>
                            <label class="filter-field">
                                <?php echo htmlspecialchars(t('admin_filter_to')); ?>
                                <input class="admin-input" id="chatDateTo" type="datetime-local">
                            </label>
                        </div>
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
                        <div class="admin-form-row" id="chatPager"></div>
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
                    <div class="admin-form-row" id="activityPager"></div>
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
                        <button class="admin-button" type="button" id="voiceDisconnectButton">
                            <?php echo htmlspecialchars(t('admin_voice_disconnect')); ?>
                        </button>
                    </div>

                    <div class="admin-form-row" id="voiceMonitorLocationRow">
                        <label class="admin-device-select">
                            <span><?php echo htmlspecialchars(t('admin_voice_monitor_airport')); ?></span>
                            <input id="voiceMonitorAirportIcao" class="admin-input"
                                   type="text" maxlength="12" placeholder="EDDM">
                        </label>
                        <label class="admin-device-select">
                            <span><?php echo htmlspecialchars(t('admin_voice_monitor_range')); ?></span>
                            <select id="voiceMonitorRange" class="admin-input">
                                <option value="5">5 NM</option>
                                <option value="10">10 NM</option>
                                <option value="25" selected>25 NM</option>
                                <option value="50">50 NM</option>
                            </select>
                        </label>
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
                        <label class="voice-continuous-option">
                            <input type="checkbox" id="voiceContinuousMode">
                            <span><?php echo htmlspecialchars(t('admin_voice_continuous_mode')); ?></span>
                        </label>
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

                    <?php if ($adminOpPermission >= 5): ?>
                    <section class="voice-test-source">
                        <h3><?php echo htmlspecialchars(t('admin_voice_test_source_title')); ?></h3>
                        <p><?php echo htmlspecialchars(t('admin_voice_test_source_text')); ?></p>
                        <div class="filter-grid">
                            <label class="filter-field"><?php echo htmlspecialchars(t('admin_voice_test_frequency')); ?><input id="voiceTestFrequency" class="admin-input" type="text" value="122.800" inputmode="decimal"></label>
                            <label class="filter-field"><?php echo htmlspecialchars(t('admin_voice_test_source_type')); ?><select id="voiceTestSourceType" class="admin-input"><option value="stream"><?php echo htmlspecialchars(t('admin_voice_test_stream')); ?></option><option value="upload"><?php echo htmlspecialchars(t('admin_voice_test_upload')); ?></option></select></label>
                            <label class="filter-field" id="voiceTestLocationTypeField" hidden><?php echo htmlspecialchars(t('admin_voice_test_location_type')); ?><select id="voiceTestLocationType" class="admin-input"><option value="pilot"><?php echo htmlspecialchars(t('admin_voice_test_location_pilot')); ?></option><option value="airport"><?php echo htmlspecialchars(t('admin_voice_test_location_airport')); ?></option></select></label>
                            <label class="filter-field" id="voiceTestReferenceField" hidden><?php echo htmlspecialchars(t('admin_voice_test_reference')); ?><select id="voiceTestReferencePilot" class="admin-input"></select></label>
                            <label class="filter-field" id="voiceTestAirportField" hidden><?php echo htmlspecialchars(t('admin_voice_test_airport_icao')); ?><input id="voiceTestAirportIcao" class="admin-input" type="text" maxlength="12" placeholder="EDDM"></label>
                            <label class="filter-field" id="voiceTestRangeField" hidden><?php echo htmlspecialchars(t('admin_voice_test_range')); ?><select id="voiceTestRange" class="admin-input"><option value="5">5 NM</option><option value="10">10 NM</option><option value="25" selected>25 NM</option><option value="50">50 NM</option></select></label>
                        </div>
                        <label class="filter-field" id="voiceTestStreamField"><?php echo htmlspecialchars(t('admin_voice_test_stream_url')); ?><input id="voiceTestStreamUrl" class="admin-input" type="url" placeholder="https://..."></label>
                        <label class="filter-field" id="voiceTestUploadField" hidden><?php echo htmlspecialchars(t('admin_voice_test_audio_file')); ?><input id="voiceTestAudioFile" class="admin-input" type="file" accept=".aac,.mp3,.flac,audio/aac,audio/mpeg,audio/flac"></label>
                        <div class="admin-form-row">
                            <label class="voice-continuous-option"><input id="voiceTestLoop" type="checkbox"><span><?php echo htmlspecialchars(t('admin_voice_test_loop')); ?></span></label>
                            <button id="voiceTestStartButton" class="admin-button primary" type="button"><?php echo htmlspecialchars(t('admin_voice_test_start')); ?></button>
                            <button id="voiceTestStopButton" class="admin-button" type="button"><?php echo htmlspecialchars(t('admin_voice_test_stop')); ?></button>
                        </div>
                        <p id="voiceTestStatus" class="voice-test-source-status"><?php echo htmlspecialchars(t('admin_voice_test_inactive')); ?></p>
                    </section>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-panel" id="admin-panel-players">
                <div class="admin-box">
                    <h2><?php echo htmlspecialchars(t('admin_players_title')); ?></h2>
                    <p><?php echo htmlspecialchars(t('admin_players_text')); ?></p>

                    <div class="filter-grid players">
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_players_search')); ?>
                            <input class="admin-input" id="playerSearchInput" type="search">
                        </label>
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_players_country')); ?>
                            <select class="admin-input" id="playerCountryFilter">
                                <option value=""><?php echo htmlspecialchars(t('admin_filter_all')); ?></option>
                            </select>
                        </label>
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_players_division')); ?>
                            <select class="admin-input" id="playerDivisionFilter">
                                <option value=""><?php echo htmlspecialchars(t('admin_filter_all')); ?></option>
                            </select>
                        </label>
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_players_rank')); ?>
                            <input class="admin-input" id="playerRankFilter" type="search">
                        </label>
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_players_status')); ?>
                            <select class="admin-input" id="playerStatusFilter">
                                <option value=""><?php echo htmlspecialchars(t('admin_filter_all')); ?></option>
                                <option value="active"><?php echo htmlspecialchars(t('admin_players_active')); ?></option>
                                <option value="inactive"><?php echo htmlspecialchars(t('admin_players_inactive')); ?></option>
                            </select>
                        </label>
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_players_network_status')); ?>
                            <select class="admin-input" id="playerOnlineFilter">
                                <option value=""><?php echo htmlspecialchars(t('admin_filter_all')); ?></option>
                                <option value="online"><?php echo htmlspecialchars(t('admin_players_online')); ?></option>
                                <option value="offline"><?php echo htmlspecialchars(t('admin_players_offline')); ?></option>
                            </select>
                        </label>
                    </div>

                    <div class="result-count" id="playerResultCount"></div>
                    <div class="admin-form-row" id="playerPager"></div>
                    <div id="playerEmpty" class="empty-state"><?php echo htmlspecialchars(t('admin_loading')); ?></div>
                    <div class="table-scroll">
                        <table class="monitor-table" id="playerTable" hidden>
                            <thead><tr>
                                <th><?php echo htmlspecialchars(t('admin_players_name')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_players_email')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_players_country')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_players_division')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_players_rank')); ?></th>
                                <th>OP</th>
                                <th><?php echo htmlspecialchars(t('admin_players_status')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_players_network_status')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_players_registered')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_players_last_login')); ?></th>
                                <?php if ($adminOpPermission >= 4): ?><th><?php echo htmlspecialchars(t('admin_manage')); ?></th><?php endif; ?>
                            </tr></thead>
                            <tbody id="playerRows"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="admin-panel" id="admin-panel-transfers">
                <div class="admin-box">
                    <h2><?php echo htmlspecialchars(t('admin_transfers_title')); ?></h2>
                    <p><?php echo htmlspecialchars(t('admin_transfers_text')); ?></p>
                    <div id="transferEmpty" class="empty-state"><?php echo htmlspecialchars(t('admin_loading')); ?></div>
                    <div class="table-scroll">
                        <table class="monitor-table" id="transferTable" hidden>
                            <thead><tr>
                                <th><?php echo htmlspecialchars(t('admin_players_name')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_players_email')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_transfer_current')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_transfer_requested')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_transfer_reason')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_time')); ?></th>
                                <th><?php echo htmlspecialchars(t('admin_transfer_action')); ?></th>
                            </tr></thead>
                            <tbody id="transferRows"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($adminOpPermission >= 4): ?>
                <div class="admin-panel" id="admin-panel-moderation">
                    <div class="admin-box">
                        <h2><?php echo htmlspecialchars(t('admin_moderation_title')); ?></h2>
                        <p><?php echo htmlspecialchars(t('admin_moderation_text')); ?></p>
                        <div id="moderationEmpty" class="empty-state"><?php echo htmlspecialchars(t('admin_loading')); ?></div>
                        <div class="table-scroll">
                            <table class="monitor-table" id="moderationTable" hidden>
                                <thead><tr>
                                    <th><?php echo htmlspecialchars(t('admin_players_name')); ?></th>
                                    <th><?php echo htmlspecialchars(t('admin_players_email')); ?></th>
                                    <th><?php echo htmlspecialchars(t('admin_moderation_ban_reason')); ?></th>
                                    <th><?php echo htmlspecialchars(t('admin_moderation_appeal_reason')); ?></th>
                                    <th><?php echo htmlspecialchars(t('admin_time')); ?></th>
                                    <th><?php echo htmlspecialchars(t('admin_transfer_action')); ?></th>
                                </tr></thead>
                                <tbody id="moderationRows"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="admin-panel" id="admin-panel-chat-filter">
                    <div class="admin-box">
                        <h2><?php echo htmlspecialchars(t('admin_chat_filter_title')); ?></h2>
                        <p><?php echo htmlspecialchars(t('admin_chat_filter_text')); ?></p>
                        <div class="admin-form-row">
                            <input id="chatFilterWordInput" class="admin-input" type="text" maxlength="60"
                                   placeholder="<?php echo htmlspecialchars(t('admin_chat_filter_word')); ?>">
                            <button class="admin-button primary" type="button" id="chatFilterAddButton">
                                <?php echo htmlspecialchars(t('admin_chat_filter_add')); ?>
                            </button>
                        </div>
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_chat_filter_search')); ?>
                            <input id="chatFilterSearchInput" class="admin-input" type="search">
                        </label>
                        <p id="chatFilterStatus"></p>
                        <div id="chatFilterEmpty" class="empty-state"><?php echo htmlspecialchars(t('admin_loading')); ?></div>
                        <div class="table-scroll">
                            <table class="monitor-table" id="chatFilterTable" hidden>
                                <thead><tr>
                                    <th><?php echo htmlspecialchars(t('admin_chat_filter_word')); ?></th>
                                    <th><?php echo htmlspecialchars(t('admin_chat_filter_created_by')); ?></th>
                                    <th><?php echo htmlspecialchars(t('admin_time')); ?></th>
                                    <th><?php echo htmlspecialchars(t('admin_transfer_action')); ?></th>
                                </tr></thead>
                                <tbody id="chatFilterRows"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($adminOpPermission >= 5): ?>
                <div class="admin-panel" id="admin-panel-configuration">
                    <div class="admin-box">
                        <h2><?php echo htmlspecialchars(t('admin_configuration_title')); ?></h2>
                        <p><?php echo htmlspecialchars(t('admin_configuration_text')); ?></p>
                        <div class="notice">
                            <?php echo htmlspecialchars(t('admin_configuration_excluded')); ?>
                        </div>
                        <div class="filter-grid players" id="configurationFields"></div>
                        <button class="admin-button primary"
                                type="button"
                                id="configurationSaveButton">
                            <?php echo htmlspecialchars(t('admin_configuration_save')); ?>
                        </button>
                        <p id="configurationStatus"></p>
                    </div>
                </div>

                <div class="admin-panel" id="admin-panel-database-reset">
                    <div class="admin-box">
                        <h2><?php echo htmlspecialchars(t('admin_database_reset_title')); ?></h2>
                        <p><?php echo htmlspecialchars(t('admin_database_reset_text')); ?></p>
                        <div class="notice">
                            <?php echo htmlspecialchars(t('admin_database_reset_warning')); ?>
                        </div>
                        <p><strong><?php echo htmlspecialchars(t('admin_database_reset_preserved')); ?></strong></p>
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_database_reset_password')); ?>
                            <input class="admin-input" id="databaseResetPassword"
                                   type="password" autocomplete="current-password">
                        </label>
                        <label class="filter-field">
                            <?php echo htmlspecialchars(t('admin_database_reset_confirmation')); ?>
                            <input class="admin-input" id="databaseResetConfirmation"
                                   type="text" autocomplete="off" placeholder="RESET VFN">
                        </label>
                        <button class="admin-button" type="button" id="databaseResetButton">
                            <?php echo htmlspecialchars(t('admin_database_reset_button')); ?>
                        </button>
                        <p id="databaseResetStatus"></p>
                    </div>
                </div>

                <div class="admin-panel" id="admin-panel-todo">
                    <div class="admin-box">
                        <h2><?php echo htmlspecialchars(t('admin_todo_title')); ?></h2>
                        <p><?php echo htmlspecialchars(t('admin_todo_text')); ?></p>
                        <?php if ($adminTodoLines === []): ?>
                            <div class="notice">
                                <?php echo htmlspecialchars(t('admin_todo_unavailable')); ?>
                            </div>
                        <?php else: ?>
                            <div class="todo-document">
                                <?php foreach ($adminTodoLines as $todoLine): ?>
                                    <?php
                                    $trimmedTodoLine = trim($todoLine);
                                    if ($trimmedTodoLine === '' || $trimmedTodoLine === '---') {
                                        continue;
                                    }
                                    if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmedTodoLine, $headingMatch)) {
                                        $headingLevel = min(4, strlen($headingMatch[1]));
                                        echo '<h' . $headingLevel . '>'
                                            . renderAdminTodoInline($headingMatch[2])
                                            . '</h' . $headingLevel . '>';
                                        continue;
                                    }
                                    if (preg_match('/^(\s*)-\s+\[([xX -])\]\s+(.+)$/', $todoLine, $checkMatch)) {
                                        $indent = min(5, intdiv(strlen($checkMatch[1]), 2));
                                        $state = strtolower($checkMatch[2]);
                                        $stateClass = $state === 'x'
                                            ? ' is-complete'
                                            : ($state === '-' ? ' is-partial' : '');
                                        $marker = $state === 'x' ? '✓' : ($state === '-' ? '◐' : '□');
                                        echo '<div class="todo-check' . $stateClass . '" style="margin-left:'
                                            . ($indent * 18) . 'px"><span class="todo-marker">'
                                            . $marker . '</span><span>'
                                            . renderAdminTodoInline($checkMatch[3])
                                            . '</span></div>';
                                        continue;
                                    }
                                    echo '<div class="todo-line">'
                                        . renderAdminTodoInline($trimmedTodoLine)
                                        . '</div>';
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
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
        'noPlayers' => t('admin_players_no_results'),
        'playersFound' => t('admin_players_result_count'),
        'manage' => t('admin_manage'),
        'playerActive' => t('admin_players_active'),
        'playerInactive' => t('admin_players_inactive'),
        'playerOnline' => t('admin_players_online'),
        'playerOffline' => t('admin_players_offline'),
        'transferNone' => t('admin_transfer_none'),
        'transferApprove' => t('admin_transfer_approve'),
        'transferReject' => t('admin_transfer_reject'),
        'moderationNone' => t('admin_moderation_none'),
        'moderationApprove' => t('admin_moderation_approve'),
        'moderationReject' => t('admin_moderation_reject'),
        'moderationReviewReason' => t('admin_moderation_review_reason'),
        'staffChatSent' => t('admin_staff_chat_sent'),
        'chatFilterRemove' => t('admin_chat_filter_remove'),
        'chatFilterEmpty' => t('admin_chat_filter_empty'),
        'chatFilterSaved' => t('admin_chat_filter_saved'),
        'chatFilterInvalid' => t('admin_chat_filter_invalid'),
        'allFrequenciesActive' => t('admin_all_frequencies_active'),
        'deviceDefault' => t('admin_voice_device_default'),
        'deviceInputPrefix' => t('admin_voice_device_input_prefix'),
        'deviceOutputPrefix' => t('admin_voice_device_output_prefix'),
        'devicePermissionHint' => t('admin_voice_device_permission_hint'),
        'voicePrepared' => t('admin_voice_browser_ready'),
        'voiceTransmitPrepared' => t('admin_voice_transmit_prepared'),
        'voiceConnecting' => t('admin_voice_connecting'),
        'voiceConnected' => t('admin_voice_connected'),
        'voiceDisconnected' => t('admin_voice_disconnected'),
        'voiceConnectionFailed' => t('admin_voice_connection_failed'),
        'voiceReceiving' => t('admin_voice_receiving'),
        'voiceAuthMissing' => t('admin_voice_auth_missing'),
        'voiceChannelBusy' => t('admin_voice_channel_busy'),
        'voiceMonitorReferenceMissing' => t('admin_voice_monitor_reference_missing'),
        'voicePushToTalk' => t('admin_voice_push_to_talk'),
        'voiceContinuousStart' => t('admin_voice_continuous_start'),
        'voiceContinuousStop' => t('admin_voice_continuous_stop'),
        'voiceTestInactive' => t('admin_voice_test_inactive'),
        'voiceTestActive' => t('admin_voice_test_active'),
        'voiceTestStarting' => t('admin_voice_test_starting'),
        'voiceTestInvalid' => t('admin_voice_test_invalid'),
        'voiceTestReferenceMissing' => t('admin_voice_test_reference_missing'),
        'voiceTestUploadFailed' => t('admin_voice_test_upload_failed'),
        'databaseResetConfirmDialog' => t('admin_database_reset_confirm_dialog'),
        'databaseResetRunning' => t('admin_database_reset_running'),
        'databaseResetComplete' => t('admin_database_reset_complete'),
        'databaseResetInvalid' => t('admin_database_reset_invalid'),
        'announcementSent' => t('admin_announcement_sent'),
        'announcementInvalid' => t('admin_announcement_invalid'),
        'announcementConfirm' => t('admin_announcement_confirm'),
        'configurationSaved' => t('admin_configuration_saved'),
        'configurationInvalid' => t('admin_configuration_invalid'),
        'configurationLoading' => t('admin_configuration_loading'),
        'configurationTrue' => t('admin_configuration_true'),
        'configurationFalse' => t('admin_configuration_false'),
        'translate' => t('admin_chat_translate'),
        'showOriginal' => t('admin_chat_show_original'),
        'translationLoading' => t('admin_chat_translation_loading'),
        'translationFailed' => t('admin_chat_translation_failed'),
        'automaticTranslation' => t('admin_chat_automatic_translation')
    ], JSON_UNESCAPED_UNICODE); ?>;
    const CONFIGURATION_LABELS = <?php echo json_encode([
        'categories' => [
            'general' => t('admin_configuration_category_general'),
            'permissions' => t('admin_configuration_category_permissions'),
            'chat' => t('admin_configuration_category_chat'),
            'weather' => t('admin_configuration_category_weather'),
            'voice' => t('admin_configuration_category_voice'),
            'download' => t('admin_configuration_category_download'),
            'legal' => t('admin_configuration_category_legal')
        ],
        'settings' => [
            'defaultTimezone' => t('admin_configuration_default_timezone'),
            'minimumInvisibleOpPermission' => t('admin_configuration_minimum_invisible_op'),
            'showRatings' => t('admin_configuration_show_ratings'),
            'maintenanceMode' => t('admin_configuration_maintenance_mode'),
            'registrationEnabled' => t('admin_configuration_registration_enabled'),
            'atcLoginEnabled' => t('admin_configuration_atc_login_enabled'),
            'chatFrequencyRangeNm' => t('admin_configuration_chat_range'),
            'aviationWeatherMetarCacheUrl' => t('admin_configuration_metar_cache_url'),
            'noaaMetarStationBaseUrl' => t('admin_configuration_metar_station_url'),
            'metarCacheSeconds' => t('admin_configuration_metar_cache_seconds'),
            'voiceServiceWebSocketUrl' => t('admin_configuration_voice_url'),
            'projectName' => t('admin_configuration_project_name'),
            'pluginDownloadEnabled' => t('admin_configuration_download_enabled'),
            'pluginDownloadUrl' => t('admin_configuration_download_url'),
            'pluginDownloadName' => t('admin_configuration_download_name'),
            'requiredPluginVersion' => t('admin_configuration_required_plugin_version'),
            'companyName' => t('admin_configuration_company_name'),
            'companyOwner' => t('admin_configuration_company_owner'),
            'companyAddress' => t('admin_configuration_company_address'),
            'companyZipCity' => t('admin_configuration_company_zip_city'),
            'companyCountry' => t('admin_configuration_company_country'),
            'companyEmail' => t('admin_configuration_company_email')
        ]
    ], JSON_UNESCAPED_UNICODE); ?>;
    const ADMIN_CSRF = <?php echo json_encode((string)$_SESSION['admin_csrf']); ?>;
    const ADMIN_LANGUAGE = <?php echo json_encode(
        vfnNormalizeLanguage($_SESSION['language'] ?? '') ?: 'en'
    ); ?>;
    const CAN_MANAGE_CHAT_FILTER = <?php echo $adminOpPermission >= 4 ? 'true' : 'false'; ?>;
    const CAN_MANAGE_MODERATION = <?php echo $adminOpPermission >= 4 ? 'true' : 'false'; ?>;
    const CAN_RESET_DATABASE = <?php echo $adminOpPermission >= 5 ? 'true' : 'false'; ?>;
    const CAN_SEND_ANNOUNCEMENT = <?php echo $adminOpPermission >= 5 ? 'true' : 'false'; ?>;
    const CAN_CONTROL_VOICE_TEST = <?php echo $adminOpPermission >= 5 ? 'true' : 'false'; ?>;

    const CAN_VIEW_ALL_FREQUENCIES =
        <?php echo $canViewAllFrequencies ? 'true' : 'false'; ?>;

    const VOICE_WS_URL =
        <?php echo json_encode((string)($voiceServiceWebSocketUrl ?? ''), JSON_UNESCAPED_SLASHES); ?> ||
        ((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.hostname + ':8090');

    const VOICE_SESSION_TOKEN =
        <?php echo json_encode($voiceSessionToken); ?>;

    const VOICE_CALLSIGN =
        <?php echo json_encode(strtoupper((string)($adminUser['username'] ?? 'WEBSTAFF'))); ?>;

    let monitoredFrequencies =
        JSON.parse(localStorage.getItem('vfn_admin_monitor_frequencies') || '["122.800"]');

    let monitorAllFrequencies =
        localStorage.getItem('vfn_admin_monitor_all') === '1' && CAN_VIEW_ALL_FREQUENCIES;

    let lastMessageId = 0;
    let chatPage = 1;
    let activityLoaded = false;
    let activityPage = 1;
    let playerPage = 1;
    let playersLoaded = false;
    let transfersLoaded = false;
    let moderationLoaded = false;
    let chatFilterLoaded = false;
    let configurationLoaded = false;
    let configurationDefinitions = {};
    let chatFilterWords = [];
    let playerOptionsLoaded = false;
    let chatFilterTimer = null;
    let playerFilterTimer = null;
    let voiceAudioContext = null;
    let voiceAnalyser = null;
    let voiceLevelData = null;
    let voiceMediaStream = null;
    let voiceInputSource = null;
    let voiceProcessor = null;
    let voiceSilentGain = null;
    let voiceMeterAnimation = null;
    let voiceTransmitting = false;
    let voiceContinuousMode =
        localStorage.getItem('vfn_admin_voice_continuous') === '1';
    let voiceSocket = null;
    let voiceConnected = false;
    let voiceConnectPromise = null;
    let voiceReconnectTimer = null;
    let voiceShouldReconnect = false;
    let voiceSequence = 0;
    let voiceCurrentFrequency = '122.800';
    let voiceRxResetTimer = null;
    let voicePlaybackContext = null;
    let voicePlaybackTime = 0;

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
            const sender =
                Number(message.sender_user_id) > 0
                    ? '<a class="player-profile-link" href="profile.php?id=' +
                      encodeURIComponent(message.sender_user_id) + '">' +
                      escapeHtml(message.sender) + '</a>'
                    : escapeHtml(message.sender);

            row.innerHTML =
                '<td>' + escapeHtml(message.date_time || message.time) + '</td>' +
                '<td class="frequency">' + escapeHtml(message.frequency) + '</td>' +
                '<td class="sender">' + sender + '</td>' +
                '<td>' + escapeHtml(message.type) + '</td>' +
                '<td class="' + typeClass + '"><span class="chat-message-text">' +
                (
                    message.was_filtered
                        ? highlightFilteredChatText(message.original_text, message.text)
                        : escapeHtml(message.text)
                ) +
                '</span><br><button type="button" class="admin-button chat-translate-button">' +
                escapeHtml(ADMIN_I18N.translate) + '</button>' +
                '</td>';

            const translateButton = row.querySelector('.chat-translate-button');
            const messageText = row.querySelector('.chat-message-text');
            const originalMarkup = messageText.innerHTML;
            let translatedText = '';
            let translationPromise = null;
            const sourceText = String(message.original_text || message.text || '');

            function loadTranslation() {
                if (translationPromise !== null) {
                    return translationPromise;
                }
                const body = new URLSearchParams();
                body.set('csrf', ADMIN_CSRF);
                body.set('target', ADMIN_LANGUAGE);
                body.set('text', sourceText);
                translationPromise = fetch('execute/admin_chat_translate.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: body.toString()
                }).then(async function(response) {
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'translation_failed');
                    }
                    translatedText = String(data.translated_text || '');
                    return data;
                });
                return translationPromise;
            }

            // Automatically translate clearly foreign scripts. Latin-script
            // messages retain the manual control to avoid translating every
            // historic row and exhausting the external service quota.
            if (/[\u0370-\u052f\u0600-\u06ff\u3040-\u30ff\u3400-\u9fff\uac00-\ud7af]/u.test(sourceText)) {
                loadTranslation().then(function() {
                    if (translatedText === '' || translatedText.localeCompare(sourceText, undefined, {sensitivity:'base'}) === 0) {
                        return;
                    }
                    const automatic = document.createElement('div');
                    automatic.className = 'chat-auto-translation';
                    const label = document.createElement('strong');
                    label.textContent = ADMIN_I18N.automaticTranslation + ': ';
                    automatic.appendChild(label);
                    automatic.appendChild(document.createTextNode(translatedText));
                    messageText.parentNode.insertBefore(automatic, translateButton);
                }).catch(function() {
                    // Keep the original message usable if translation is offline.
                });
            }

            translateButton.addEventListener('click', async function() {
                if (translateButton.dataset.translated === '1') {
                    messageText.innerHTML = originalMarkup;
                    translateButton.dataset.translated = '0';
                    translateButton.textContent = ADMIN_I18N.translate;
                    return;
                }
                if (translatedText !== '') {
                    messageText.textContent = translatedText;
                    translateButton.dataset.translated = '1';
                    translateButton.textContent = ADMIN_I18N.showOriginal;
                    return;
                }
                translateButton.disabled = true;
                translateButton.textContent = ADMIN_I18N.translationLoading;
                try {
                    await loadTranslation();
                    messageText.textContent = translatedText;
                    translateButton.dataset.translated = '1';
                    translateButton.textContent = ADMIN_I18N.showOriginal;
                } catch (error) {
                    translateButton.textContent = ADMIN_I18N.translationFailed;
                    setTimeout(function() {
                        translateButton.textContent = ADMIN_I18N.translate;
                    }, 2500);
                } finally {
                    translateButton.disabled = false;
                }
            });

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

    async function pollMessages(forceHistory)
    {
        if (chatPage > 1 && !forceHistory) {
            return;
        }
        if (!monitorAllFrequencies && monitoredFrequencies.length === 0) {
            return;
        }

        const params = new URLSearchParams();
        params.set('since_id', String(chatPage === 1 ? lastMessageId : 0));
        params.set('page', String(chatPage));
        params.set('per_page', '50');
        params.set('frequencies', monitoredFrequencies.join(','));

        const chatFilters = {
            search: document.getElementById('chatSearchInput').value.trim(),
            user: document.getElementById('chatUserFilter').value.trim(),
            frequency: document.getElementById('chatFrequencyFilter').value.trim(),
            type: document.getElementById('chatTypeFilter').value.trim(),
            date_from: document.getElementById('chatDateFrom').value,
            date_to: document.getElementById('chatDateTo').value
        };

        Object.entries(chatFilters).forEach(function(entry) {
            if (entry[1] !== '') {
                params.set(entry[0], entry[1]);
            }
        });

        if (monitorAllFrequencies) {
            params.set('all', '1');
        }

        try {
            const requestedHistory = lastMessageId === 0;
            const response =
                await fetch('execute/admin_chat_monitor.php?' + params.toString());

            const data =
                await response.json();

            if (data.success) {
                appendMessages(data.messages || [], lastMessageId === 0);
                if (requestedHistory || chatPage > 1 || forceHistory) {
                    renderPager('chatPager', data.pagination, function(page) {
                        chatPage = page;
                        lastMessageId = 0;
                        document.getElementById('messageRows').innerHTML = '';
                        pollMessages(true);
                    });
                }
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
                await fetch('execute/admin_staff_activity.php?page=' + activityPage + '&per_page=25');

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
            renderPager('activityPager', data.pagination, function(page) {
                activityPage = page;
                loadActivities();
            });
        } catch (error) {
            list.innerHTML = '<div class="empty-state">' + escapeHtml(ADMIN_I18N.serverError) + '</div>';
        }
    }

    function populatePlayerOptions(data)
    {
        if (playerOptionsLoaded) {
            return;
        }

        const countrySelect = document.getElementById('playerCountryFilter');
        const divisionSelect = document.getElementById('playerDivisionFilter');

        (data.countries || []).forEach(function(country) {
            const option = document.createElement('option');
            option.value = country;
            option.textContent = country;
            countrySelect.appendChild(option);
        });

        (data.divisions || []).forEach(function(division) {
            const option = document.createElement('option');
            option.value = division.code;
            option.textContent = divisionFlagEmoji(division.code) + ' ' + (
                division.name && division.name !== division.code
                    ? division.code + ' - ' + division.name
                    : division.code
            );
            divisionSelect.appendChild(option);
        });

        playerOptionsLoaded = true;
    }

    async function loadPlayers()
    {
        const rows = document.getElementById('playerRows');
        const table = document.getElementById('playerTable');
        const empty = document.getElementById('playerEmpty');
        const count = document.getElementById('playerResultCount');
        const params = new URLSearchParams();
        params.set('page', String(playerPage));
        params.set('per_page', '25');
        const filters = {
            search: document.getElementById('playerSearchInput').value.trim(),
            country: document.getElementById('playerCountryFilter').value,
            division: document.getElementById('playerDivisionFilter').value,
            rank: document.getElementById('playerRankFilter').value.trim(),
            status: document.getElementById('playerStatusFilter').value,
            online: document.getElementById('playerOnlineFilter').value
        };

        Object.entries(filters).forEach(function(entry) {
            if (entry[1] !== '') {
                params.set(entry[0], entry[1]);
            }
        });

        empty.hidden = false;
        empty.textContent = ADMIN_I18N.noPlayers;

        try {
            const response =
                await fetch('execute/admin_players.php?' + params.toString());
            const data = await response.json();

            if (!data.success || !Array.isArray(data.players)) {
                throw new Error('players_failed');
            }

            populatePlayerOptions(data);
            rows.innerHTML = '';

            data.players.forEach(function(player) {
                const row = document.createElement('tr');
                const displayName =
                    player.real_name
                        ? player.real_name + ' (' + player.username + ')'
                        : player.username;
                const division =
                    player.division_name
                        ? player.division + ' - ' + player.division_name
                        : (player.division || '-');
                const ranks =
                    [player.pilot_rank, player.atc_rank, player.special_rank]
                        .filter(Boolean)
                        .join('<br>');
                const statusLabel =
                    player.active
                        ? ADMIN_I18N.playerActive
                        : ADMIN_I18N.playerInactive;
                const onlineLabel =
                    player.online
                        ? ADMIN_I18N.playerOnline
                        : ADMIN_I18N.playerOffline;

                row.innerHTML =
                    '<td class="sender"><a class="player-profile-link" href="profile.php?id=' +
                    encodeURIComponent(player.id) + '">' + escapeHtml(displayName) + '</a></td>' +
                    '<td>' + escapeHtml(player.email || '-') + '</td>' +
                    '<td>' + escapeHtml(player.country || '-') + '</td>' +
                    '<td>' + divisionFlagHtml(player.division) + escapeHtml(division) + '</td>' +
                    '<td>' + ranks.split('<br>').map(escapeHtml).join('<br>') + '</td>' +
                    '<td>' + escapeHtml(player.op_permission) + '</td>' +
                    '<td><span class="player-status ' + (player.active ? 'active' : '') + '">' +
                    escapeHtml(statusLabel) + '</span></td>' +
                    '<td><span class="player-status ' + (player.online ? 'active' : '') + '">' +
                    escapeHtml(onlineLabel) + '</span></td>' +
                    '<td>' + escapeHtml(player.registered) + '</td>' +
                    '<td>' + escapeHtml(player.last_login) + '</td>' +
                    (CAN_MANAGE_MODERATION
                        ? '<td><a class="admin-button" href="admin_user.php?id=' +
                          encodeURIComponent(player.id) + '">' +
                          escapeHtml(ADMIN_I18N.manage) + '</a></td>'
                        : '');
                rows.appendChild(row);
            });

            table.hidden = data.players.length === 0;
            empty.hidden = data.players.length !== 0;
            count.textContent =
                String((data.pagination || {}).total || data.players.length) + ' ' + ADMIN_I18N.playersFound;
            renderPager('playerPager', data.pagination, function(page) {
                playerPage = page;
                loadPlayers();
            });
        } catch (error) {
            rows.innerHTML = '';
            table.hidden = true;
            empty.hidden = false;
            empty.textContent = ADMIN_I18N.serverError;
            count.textContent = '';
        }
    }

    function renderPager(containerId, pagination, onPage)
    {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '';
        const pages = Number((pagination || {}).pages || 1);
        const current = Number((pagination || {}).page || 1);
        if (pages <= 1) return;
        const first = Math.max(1, current - 3);
        const last = Math.min(pages, current + 3);
        for (let page = first; page <= last; page++) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'admin-button' + (page === current ? ' primary' : '');
            button.textContent = String(page);
            button.addEventListener('click', function() { onPage(page); });
            container.appendChild(button);
        }
    }

    function refreshChatFilters()
    {
        chatPage = 1;
        lastMessageId = 0;
        document.getElementById('messageRows').innerHTML = '';
        document.getElementById('messageTable').hidden = true;
        document.getElementById('messageEmpty').hidden = false;
        pollMessages();
    }

    async function loadTransfers()
    {
        const rows = document.getElementById('transferRows');
        const table = document.getElementById('transferTable');
        const empty = document.getElementById('transferEmpty');
        try {
            const response = await fetch('execute/admin_division_transfers.php');
            const data = await response.json();
            if (!data.success || !Array.isArray(data.items)) {
                throw new Error('transfer_load_failed');
            }
            rows.innerHTML = '';
            data.items.forEach(function(item) {
                const row = document.createElement('tr');
                const name = item.real_name
                    ? item.real_name + ' (' + item.username + ')'
                    : item.username;
                row.innerHTML =
                    '<td><a class="player-profile-link" href="profile.php?id=' +
                    encodeURIComponent(item.user_id) + '">' + escapeHtml(name) + '</a></td>' +
                    '<td>' + escapeHtml(item.email) + '</td>' +
                    '<td>' + divisionFlagHtml(item.current_division) + escapeHtml(item.current_division) + '</td>' +
                    '<td class="frequency">' + divisionFlagHtml(item.requested_division) + escapeHtml(item.requested_division) + '</td>' +
                    '<td>' + escapeHtml(item.reason) + '</td>' +
                    '<td>' + escapeHtml(item.created_at) + '</td>' +
                    '<td><button class="admin-button primary" data-transfer-action="approve" data-transfer-id="' +
                    item.id + '">' + escapeHtml(ADMIN_I18N.transferApprove) + '</button> ' +
                    '<button class="admin-button" data-transfer-action="reject" data-transfer-id="' +
                    item.id + '">' + escapeHtml(ADMIN_I18N.transferReject) + '</button></td>';
                rows.appendChild(row);
            });
            table.hidden = data.items.length === 0;
            empty.hidden = data.items.length !== 0;
            empty.textContent = ADMIN_I18N.transferNone;
            const dot = document.getElementById('transferTabDot');
            if (dot && data.items.length === 0) {
                dot.remove();
            }
        } catch (error) {
            table.hidden = true;
            empty.hidden = false;
            empty.textContent = ADMIN_I18N.serverError;
        }
    }

    async function updateTransfer(requestId, action)
    {
        const body = new URLSearchParams();
        body.set('request_id', String(requestId));
        body.set('action', action);
        body.set('csrf', ADMIN_CSRF);
        const response = await fetch('execute/admin_division_transfers.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        });
        const data = await response.json();
        if (!data.success) {
            throw new Error('transfer_update_failed');
        }
        loadTransfers();
    }

    async function loadBanAppeals()
    {
        if (!CAN_MANAGE_MODERATION) return;
        const rows = document.getElementById('moderationRows');
        const table = document.getElementById('moderationTable');
        const empty = document.getElementById('moderationEmpty');
        try {
            const response = await fetch('execute/admin_ban_appeals.php');
            const data = await response.json();
            if (!data.success || !Array.isArray(data.items)) throw new Error('load_failed');
            rows.innerHTML = '';
            data.items.forEach(function(item) {
                const row = document.createElement('tr');
                const name = item.real_name ? item.real_name + ' (' + item.username + ')' : item.username;
                const banInfo = item.ban_reason +
                    (item.ban_expires_at ? ' (' + item.ban_expires_at + ')' : '');
                row.innerHTML =
                    '<td><a class="player-profile-link" href="profile.php?id=' +
                    encodeURIComponent(item.user_id) + '">' + escapeHtml(name) + '</a></td>' +
                    '<td>' + escapeHtml(item.email) + '</td>' +
                    '<td>' + escapeHtml(banInfo) + '</td>' +
                    '<td>' + escapeHtml(item.appeal_reason) + '</td>' +
                    '<td>' + escapeHtml(item.created_at) + '</td>' +
                    '<td><button class="admin-button primary" data-appeal-action="approve" data-appeal-id="' +
                    item.id + '">' + escapeHtml(ADMIN_I18N.moderationApprove) + '</button> ' +
                    '<button class="admin-button" data-appeal-action="reject" data-appeal-id="' +
                    item.id + '">' + escapeHtml(ADMIN_I18N.moderationReject) + '</button></td>';
                rows.appendChild(row);
            });
            table.hidden = data.items.length === 0;
            empty.hidden = data.items.length !== 0;
            empty.textContent = ADMIN_I18N.moderationNone;
            const dot = document.getElementById('moderationTabDot');
            if (dot && data.items.length === 0) dot.remove();
        } catch (error) {
            table.hidden = true;
            empty.hidden = false;
            empty.textContent = ADMIN_I18N.serverError;
        }
    }

    async function updateBanAppeal(requestId, action, reviewReason)
    {
        const body = new URLSearchParams();
        body.set('request_id', String(requestId));
        body.set('action', action);
        body.set('review_reason', reviewReason);
        body.set('csrf', ADMIN_CSRF);
        const response = await fetch('execute/admin_ban_appeals.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'update_failed');
        loadBanAppeals();
    }

    function renderChatFilterWords()
    {
        if (!CAN_MANAGE_CHAT_FILTER) {
            return;
        }
        const query = document.getElementById('chatFilterSearchInput').value
            .trim().toLocaleLowerCase();
        const items = chatFilterWords.filter(function(item) {
            return item.word.toLocaleLowerCase().includes(query);
        });
        const rows = document.getElementById('chatFilterRows');
        const table = document.getElementById('chatFilterTable');
        const empty = document.getElementById('chatFilterEmpty');
        rows.innerHTML = '';
        items.forEach(function(item) {
            const row = document.createElement('tr');
            row.innerHTML =
                '<td class="filtered-chat-word">' + escapeHtml(item.word) + '</td>' +
                '<td>' + escapeHtml(item.created_by || '-') + '</td>' +
                '<td>' + escapeHtml(item.created_at) + '</td>' +
                '<td><button class="admin-button" data-filter-word-remove="' +
                item.id + '">' + escapeHtml(ADMIN_I18N.chatFilterRemove) + '</button></td>';
            rows.appendChild(row);
        });
        table.hidden = items.length === 0;
        empty.hidden = items.length !== 0;
        empty.textContent = ADMIN_I18N.chatFilterEmpty;
    }

    async function loadChatFilterWords()
    {
        if (!CAN_MANAGE_CHAT_FILTER) {
            return;
        }
        try {
            const response = await fetch('execute/admin_chat_filter_words.php');
            const data = await response.json();
            if (!data.success || !Array.isArray(data.items)) {
                throw new Error('filter_load_failed');
            }
            chatFilterWords = data.items;
            renderChatFilterWords();
        } catch (error) {
            document.getElementById('chatFilterStatus').textContent =
                ADMIN_I18N.serverError;
        }
    }

    function renderConfigurationFields(data)
    {
        const container = document.getElementById('configurationFields');
        container.innerHTML = '';
        configurationDefinitions = data.definitions || {};

        let currentCategory = '';
        Object.entries(configurationDefinitions).forEach(function(entry) {
            const key = entry[0];
            const definition = entry[1];

            if (definition.category !== currentCategory) {
                currentCategory = definition.category;
                const heading = document.createElement('h3');
                heading.style.gridColumn = '1 / -1';
                heading.textContent =
                    CONFIGURATION_LABELS.categories[currentCategory] || currentCategory;
                container.appendChild(heading);
            }

            const label = document.createElement('label');
            label.className = 'filter-field';
            label.textContent = CONFIGURATION_LABELS.settings[key] || key;

            let input;
            if (definition.type === 'boolean') {
                input = document.createElement('select');
                [
                    ['true', ADMIN_I18N.configurationTrue],
                    ['false', ADMIN_I18N.configurationFalse]
                ].forEach(function(optionData) {
                    const option = document.createElement('option');
                    option.value = optionData[0];
                    option.textContent = optionData[1];
                    input.appendChild(option);
                });
                input.value = data.values[key] ? 'true' : 'false';
            } else if (definition.type === 'timezone') {
                input = document.createElement('select');
                (data.timezones || []).forEach(function(timezone) {
                    const option = document.createElement('option');
                    option.value = timezone;
                    option.textContent = timezone;
                    input.appendChild(option);
                });
                input.value = String(data.values[key] || 'UTC');
            } else {
                input = document.createElement('input');
                input.type =
                    definition.type === 'integer' || definition.type === 'number'
                        ? 'number'
                        : definition.type === 'email'
                            ? 'email'
                            : 'text';
                if (definition.min !== undefined) {
                    input.min = String(definition.min);
                }
                if (definition.max !== undefined) {
                    input.max = String(definition.max);
                }
                if (definition.type === 'number') {
                    input.step = '0.1';
                }
                input.value =
                    data.values[key] === null || data.values[key] === undefined
                        ? ''
                        : String(data.values[key]);
            }

            input.className = 'admin-input';
            input.dataset.configurationKey = key;
            label.appendChild(input);
            container.appendChild(label);
        });
    }

    async function loadConfigurationSettings()
    {
        const status = document.getElementById('configurationStatus');
        status.textContent = ADMIN_I18N.configurationLoading;

        try {
            const response = await fetch('execute/admin_config_settings.php');
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'configuration_load_failed');
            }
            renderConfigurationFields(data);
            status.textContent = '';
        } catch (error) {
            status.textContent = ADMIN_I18N.configurationInvalid;
        }
    }

    async function changeChatFilterWord(action, values)
    {
        const body = new URLSearchParams();
        body.set('csrf', ADMIN_CSRF);
        body.set('action', action);
        Object.entries(values).forEach(function(entry) {
            body.set(entry[0], String(entry[1]));
        });
        const response = await fetch('execute/admin_chat_filter_words.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        });
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'filter_change_failed');
        }
        chatFilterWords = data.items || [];
        renderChatFilterWords();
        document.getElementById('chatFilterStatus').textContent =
            ADMIN_I18N.chatFilterSaved;
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

    function divisionFlagHtml(code)
    {
        const normalized = String(code || '').trim().toLowerCase();
        if (!/^[a-z0-9-]{2,10}$/.test(normalized)) {
            return '';
        }
        return '<img src="images/flags/' + encodeURIComponent(normalized)
            + '.png" alt="" style="width:25px;max-height:18px;object-fit:cover;vertical-align:-4px;margin-right:6px">';
    }

    function divisionFlagEmoji(code)
    {
        const normalized = String(code || '').trim().toUpperCase();
        if (!/^[A-Z]{2}$/.test(normalized)) {
            return '🏳';
        }
        return String.fromCodePoint(
            0x1F1E6 + normalized.charCodeAt(0) - 65,
            0x1F1E6 + normalized.charCodeAt(1) - 65
        );
    }

    function highlightFilteredChatText(original, filtered)
    {
        const originalChars = Array.from(String(original || ''));
        const filteredChars = Array.from(String(filtered || ''));
        let html = '';
        let index = 0;

        while (index < originalChars.length) {
            const isFiltered =
                filteredChars[index] === '*'
                && originalChars[index] !== '*';
            let end = index + 1;
            while (
                end < originalChars.length
                && (
                    filteredChars[end] === '*'
                    && originalChars[end] !== '*'
                ) === isFiltered
            ) {
                end += 1;
            }
            const text = originalChars.slice(index, end).join('');
            html += isFiltered
                ? '<span class="filtered-chat-word">' + escapeHtml(text) + '</span>'
                : escapeHtml(text);
            index = end;
        }
        return html;
    }

    document.querySelectorAll('.admin-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            const tabName =
                tab.dataset.tab;

            localStorage.setItem(
                'vfn_admin_active_tab',
                tabName
            );

            document.querySelectorAll('.admin-tab').forEach(item => item.classList.remove('is-active'));
            document.querySelectorAll('.admin-panel').forEach(item => item.classList.remove('is-active'));

            tab.classList.add('is-active');
            document.getElementById('admin-panel-' + tabName).classList.add('is-active');

            if (tabName === 'activity' && !activityLoaded) {
                activityLoaded = true;
                loadActivities();
            }

            if (tabName === 'players' && !playersLoaded) {
                playersLoaded = true;
                loadPlayers();
            }

            if (tabName === 'transfers' && !transfersLoaded) {
                transfersLoaded = true;
                loadTransfers();
            }

            if (tabName === 'moderation' && !moderationLoaded) {
                moderationLoaded = true;
                loadBanAppeals();
            }

            if (tabName === 'chat-filter' && !chatFilterLoaded) {
                chatFilterLoaded = true;
                loadChatFilterWords();
            }

            if (tabName === 'configuration' && !configurationLoaded) {
                configurationLoaded = true;
                loadConfigurationSettings();
            }
        });
    });

    if (CAN_MANAGE_CHAT_FILTER) {
        document.getElementById('chatFilterAddButton').addEventListener('click', function() {
            const input = document.getElementById('chatFilterWordInput');
            const word = input.value.trim();
            if (word === '') {
                document.getElementById('chatFilterStatus').textContent =
                    ADMIN_I18N.chatFilterInvalid;
                return;
            }
            changeChatFilterWord('add', {word: word})
                .then(function() { input.value = ''; })
                .catch(function() {
                    document.getElementById('chatFilterStatus').textContent =
                        ADMIN_I18N.chatFilterInvalid;
                });
        });
        document.getElementById('chatFilterSearchInput').addEventListener(
            'input',
            renderChatFilterWords
        );
        document.getElementById('chatFilterRows').addEventListener('click', function(event) {
            const button = event.target.closest('[data-filter-word-remove]');
            if (!button) {
                return;
            }
            button.disabled = true;
            changeChatFilterWord('remove', {id: button.dataset.filterWordRemove})
                .catch(function() {
                    button.disabled = false;
                    document.getElementById('chatFilterStatus').textContent =
                        ADMIN_I18N.serverError;
                });
        });
    }

    if (CAN_RESET_DATABASE) {
        document.getElementById('databaseResetButton').addEventListener('click', async function() {
            const button = this;
            const password = document.getElementById('databaseResetPassword').value;
            const confirmation = document.getElementById('databaseResetConfirmation').value;
            const status = document.getElementById('databaseResetStatus');

            if (password === '' || confirmation !== 'RESET VFN') {
                status.textContent = ADMIN_I18N.databaseResetInvalid;
                return;
            }

            if (!window.confirm(ADMIN_I18N.databaseResetConfirmDialog)) {
                return;
            }

            button.disabled = true;
            status.textContent = ADMIN_I18N.databaseResetRunning;

            const body = new URLSearchParams();
            body.set('csrf', ADMIN_CSRF);
            body.set('password', password);
            body.set('confirmation', confirmation);

            try {
                const response = await fetch('execute/admin_database_reset.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body.toString()
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'database_reset_failed');
                }
                status.textContent = ADMIN_I18N.databaseResetComplete;
                window.setTimeout(function() {
                    localStorage.removeItem('vfn_admin_monitor_frequencies');
                    localStorage.removeItem('vfn_admin_monitor_all');
                    location.href = 'index.php?type=success&message=database_reset_complete';
                }, 1200);
            } catch (error) {
                status.textContent = ADMIN_I18N.databaseResetInvalid;
                button.disabled = false;
            }
        });
    }

    if (CAN_RESET_DATABASE) {
        document.getElementById('configurationSaveButton').addEventListener('click', async function() {
            const button = this;
            const status = document.getElementById('configurationStatus');
            const settings = {};

            document.querySelectorAll('[data-configuration-key]').forEach(function(input) {
                const key = input.dataset.configurationKey;
                const definition = configurationDefinitions[key] || {};

                if (definition.type === 'boolean') {
                    settings[key] = input.value === 'true';
                } else if (definition.type === 'integer') {
                    settings[key] = Number.parseInt(input.value, 10);
                } else if (definition.type === 'number') {
                    settings[key] = Number.parseFloat(input.value);
                } else {
                    settings[key] = input.value;
                }
            });

            button.disabled = true;
            const body = new URLSearchParams();
            body.set('csrf', ADMIN_CSRF);
            body.set('settings', JSON.stringify(settings));

            try {
                const response = await fetch('execute/admin_config_settings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body.toString()
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'configuration_save_failed');
                }
                renderConfigurationFields(data);
                status.textContent = ADMIN_I18N.configurationSaved;
            } catch (error) {
                status.textContent = ADMIN_I18N.configurationInvalid;
            } finally {
                button.disabled = false;
            }
        });
    }

    document.getElementById('transferRows').addEventListener('click', function(event) {
        const button = event.target.closest('[data-transfer-action]');
        if (!button) {
            return;
        }
        button.disabled = true;
        updateTransfer(button.dataset.transferId, button.dataset.transferAction)
            .catch(function() {
                button.disabled = false;
                alert(ADMIN_I18N.serverError);
            });
    });

    if (CAN_MANAGE_MODERATION) {
        document.getElementById('moderationRows').addEventListener('click', function(event) {
            const button = event.target.closest('[data-appeal-action]');
            if (!button) return;
            const reason = window.prompt(ADMIN_I18N.moderationReviewReason);
            if (reason === null || reason.trim() === '') return;
            button.disabled = true;
            updateBanAppeal(button.dataset.appealId, button.dataset.appealAction, reason.trim())
                .catch(function() {
                    button.disabled = false;
                    alert(ADMIN_I18N.serverError);
                });
        });
    }

    document.getElementById('staffChatSendButton').addEventListener('click', async function() {
        const frequencyInput = document.getElementById('staffChatFrequency');
        const messageInput = document.getElementById('staffChatMessage');
        const status = document.getElementById('staffChatStatus');
        const frequency = normalizeFrequency(frequencyInput.value);
        if (!frequency || messageInput.value.trim() === '') {
            status.textContent = ADMIN_I18N.invalidFrequency;
            return;
        }
        const body = new URLSearchParams();
        body.set('frequency', frequency);
        body.set('message', messageInput.value.trim());
        body.set('scope', document.getElementById('staffChatScope').value);
        body.set('reference_user_id', document.getElementById('staffChatReferencePilot').value);
        body.set('range_nm', document.getElementById('staffChatRange').value);
        body.set('csrf', ADMIN_CSRF);
        try {
            const response = await fetch('execute/admin_chat_send.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            });
            const data = await response.json();
            if (!data.success) {
                throw new Error('send_failed');
            }
            messageInput.value = '';
            status.textContent = ADMIN_I18N.staffChatSent;
            if (!monitorAllFrequencies && !monitoredFrequencies.includes(frequency)) {
                monitoredFrequencies.push(frequency);
                saveMonitorState();
                renderFrequencies();
            }
            if (data.message) {
                appendMessages([data.message]);
            } else {
                refreshChatFilters();
            }
        } catch (error) {
            status.textContent = ADMIN_I18N.serverError;
        }
    });

    async function loadStaffReferencePilots()
    {
        const select = document.getElementById('staffChatReferencePilot');
        try {
            const response = await fetch(
                'execute/get_pilots.php?protection=<?php echo rawurlencode((string)$getPilotsProtection); ?>'
            );
            const data = await response.json();
            const previous = select.value;
            select.innerHTML = '';
            ((data.pilots && data.pilots.items) || []).forEach(function(pilot) {
                const option = document.createElement('option');
                option.value = String(pilot.user_id);
                option.textContent = pilot.callsign + ' — ' + pilot.com1 + ' / ' + pilot.com2;
                select.appendChild(option);
            });
            if ([...select.options].some(option => option.value === previous)) {
                select.value = previous;
            }
        } catch (error) {
            select.innerHTML = '';
        }
    }

    document.getElementById('staffChatScope').addEventListener('change', function() {
        document.getElementById('staffChatRegionFields').hidden = this.value !== 'regional';
        if (this.value === 'regional') loadStaffReferencePilots();
    });

    if (CAN_SEND_ANNOUNCEMENT) {
        document.getElementById('adminAnnouncementSendButton').addEventListener('click', async function() {
            const button = this;
            const messageInput = document.getElementById('adminAnnouncementMessage');
            const status = document.getElementById('adminAnnouncementStatus');
            const message = messageInput.value.trim();

            if (message === '') {
                status.textContent = ADMIN_I18N.announcementInvalid;
                return;
            }

            if (!window.confirm(ADMIN_I18N.announcementConfirm)) {
                return;
            }

            button.disabled = true;
            const body = new URLSearchParams();
            body.set('csrf', ADMIN_CSRF);
            body.set('message', message);

            try {
                const response = await fetch('execute/admin_announcement_send.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body.toString()
                });
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'announcement_failed');
                }
                messageInput.value = '';
                status.textContent =
                    ADMIN_I18N.announcementSent.replace(
                        '{count}',
                        String(data.recipient_count || 0)
                    );
                activityLoaded = true;
                loadActivities();
            } catch (error) {
                status.textContent = ADMIN_I18N.announcementInvalid;
            } finally {
                button.disabled = false;
            }
        });
    }

    [
        'chatSearchInput',
        'chatUserFilter',
        'chatFrequencyFilter',
        'chatTypeFilter',
        'chatDateFrom',
        'chatDateTo'
    ].forEach(function(id) {
        document.getElementById(id).addEventListener('input', function() {
            window.clearTimeout(chatFilterTimer);
            chatFilterTimer = window.setTimeout(refreshChatFilters, 300);
        });
    });

    [
        'playerSearchInput',
        'playerCountryFilter',
        'playerDivisionFilter',
        'playerRankFilter',
        'playerStatusFilter',
        'playerOnlineFilter'
    ].forEach(function(id) {
        const element = document.getElementById(id);
        element.addEventListener(
            element.tagName === 'SELECT' ? 'change' : 'input',
            function() {
                playerPage = 1;
                window.clearTimeout(playerFilterTimer);
                playerFilterTimer = window.setTimeout(loadPlayers, 300);
            }
        );
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

        if (voiceProcessor) {
            voiceProcessor.onaudioprocess = null;
            voiceProcessor.disconnect();
            voiceProcessor = null;
        }

        if (voiceInputSource) {
            voiceInputSource.disconnect();
            voiceInputSource = null;
        }

        if (voiceSilentGain) {
            voiceSilentGain.disconnect();
            voiceSilentGain = null;
        }

        voiceAnalyser = null;
        voiceLevelData = null;
        setVoiceMeter('tx', 0, false);
    }

    function bytesToBase64(bytes)
    {
        let binary = '';

        for (let index = 0; index < bytes.length; index++) {
            binary += String.fromCharCode(bytes[index]);
        }

        return btoa(binary);
    }

    function base64ToBlob(base64, type)
    {
        const binary =
            atob(base64);

        const bytes =
            new Uint8Array(binary.length);

        for (let index = 0; index < binary.length; index++) {
            bytes[index] =
                binary.charCodeAt(index);
        }

        return new Blob([bytes], { type });
    }

    function unlockVoicePlayback()
    {
        voicePlaybackContext =
            voicePlaybackContext ||
            new (window.AudioContext || window.webkitAudioContext)();

        if (voicePlaybackContext.state === 'suspended') {
            voicePlaybackContext.resume().catch(function() {});
        }
    }

    function downsampleAudio(input, inputRate, outputRate)
    {
        if (outputRate >= inputRate) {
            return new Float32Array(input);
        }

        const ratio =
            inputRate / outputRate;

        const outputLength =
            Math.max(1, Math.round(input.length / ratio));

        const output =
            new Float32Array(outputLength);

        for (let outputIndex = 0; outputIndex < outputLength; outputIndex++) {
            const start =
                Math.floor(outputIndex * ratio);

            const end =
                Math.min(input.length, Math.floor((outputIndex + 1) * ratio));

            let sum = 0;
            let count = 0;

            for (let inputIndex = start; inputIndex < end; inputIndex++) {
                sum += input[inputIndex];
                count++;
            }

            output[outputIndex] =
                count > 0 ? sum / count : 0;
        }

        return output;
    }

    function encodePcm16(samples)
    {
        const bytes =
            new Uint8Array(samples.length * 2);

        const view =
            new DataView(bytes.buffer);

        for (let index = 0; index < samples.length; index++) {
            const sample =
                Math.max(-1, Math.min(1, samples[index]));

            view.setInt16(
                index * 2,
                sample < 0
                    ? sample * 0x8000
                    : sample * 0x7fff,
                true
            );
        }

        return bytesToBase64(bytes);
    }

    function decodePcm16(base64)
    {
        const binary =
            atob(base64);

        const sampleCount =
            Math.floor(binary.length / 2);

        const samples =
            new Float32Array(sampleCount);

        for (let index = 0; index < sampleCount; index++) {
            const low =
                binary.charCodeAt(index * 2);

            const high =
                binary.charCodeAt(index * 2 + 1);

            let value =
                low | (high << 8);

            if (value >= 0x8000) {
                value -= 0x10000;
            }

            samples[index] =
                value / 0x8000;
        }

        return samples;
    }

    function getAudioLevel(samples)
    {
        if (!samples || samples.length === 0) {
            return 0;
        }

        let sum = 0;

        for (let index = 0; index < samples.length; index++) {
            sum += samples[index] * samples[index];
        }

        return Math.min(100, Math.sqrt(sum / samples.length) * 550);
    }

    async function playIncomingVoice(payload)
    {
        if (!payload || typeof payload.payload !== 'string') {
            return;
        }

        const codec =
            String(payload.codec || '');

        if (codec === 'pcm16') {
            const samples =
                decodePcm16(payload.payload);

            if (samples.length === 0) {
                return;
            }

            setVoiceRxActivity(
                true,
                getAudioLevel(samples)
            );

            voicePlaybackContext =
                voicePlaybackContext ||
                new (window.AudioContext || window.webkitAudioContext)();

            if (voicePlaybackContext.state === 'suspended') {
                await voicePlaybackContext.resume();
            }

            const sampleRate =
                Number(payload.sampleRate || 16000);

            const buffer =
                voicePlaybackContext.createBuffer(
                    1,
                    samples.length,
                    sampleRate
                );

            buffer.copyToChannel(samples, 0);

            const source =
                voicePlaybackContext.createBufferSource();

            source.buffer =
                buffer;

            source.connect(voicePlaybackContext.destination);

            if (
                voicePlaybackTime < voicePlaybackContext.currentTime ||
                voicePlaybackTime > voicePlaybackContext.currentTime + 0.75
            ) {
                voicePlaybackTime =
                    voicePlaybackContext.currentTime + 0.04;
            }

            source.start(voicePlaybackTime);

            voicePlaybackTime +=
                buffer.duration;

            return;
        }

        let mimeType =
            'audio/webm';

        if (codec === 'webm-opus') {
            mimeType =
                'audio/webm;codecs=opus';
        } else if (codec === 'mp4') {
            mimeType =
                'audio/mp4';
        }

        const audio =
            new Audio();

        const selectedOutput =
            document.getElementById('voiceOutputDeviceSelect').value;

        if (selectedOutput && typeof audio.setSinkId === 'function') {
            try {
                await audio.setSinkId(selectedOutput);
            } catch (error) {
                console.warn('Voice output device could not be selected.', error);
            }
        }

        const url =
            URL.createObjectURL(base64ToBlob(payload.payload, mimeType));

        audio.src =
            url;

        audio.onended =
            function() {
                URL.revokeObjectURL(url);
            };

        try {
            await audio.play();
        } catch (error) {
            console.warn('Incoming voice could not be played.', error);
        }
    }

    function setVoiceRxActivity(active, level)
    {
        if (voiceRxResetTimer) {
            clearTimeout(voiceRxResetTimer);
            voiceRxResetTimer = null;
        }

        setVoiceMeter(
            'rx',
            active ? Math.max(1, Number(level || 0)) : 0,
            active
        );

        if (active) {
            voiceRxResetTimer =
                setTimeout(function() {
                    setVoiceMeter('rx', 0, false);

                    if (voiceConnected) {
                        document.getElementById('voiceReceiverStatus').textContent =
                            ADMIN_I18N.voiceConnected;
                    }
                }, 450);
        }
    }

    function sendVoiceSocket(payload)
    {
        if (!voiceSocket || voiceSocket.readyState !== WebSocket.OPEN) {
            return false;
        }

        voiceSocket.send(JSON.stringify(payload));
        return true;
    }

    function getVoiceMonitorPayload(frequency)
    {
        const isGlobalUnicom = frequency === '122.800';
        const airportField = document.getElementById('voiceMonitorAirportIcao');
        const savedAirport = localStorage.getItem('vfn_admin_voice_monitor_airport') || '';
        return {
            type: 'monitor',
            frequency,
            global: isGlobalUnicom,
            airportIcao: isGlobalUnicom
                ? ''
                : String(airportField && airportField.value ? airportField.value : savedAirport).trim().toUpperCase(),
            rangeNm: Number(document.getElementById('voiceMonitorRange').value)
        };
    }

    function refreshVoiceMonitorLocationFields()
    {
        const frequency = normalizeFrequency(
            document.getElementById('voiceFrequencyInput').value
        ) || voiceCurrentFrequency;
        document.getElementById('voiceMonitorLocationRow').hidden =
            frequency === '122.800';
    }

    function connectVoiceSocket(frequency)
    {
        if (voiceConnectPromise) {
            return voiceConnectPromise;
        }

        voiceShouldReconnect =
            true;

        localStorage.setItem(
            'vfn_admin_voice_frequency',
            frequency
        );

        voiceConnectPromise = new Promise(function(resolve, reject) {
            let settled = false;

            const finish = function(error) {
                if (settled) {
                    return;
                }

                settled = true;

                if (error) {
                    reject(error);
                } else {
                    resolve();
                }
            };

            if (!VOICE_SESSION_TOKEN) {
                finish(new Error(ADMIN_I18N.voiceAuthMissing));
                return;
            }

            if (voiceSocket && voiceConnected) {
                sendVoiceSocket(getVoiceMonitorPayload(frequency));
                finish();
                return;
            }

            if (voiceSocket) {
                voiceSocket.close();
            }

            document.getElementById('voiceReceiverStatus').textContent =
                ADMIN_I18N.voiceConnecting;

            voiceSocket =
                new WebSocket(VOICE_WS_URL);

            const connectTimeout =
                setTimeout(function() {
                    if (voiceSocket) {
                        voiceSocket.close();
                    }

                    finish(new Error(ADMIN_I18N.voiceConnectionFailed));
                }, 10000);

            voiceSocket.addEventListener('open', function() {
                sendVoiceSocket({
                    type: 'hello',
                    token: VOICE_SESSION_TOKEN,
                    callsign: VOICE_CALLSIGN,
                    com1: frequency,
                    com2: frequency,
                    txCom: 1
                });
            });

            voiceSocket.addEventListener('message', function(event) {
                let payload =
                    null;

                try {
                    payload =
                        JSON.parse(event.data);
                } catch (error) {
                    return;
                }

                if (payload.type === 'hello' && payload.success) {
                    voiceConnected = true;
                    sendVoiceSocket(getVoiceMonitorPayload(frequency));
                    if (CAN_CONTROL_VOICE_TEST) {
                        sendVoiceSocket({type: 'test_source_status'});
                    }
                    document.getElementById('voiceReceiverStatus').textContent =
                        ADMIN_I18N.voiceConnected;
                    clearTimeout(connectTimeout);
                    finish();
                    return;
                }

                if (payload.type === 'monitor' && payload.success) {
                    document.getElementById('voiceReceiverStatus').textContent =
                        ADMIN_I18N.voiceConnected;
                    return;
                }

                if (payload.type === 'audio') {
                    document.getElementById('voiceReceiverStatus').textContent =
                        ADMIN_I18N.voiceReceiving + ' ' + String(payload.from || '');
                    playIncomingVoice(payload).catch(function(error) {
                        console.warn('Incoming voice playback failed.', error);
                    });
                    return;
                }

                if (payload.type === 'rx') {
                    return;
                }

                if (payload.type === 'tx' && payload.busy) {
                    voiceTransmitting = false;
                    document.getElementById('voiceTransmitButton')
                        .classList.remove('is-transmitting');
                    updateVoiceTransmitButton();
                    stopVoiceInputMonitor();
                    document.getElementById('voiceReceiverStatus').textContent =
                        ADMIN_I18N.voiceChannelBusy.replace(
                            '{callsign}', String(payload.from || 'RADIO')
                        );
                    return;
                }

                if (payload.type === 'test_source_status' && CAN_CONTROL_VOICE_TEST) {
                    updateVoiceTestStatus(payload);
                    return;
                }

                if (payload.type === 'error') {
                    voiceShouldReconnect = false;
                    document.getElementById('voiceReceiverStatus').textContent =
                        String(payload.message || ADMIN_I18N.voiceConnectionFailed);
                    clearTimeout(connectTimeout);
                    finish(new Error(String(payload.message || ADMIN_I18N.voiceConnectionFailed)));
                }
            });

            voiceSocket.addEventListener('close', function() {
                voiceConnected =
                    false;

                clearTimeout(connectTimeout);

                finish(new Error(ADMIN_I18N.voiceConnectionFailed));

                if (voiceShouldReconnect && !voiceReconnectTimer) {
                    voiceReconnectTimer =
                        setTimeout(function() {
                            voiceReconnectTimer = null;

                            connectVoiceSocket(voiceCurrentFrequency)
                                .catch(function() {});
                        }, 2000);
                }
            });

            voiceSocket.addEventListener('error', function() {
                document.getElementById('voiceReceiverStatus').textContent =
                    ADMIN_I18N.voiceConnectionFailed;
                clearTimeout(connectTimeout);
                finish(new Error(ADMIN_I18N.voiceConnectionFailed));
            });
        });

        const pendingConnection =
            voiceConnectPromise;

        pendingConnection.then(
            function() {
                if (voiceConnectPromise === pendingConnection) {
                    voiceConnectPromise = null;
                }
            },
            function() {
                if (voiceConnectPromise === pendingConnection) {
                    voiceConnectPromise = null;
                }
            }
        );

        return pendingConnection;
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

        voiceInputSource =
            voiceAudioContext.createMediaStreamSource(voiceMediaStream);

        voiceAnalyser =
            voiceAudioContext.createAnalyser();
        voiceAnalyser.fftSize =
            256;
        voiceLevelData =
            new Uint8Array(voiceAnalyser.frequencyBinCount);

        voiceInputSource.connect(voiceAnalyser);

        voiceProcessor =
            voiceAudioContext.createScriptProcessor(2048, 1, 1);

        voiceSilentGain =
            voiceAudioContext.createGain();

        voiceSilentGain.gain.value =
            0;

        voiceProcessor.onaudioprocess =
            function(event) {
                if (!voiceTransmitting || !voiceConnected) {
                    return;
                }

                const input =
                    event.inputBuffer.getChannelData(0);

                const sampleRate =
                    16000;

                const samples =
                    downsampleAudio(
                        input,
                        voiceAudioContext.sampleRate,
                        sampleRate
                    );

                sendVoiceSocket({
                    type: 'audio',
                    codec: 'pcm16',
                    sampleRate,
                    frequency: voiceCurrentFrequency,
                    sequence: ++voiceSequence,
                    payload: encodePcm16(samples)
                });
            };

        voiceInputSource.connect(voiceProcessor);
        voiceProcessor.connect(voiceSilentGain);
        voiceSilentGain.connect(voiceAudioContext.destination);

        updateVoiceInputMeter();
    }

    async function setVoiceTransmitting(active)
    {
        const button =
            document.getElementById('voiceTransmitButton');

        voiceTransmitting =
            active;

        button.classList.toggle('is-transmitting', active);
        updateVoiceTransmitButton();

        if (active) {
            try {
                await connectVoiceSocket(voiceCurrentFrequency);

                if (!voiceTransmitting) {
                    return;
                }

                await startVoiceInputMonitor();

                if (!voiceTransmitting) {
                    stopVoiceInputMonitor();
                    return;
                }

                sendVoiceSocket({
                    type: 'ptt',
                    active: true,
                    txCom: 1,
                    frequency: voiceCurrentFrequency
                });

                document.getElementById('voiceReceiverStatus').textContent =
                    ADMIN_I18N.voiceTransmitPrepared;
            } catch (error) {
                voiceTransmitting = false;
                button.classList.remove('is-transmitting');
                updateVoiceTransmitButton();
                document.getElementById('voiceReceiverStatus').textContent =
                    ADMIN_I18N.devicePermissionHint;
            }
            return;
        }

        if (voiceConnected) {
            sendVoiceSocket({
                type: 'ptt',
                active: false,
                txCom: 1,
                frequency: voiceCurrentFrequency
            });
        }

        stopVoiceInputMonitor();
    }

    function updateVoiceTransmitButton()
    {
        const button =
            document.getElementById('voiceTransmitButton');

        if (!voiceContinuousMode) {
            button.textContent =
                ADMIN_I18N.voicePushToTalk;
            return;
        }

        button.textContent =
            voiceTransmitting
                ? ADMIN_I18N.voiceContinuousStop
                : ADMIN_I18N.voiceContinuousStart;
    }

    function updateVoiceTestStatus(payload)
    {
        const status = document.getElementById('voiceTestStatus');
        if (!status) return;
        status.textContent = payload.active
            ? ADMIN_I18N.voiceTestActive
                .replace('{frequency}', String(payload.frequency || '-'))
                .replace('{source}', String(payload.sourceName || '-'))
                .replace('{location}', String(payload.locationName || 'UNICOM'))
                .replace('{range}', payload.rangeNm ? String(payload.rangeNm) + ' NM' : 'global')
            : (payload.reason === 'error'
                ? String(payload.message || ADMIN_I18N.voiceTestInvalid)
                : ADMIN_I18N.voiceTestInactive);
    }

    async function loadVoiceTestReferencePilots()
    {
        const select = document.getElementById('voiceTestReferencePilot');
        if (!select) return;
        const response = await fetch(
            'execute/get_pilots.php?protection=<?php echo rawurlencode((string)$getPilotsProtection); ?>'
        );
        const data = await response.json();
        const previous = select.value;
        select.innerHTML = '';
        ((data.pilots && data.pilots.items) || []).forEach(function(pilot) {
            const option = document.createElement('option');
            option.value = String(pilot.user_id);
            option.textContent = String(pilot.callsign || pilot.username || pilot.user_id);
            select.appendChild(option);
        });
        if ([...select.options].some(option => option.value === previous)) select.value = previous;
    }

    function refreshVoiceTestFields()
    {
        if (!CAN_CONTROL_VOICE_TEST) return;
        const upload = document.getElementById('voiceTestSourceType').value === 'upload';
        document.getElementById('voiceTestStreamField').hidden = upload;
        document.getElementById('voiceTestUploadField').hidden = !upload;
        const regional = normalizeFrequency(document.getElementById('voiceTestFrequency').value) !== '122.800';
        const airport = regional && document.getElementById('voiceTestLocationType').value === 'airport';
        document.getElementById('voiceTestLocationTypeField').hidden = !regional;
        document.getElementById('voiceTestReferenceField').hidden = !regional || airport;
        document.getElementById('voiceTestAirportField').hidden = !airport;
        document.getElementById('voiceTestRangeField').hidden = !airport;
        if (regional && !airport) loadVoiceTestReferencePilots().catch(function() {});
    }

    async function startVoiceTestSource()
    {
        const status = document.getElementById('voiceTestStatus');
        const frequency = normalizeFrequency(document.getElementById('voiceTestFrequency').value);
        const sourceType = document.getElementById('voiceTestSourceType').value;
        if (!frequency) { status.textContent = ADMIN_I18N.invalidFrequency; return; }
        const locationType = document.getElementById('voiceTestLocationType').value;
        const referenceUserId = document.getElementById('voiceTestReferencePilot').value;
        const airportIcao = document.getElementById('voiceTestAirportIcao').value.trim().toUpperCase();
        if (frequency !== '122.800' && locationType === 'pilot' && !referenceUserId) {
            status.textContent = ADMIN_I18N.voiceTestReferenceMissing;
            return;
        }
        if (frequency !== '122.800' && locationType === 'airport' && !airportIcao) {
            status.textContent = ADMIN_I18N.voiceTestInvalid;
            return;
        }
        status.textContent = ADMIN_I18N.voiceTestStarting;
        const payload = {
            type: 'test_source_start', frequency, sourceType, locationType,
            referenceUserId, airportIcao,
            rangeNm: Number(document.getElementById('voiceTestRange').value),
            loop: document.getElementById('voiceTestLoop').checked
        };
        if (sourceType === 'stream') {
            payload.streamUrl = document.getElementById('voiceTestStreamUrl').value.trim();
            if (!payload.streamUrl) { status.textContent = ADMIN_I18N.voiceTestInvalid; return; }
            localStorage.setItem('vfn_admin_voice_test_stream_url', payload.streamUrl);
        } else {
            const file = document.getElementById('voiceTestAudioFile').files[0];
            if (!file) { status.textContent = ADMIN_I18N.voiceTestInvalid; return; }
            const form = new FormData();
            form.set('csrf', ADMIN_CSRF);
            form.set('audio', file);
            const response = await fetch('execute/admin_voice_upload.php', {method: 'POST', body: form});
            const result = await response.json();
            if (!result.success) throw new Error(ADMIN_I18N.voiceTestUploadFailed);
            payload.fileName = result.fileName;
        }
        await connectVoiceSocket(frequency);
        sendVoiceSocket(payload);
    }

    if (CAN_CONTROL_VOICE_TEST) {
        const voiceTestStreamUrl = document.getElementById('voiceTestStreamUrl');
        voiceTestStreamUrl.value =
            localStorage.getItem('vfn_admin_voice_test_stream_url') || '';
        voiceTestStreamUrl.addEventListener('input', function() {
            localStorage.setItem('vfn_admin_voice_test_stream_url', this.value.trim());
        });
        document.getElementById('voiceTestSourceType').addEventListener('change', refreshVoiceTestFields);
        document.getElementById('voiceTestFrequency').addEventListener('change', refreshVoiceTestFields);
        document.getElementById('voiceTestLocationType').addEventListener('change', refreshVoiceTestFields);
        document.getElementById('voiceTestStartButton').addEventListener('click', function() {
            startVoiceTestSource().catch(function(error) {
                document.getElementById('voiceTestStatus').textContent = error.message || ADMIN_I18N.voiceTestInvalid;
            });
        });
        document.getElementById('voiceTestStopButton').addEventListener('click', async function() {
            await connectVoiceSocket(normalizeFrequency(document.getElementById('voiceTestFrequency').value) || '122.800');
            sendVoiceSocket({type: 'test_source_stop'});
        });
        refreshVoiceTestFields();
    }

    document.getElementById('voiceRefreshDevicesButton').addEventListener('click', refreshVoiceDevices);

    document.getElementById('voiceConnectButton').addEventListener('click', async function() {
        const frequency =
            normalizeFrequency(document.getElementById('voiceFrequencyInput').value);

        if (!frequency) {
            alert(ADMIN_I18N.invalidFrequency);
            return;
        }
        if (frequency !== '122.800' &&
            !document.getElementById('voiceMonitorAirportIcao').value.trim()) {
            alert(ADMIN_I18N.voiceMonitorReferenceMissing);
            return;
        }

        voiceCurrentFrequency =
            frequency;

        if (voicePlaybackContext && voicePlaybackContext.state === 'suspended') {
            voicePlaybackContext.resume().catch(function() {});
        }

        document.getElementById('voiceFrequencyStatus').textContent =
            frequency;

        try {
            await connectVoiceSocket(frequency);
        } catch (error) {
            document.getElementById('voiceReceiverStatus').textContent =
                error.message || ADMIN_I18N.voiceConnectionFailed;
        }
    });

    document.getElementById('voiceDisconnectButton').addEventListener('click', async function() {
        voiceShouldReconnect =
            false;

        localStorage.removeItem(
            'vfn_admin_voice_frequency'
        );

        if (voiceReconnectTimer) {
            clearTimeout(voiceReconnectTimer);
            voiceReconnectTimer = null;
        }

        if (voiceTransmitting) {
            await setVoiceTransmitting(false);
        }

        voiceConnected =
            false;

        if (voiceSocket) {
            voiceSocket.close(1000, 'User disconnected');
            voiceSocket = null;
        }

        setVoiceMeter('tx', 0, false);
        setVoiceMeter('rx', 0, false);

        document.getElementById('voiceFrequencyStatus').textContent =
            '-';

        document.getElementById('voiceReceiverStatus').textContent =
            ADMIN_I18N.voiceDisconnected;
    });

    const voiceTransmitButton =
        document.getElementById('voiceTransmitButton');

    voiceTransmitButton.addEventListener('mousedown', function() {
        unlockVoicePlayback();

        if (voiceContinuousMode) {
            setVoiceTransmitting(!voiceTransmitting);
            return;
        }

        setVoiceTransmitting(true);
    });
    voiceTransmitButton.addEventListener('mouseup', function() {
        if (!voiceContinuousMode) {
            setVoiceTransmitting(false);
        }
    });
    voiceTransmitButton.addEventListener('mouseleave', function() {
        if (!voiceContinuousMode) {
            setVoiceTransmitting(false);
        }
    });
    voiceTransmitButton.addEventListener('touchstart', function(event) {
        event.preventDefault();
        unlockVoicePlayback();

        if (voiceContinuousMode) {
            setVoiceTransmitting(!voiceTransmitting);
            return;
        }

        setVoiceTransmitting(true);
    });
    voiceTransmitButton.addEventListener('touchend', function(event) {
        event.preventDefault();

        if (!voiceContinuousMode) {
            setVoiceTransmitting(false);
        }
    });

    const voiceContinuousModeInput =
        document.getElementById('voiceContinuousMode');

    voiceContinuousModeInput.checked =
        voiceContinuousMode;

    voiceContinuousModeInput.addEventListener('change', function() {
        voiceContinuousMode =
            voiceContinuousModeInput.checked;

        localStorage.setItem(
            'vfn_admin_voice_continuous',
            voiceContinuousMode ? '1' : '0'
        );

        if (!voiceContinuousMode && voiceTransmitting) {
            setVoiceTransmitting(false);
        }

        updateVoiceTransmitButton();
    });

    updateVoiceTransmitButton();

    renderFrequencies();
    pollMessages();
    refreshVoiceDevices();
    setInterval(pollMessages, 3000);

    const requestedAdminTab =
        new URLSearchParams(window.location.search).get('tab');
    const savedAdminTab =
        requestedAdminTab || localStorage.getItem('vfn_admin_active_tab');

    if (savedAdminTab) {
        const savedTabButton =
            document.querySelector(
                '.admin-tab[data-tab="' + CSS.escape(savedAdminTab) + '"]'
            );

        const savedTabPanel =
            document.getElementById(
                'admin-panel-' + savedAdminTab
            );

        if (savedTabButton && savedTabPanel) {
            document.querySelectorAll('.admin-tab').forEach(function(item) {
                item.classList.remove('is-active');
            });

            document.querySelectorAll('.admin-panel').forEach(function(item) {
                item.classList.remove('is-active');
            });

            savedTabButton.classList.add('is-active');
            savedTabPanel.classList.add('is-active');

            if (savedAdminTab === 'activity' && !activityLoaded) {
                activityLoaded = true;
                loadActivities();
            }

            if (savedAdminTab === 'players' && !playersLoaded) {
                playersLoaded = true;
                loadPlayers();
            }

            if (savedAdminTab === 'transfers' && !transfersLoaded) {
                transfersLoaded = true;
                loadTransfers();
            }

            if (savedAdminTab === 'moderation' && !moderationLoaded) {
                moderationLoaded = true;
                loadBanAppeals();
            }

            if (savedAdminTab === 'chat-filter' && !chatFilterLoaded) {
                chatFilterLoaded = true;
                loadChatFilterWords();
            }

            if (savedAdminTab === 'configuration' && !configurationLoaded) {
                configurationLoaded = true;
                loadConfigurationSettings();
            }
        }
    }

    document.addEventListener(
        'pointerdown',
        unlockVoicePlayback,
        { passive: true }
    );

    const voiceMonitorAirportIcao = document.getElementById('voiceMonitorAirportIcao');
    const voiceMonitorRange = document.getElementById('voiceMonitorRange');
    voiceMonitorAirportIcao.value =
        localStorage.getItem('vfn_admin_voice_monitor_airport') || '';
    voiceMonitorRange.value =
        localStorage.getItem('vfn_admin_voice_monitor_range') || '25';

    const savedVoiceFrequency =
        normalizeFrequency(
            localStorage.getItem('vfn_admin_voice_frequency')
        );

    if (savedVoiceFrequency) {
        voiceCurrentFrequency =
            savedVoiceFrequency;

        document.getElementById('voiceFrequencyInput').value =
            savedVoiceFrequency;

        document.getElementById('voiceFrequencyStatus').textContent =
            savedVoiceFrequency;

        setTimeout(function() {
            connectVoiceSocket(savedVoiceFrequency)
                .catch(function() {});
        }, 350);
    }


    voiceMonitorAirportIcao.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        localStorage.setItem('vfn_admin_voice_monitor_airport', this.value.trim());
    });
    voiceMonitorRange.addEventListener('change', function() {
        localStorage.setItem('vfn_admin_voice_monitor_range', this.value);
    });
    document.getElementById('voiceFrequencyInput').addEventListener(
        'input', refreshVoiceMonitorLocationFields
    );
    refreshVoiceMonitorLocationFields();
</script>
<?php endif; ?>
</body>
</html>
