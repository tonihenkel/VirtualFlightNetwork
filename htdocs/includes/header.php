<?php

if (!isset($projectName)) {
    $projectName = "Flight Radar Sim Project";
}

if (!isset($defaultTimezone) || trim($defaultTimezone) === '') {
    $defaultTimezone = "UTC";
}



$currentPage = basename($_SERVER['PHP_SELF']);

$currentLanguage = $_SESSION['language'] ?? 'en';
$currentLanguageDirection = function_exists('vfnLanguageMeta')
    ? (string)(vfnLanguageMeta($currentLanguage)['dir'] ?? 'ltr')
    : 'ltr';
$headerLanguages = function_exists('vfnLanguages') ? vfnLanguages() : [
    'en' => ['name' => 'English', 'flag' => 'gb'],
    'de' => ['name' => 'Deutsch', 'flag' => 'de'],
];
?>
<script>
document.documentElement.lang = <?php echo json_encode($currentLanguage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
document.documentElement.dir = <?php echo json_encode($currentLanguageDirection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php

function buildLanguageUrl(string $language): string
{
    $query = $_GET;
    $query['lang'] = $language;

    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($query);
}

function renderFlag(string $language): string
{
    $meta = function_exists('vfnLanguageMeta') ? vfnLanguageMeta($language) : ['flag' => 'gb'];
    $flag = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($meta['flag'] ?? 'gb')));
    return '<img class="flag" src="images/flags/' . htmlspecialchars($flag, ENT_QUOTES) . '.png" alt="">';
}


$unreadActivityCount = 0;
$pendingDivisionTransferCount = 0;
$pendingBanAppealCount = 0;
$pendingBugReportCount = 0;
$unreadPrivateMessageCount = 0;
$headerOpPermission =
    (int)($_SESSION['web_op_permission'] ?? 0);

if (isset($_SESSION['web_user_id'])) {
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $headerPdo = $pdo;
        } else {
            $headerPdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]
            );
        }

        require_once __DIR__ . '/web_session.php';

        if (!validateVfnWebSession($headerPdo)) {
            $headerOpPermission = 0;
        }

        if (!isset($_SESSION['web_user_id'])) {
            throw new RuntimeException('web_session_invalid');
        }

        $unreadActivityStmt = $headerPdo->prepare(
            "SELECT COUNT(*)
             FROM user_activity_log
             WHERE user_id = :user_id
               AND is_read = 0"
        );

        $unreadActivityStmt->execute([
            'user_id' => (int)$_SESSION['web_user_id']
        ]);

        $unreadActivityCount =
            (int)$unreadActivityStmt->fetchColumn();

        try {
            $privateMessageStmt = $headerPdo->prepare(
                "SELECT COUNT(*)
                 FROM chat_messages c
                 LEFT JOIN web_notification_state n ON n.user_id = :user_id
                 WHERE c.recipient_user_id = :user_id
                   AND c.sender_user_id <> :user_id
                   AND c.message_text LIKE '[PM]%'
                   AND c.id > COALESCE(n.last_private_message_id, 0)"
            );
            $privateMessageStmt->execute(['user_id'=>(int)$_SESSION['web_user_id']]);
            $unreadPrivateMessageCount=(int)$privateMessageStmt->fetchColumn();
        } catch (Throwable $ignored) {
            try {
                $fallbackPrivateStmt=$headerPdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE recipient_user_id=:uid AND sender_user_id<>:uid AND message_text LIKE '[PM]%'");
                $fallbackPrivateStmt->execute(['uid'=>(int)$_SESSION['web_user_id']]);
                $unreadPrivateMessageCount=(int)$fallbackPrivateStmt->fetchColumn();
            } catch (Throwable $ignoredAgain) {
                $unreadPrivateMessageCount=0;
            }
        }

        $opPermissionStmt = $headerPdo->prepare(
            "SELECT op_permission
             FROM users
             WHERE id = :user_id
             LIMIT 1"
        );

        $opPermissionStmt->execute([
            'user_id' => (int)$_SESSION['web_user_id']
        ]);

        $headerOpPermission =
            (int)$opPermissionStmt->fetchColumn();

        $_SESSION['web_op_permission'] =
            $headerOpPermission;

        if ($headerOpPermission >= 1) {
            try {
                $pendingBugReportCount = (int)$headerPdo->query(
                    "SELECT COUNT(*) FROM bug_reports
                     WHERE status IN ('new','open','in_progress','waiting_user','testing')"
                )->fetchColumn();
            } catch (Throwable $ignoredBugReports) {
                $pendingBugReportCount = 0;
            }
        }

        if ($headerOpPermission > 1) {
            $pendingDivisionTransferCount = (int)$headerPdo
                ->query(
                    "SELECT COUNT(*)
                     FROM division_transfer_requests
                     WHERE status = 'pending'"
                )
                ->fetchColumn();
        }

        if ($headerOpPermission >= 4) {
            $pendingBanAppealCount = (int)$headerPdo
                ->query(
                    "SELECT COUNT(*)
                     FROM ban_appeal_requests
                     WHERE status = 'pending'"
                )
                ->fetchColumn();
        }

    } catch (Exception $e) {
        $unreadActivityCount = 0;
    }
}
?>



