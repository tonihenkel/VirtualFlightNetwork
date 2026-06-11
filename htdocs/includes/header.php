<?php

if (!isset($projectName)) {
    $projectName = "Flight Radar Sim Project";
}

if (!isset($defaultTimezone) || trim($defaultTimezone) === '') {
    $defaultTimezone = "UTC";
}

$currentPage = basename($_SERVER['PHP_SELF']);

$currentLanguage = $_SESSION['language'] ?? 'en';

function buildLanguageUrl(string $language): string
{
    $query = $_GET;
    $query['lang'] = $language;

    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($query);
}

function renderFlag(string $language): string
{
    if ($language === 'de') {
        return '<span class="flag flag-de"><span></span></span>';
    }

    return '<span class="flag flag-en"><span></span></span>';
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

        <a href="index.php#download">
            <?php echo htmlspecialchars(t('nav_download')); ?>
        </a>

        <a href="imprint.php">
            <?php echo htmlspecialchars(t('nav_imprint')); ?>
        </a>

        <?php if (isset($_SESSION['web_user_id'])): ?>

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
            </a>
            </span>

            <a href="web_logout.php?return_to=<?php echo urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'); ?>">
                <?php echo htmlspecialchars(t('nav_logout')); ?>
            </a>

        <?php else: ?>

            <button type="button" onclick="openModal('loginModal')">
                <?php echo htmlspecialchars(t('nav_login')); ?>
            </button>

            <button type="button" onclick="openModal('registerModal')">
                <?php echo htmlspecialchars(t('nav_register')); ?>
            </button>

        <?php endif; ?>

        <div class="language-dropdown">

            <button type="button"
                    class="language-button"
                    onclick="toggleLanguageMenu()">

                <?php echo renderFlag($currentLanguage); ?>

                <?php if ($currentLanguage === 'de'): ?>
                    Deutsch
                <?php else: ?>
                    English
                <?php endif; ?>

            </button>

            <div class="language-menu" id="languageMenu">

                <a class="language-item"
                   href="<?php echo htmlspecialchars(buildLanguageUrl('en')); ?>">

                    <?php echo renderFlag('en'); ?>
                    English
                </a>

                <a class="language-item"
                   href="<?php echo htmlspecialchars(buildLanguageUrl('de')); ?>">

                    <?php echo renderFlag('de'); ?>
                    Deutsch
                </a>

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