<style>
    .topbar {
        width: 100%;
        padding: 22px 40px;

        display: flex;
        align-items: center;
        justify-content: space-between;

        background: rgba(0, 0, 0, 0.35);

        border-bottom:
            1px solid rgba(255, 255, 255, 0.12);

        backdrop-filter: blur(12px);

        position: relative;

        z-index: 50000;
    }

    .logo {
        font-size: 22px;
        font-weight: bold;
        letter-spacing: 0.5px;

        color: white;
    }

    .logo a {
        color: white;
        text-decoration: none;
    }

    .nav {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .nav a,
    .nav button,
    .nav span {
        color: #d7e8ff;

        text-decoration: none;

        background: transparent;
        border: 0;

        cursor: pointer;

        font-size: 14px;
        font-family: Arial, sans-serif;
    }

    .nav a:hover,
    .nav button:hover {
        color: #ffffff;
    }

    .language-dropdown {
        position: relative;
    }

    .language-button {
        background: rgba(255,255,255,0.08);

        border:
            1px solid rgba(255,255,255,0.15);

        border-radius: 8px;

        padding: 8px 12px;

        color: white;

        cursor: pointer;

        min-width: 145px;

        text-align: left;

        font-family: Arial, sans-serif;

        display: flex;
        align-items: center;
        gap: 8px;
    }

    .language-menu {
        position: absolute;

        top: calc(100% + 8px);
        right: 0;

        min-width: 170px;

        background:
            rgba(10,15,25,0.97);

        border:
            1px solid rgba(255,255,255,0.12);

        border-radius: 10px;

        overflow: hidden;

        display: none;

        box-shadow:
            0 20px 50px rgba(0,0,0,0.45);

        z-index: 60000;
    }

    .language-menu.open {
        display: block;
    }

    .language-item {
        display: flex;
        align-items: center;
        gap: 8px;

        padding: 12px 14px;

        color: white;

        text-decoration: none;

        transition:
            background 0.15s ease;

        font-family: Arial, sans-serif;
    }

    .language-item:hover {
        background:
            rgba(255,255,255,0.08);
    }

    .flag {
        width: 22px;
        height: 14px;

        display: inline-block;

        border-radius: 2px;

        overflow: hidden;

        box-shadow:
            0 0 0 1px rgba(255,255,255,0.35);

        flex-shrink: 0;
        object-fit: cover;
    }

    .flag.flag-de {
        background:
            linear-gradient(
                to bottom,
                #000000 0%,
                #000000 33.333%,
                #dd0000 33.333%,
                #dd0000 66.666%,
                #ffce00 66.666%,
                #ffce00 100%
            ) !important;
    }

    .flag-en {
        position: relative;
        background: #012169;
    }

    .flag-en::before {
        content: "";

        position: absolute;

        inset: 0;

        background:
            linear-gradient(
                27deg,
                transparent 42%,
                #ffffff 42%,
                #ffffff 48%,
                #c8102e 48%,
                #c8102e 52%,
                #ffffff 52%,
                #ffffff 58%,
                transparent 58%
            ),

            linear-gradient(
                153deg,
                transparent 42%,
                #ffffff 42%,
                #ffffff 48%,
                #c8102e 48%,
                #c8102e 52%,
                #ffffff 52%,
                #ffffff 58%,
                transparent 58%
            );
    }

    .flag-en::after {
        content: "";

        position: absolute;

        inset: 0;

        background:
            linear-gradient(
                to bottom,
                transparent 36%,
                #ffffff 36%,
                #ffffff 64%,
                transparent 64%
            ),

            linear-gradient(
                to right,
                transparent 39%,
                #ffffff 39%,
                #ffffff 61%,
                transparent 61%
            ),

            linear-gradient(
                to bottom,
                transparent 42%,
                #c8102e 42%,
                #c8102e 58%,
                transparent 58%
            ),

            linear-gradient(
                to right,
                transparent 45%,
                #c8102e 45%,
                #c8102e 55%,
                transparent 55%
            );
    }

@media (max-width: 950px) {

    .topbar {
        padding: 18px 25px;

        flex-direction: column;

        gap: 12px;

        align-items: flex-start;
    }

    .nav {
        flex-wrap: wrap;
    }
}

.ProjectLogo {
    height: 40px;
}


        .activity-notification-dot {
            display: inline-block;
            width: 9px;
            height: 9px;
            margin-left: 8px;
            background: #ff3b3b;
            border-radius: 50%;
            box-shadow:  0 0 8px rgba(255, 59, 59, 0.9);
            vertical-align: middle;
        }

        .header-notification-dot {
            display: inline-block !important;
            width: 8px;
            height: 8px;
            margin-left: 6px;
            background: #ff3b3b !important;
            background-color: #ff3b3b !important;
            border-radius: 50%;
            box-shadow: 0 0 8px rgba(255, 59, 59, 0.9);
            position: relative;
            top: -1px;
            vertical-align: middle;
            z-index: 1;
        }

</style>

<header class="topbar">

    <div class="logo">
        <a href="index.php">
            <img src="images/logo/logo.png" class="ProjectLogo">
        </a>
    </div>

    <nav class="nav">

        <a href="map.php">
            <?php echo htmlspecialchars(t('nav_live_map')); ?>
        </a>

        <a href="statistics.php">
            <?php echo htmlspecialchars(t('nav_statistics')); ?>
        </a>

        <a href="divisions.php">
            <?php echo htmlspecialchars(t('nav_divisions')); ?>
        </a>

        <a href="index.php#download">
            <?php echo htmlspecialchars(t('nav_download')); ?>
        </a>

        <a href="bug_reports.php">
            <?php echo htmlspecialchars(t('nav_bug_reports')); ?>
            <?php if ($headerOpPermission >= 1 && $pendingBugReportCount > 0): ?><span class="header-notification-dot"></span><?php endif; ?>
        </a>

        <?php if (isset($_SESSION['web_user_id'])): ?>

            <a href="flightplans.php">
                <?php echo htmlspecialchars(t('nav_flightplans')); ?>
            </a>

            <a href="messages.php">
                <?php echo htmlspecialchars(t('nav_messages')); ?>
                <?php if ($unreadPrivateMessageCount > 0): ?><span class="header-notification-dot"></span><?php endif; ?>
            </a>

            <?php if (!empty($atcLoginEnabled) || $headerOpPermission >= 5): ?>
                <button type="button" onclick="openAtcClient()">
                    <?php echo htmlspecialchars(t('nav_atc_login')); ?>
                </button>
            <?php endif; ?>

            <a href="notifications.php" title="<?php echo htmlspecialchars(t('nav_notifications')); ?>">
                🔔
                <?php if ($unreadActivityCount > 0 || $unreadPrivateMessageCount > 0 || $pendingDivisionTransferCount > 0 || $pendingBanAppealCount > 0): ?><span class="header-notification-dot"></span><?php endif; ?>
            </a>

            <?php if ($headerOpPermission >= 1): ?>
                <a href="<?php echo $headerOpPermission > 1 ? 'admin.php' : 'bug_reports.php'; ?>">
                    <?php echo htmlspecialchars(t('nav_admin_panel')); ?>
                    <?php if ($pendingDivisionTransferCount > 0 || $pendingBanAppealCount > 0 || $pendingBugReportCount > 0): ?>
                        <span class="header-notification-dot"></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <span style="color:#00ffcc;font-weight:bold;">
            <a href="profile.php?id=<?php echo (int)$_SESSION['web_user_id']; ?>"
               style="color:#00ffcc;font-weight:bold;text-decoration:none;">
                <?php
                echo htmlspecialchars(
                    $_SESSION['web_real_name']
                    ?? $_SESSION['web_username']
                    ?? 'User'
                );
                ?>

                <?php if ($unreadActivityCount > 0): ?>
                    <span class="header-notification-dot"></span>
                <?php endif; ?>
            </a>
            </span>

            <a href="web_logout.php?return_to=index.php">
                <?php echo htmlspecialchars(t('nav_logout')); ?>
            </a>

        <?php else: ?>

            <button type="button" onclick="openModal('loginModal')">
                <?php echo htmlspecialchars(t('nav_login')); ?>
            </button>

            <?php if (empty($maintenanceMode) && !empty($registrationEnabled)): ?>
                <button type="button" onclick="openModal('registerModal')">
                    <?php echo htmlspecialchars(t('nav_register')); ?>
                </button>
            <?php endif; ?>

        <?php endif; ?>

        <div class="language-dropdown">

            <button type="button"
                    class="language-button"
                    onclick="toggleLanguageMenu()">

                <?php echo renderFlag($currentLanguage); ?>

                <?php echo htmlspecialchars((string)($headerLanguages[$currentLanguage]['name'] ?? 'English')); ?>

            </button>

            <div class="language-menu" id="languageMenu">

                <?php foreach ($headerLanguages as $languageCode => $languageMeta): ?>
                    <a class="language-item" href="<?php echo htmlspecialchars(buildLanguageUrl($languageCode)); ?>">
                        <?php echo renderFlag($languageCode); ?>
                        <?php echo htmlspecialchars((string)$languageMeta['name']); ?>
                    </a>
                <?php endforeach; ?>

            </div>

        </div>

    </nav>

</header>

<script>

    const DEFAULT_TIMEZONE =
        <?php echo json_encode($defaultTimezone ?? 'UTC'); ?>;

    function formatUtcTime(date = new Date())
    {
        return date.toLocaleTimeString(
            'en-GB',
            {
                timeZone: DEFAULT_TIMEZONE,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }
        ) + ' ' + DEFAULT_TIMEZONE;
    }

    function toggleLanguageMenu()
    {
        document
            .getElementById('languageMenu')
            .classList
            .toggle('open');
    }

    function openAtcClient()
    {
        const width = Math.max(1024, window.screen.availWidth || 1400);
        const height = Math.max(700, window.screen.availHeight || 900);
        let activeAtc = null;
        try {
            activeAtc = JSON.parse(localStorage.getItem('vfn-atc-client-active') || 'null');
        } catch (error) {
            activeAtc = null;
        }
        if (activeAtc && Date.now() - Number(activeAtc.timestamp || 0) < 15000) {
            try {
                localStorage.setItem('vfn-atc-focus-request', String(Date.now()));
                if (typeof BroadcastChannel !== 'undefined') {
                    const channel = new BroadcastChannel('vfn-atc-client');
                    channel.postMessage({type: 'focus'});
                    channel.close();
                }
                const existingWindow = window.open('', 'vfnAtcRadarClient');
                if (existingWindow) existingWindow.focus();
            } catch (error) {
                // The browser may restrict focusing an existing popup.
            }
            return;
        }
        const radarWindow = window.open(
            'atc.php',
            'vfnAtcRadarClient',
            'popup=yes,width=' + width + ',height=' + height + ',left=0,top=0,resizable=yes,scrollbars=no'
        );
        if (radarWindow) {
            radarWindow.focus();
            try {
                radarWindow.moveTo(0, 0);
                radarWindow.resizeTo(width, height);
            } catch (error) {
                // Window placement is controlled by the browser.
            }
        }
    }

    document.addEventListener(
        'click',
        function(event)
        {
            const menu = document.getElementById('languageMenu');
            const dropdown = document.querySelector('.language-dropdown');

            if (
                menu &&
                dropdown &&
                !dropdown.contains(event.target)
            ) {
                menu.classList.remove('open');
            }
        }
    );

</script>
