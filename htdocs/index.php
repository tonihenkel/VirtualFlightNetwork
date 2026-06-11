<?php

session_start();

require_once 'execute/config.php';
require_once 'includes/language.php';

if (!isset($projectName) || trim($projectName) === '') {
    $projectName = "Flight Radar Sim Project";
}

$mapUrl =
    "map.php";

$pluginDownloadFilePath =
    $pluginDownloadPath ?? '';

$pluginDownloadButtonUrl =
    $pluginDownloadUrl ?? 'execute/download_plugin.php';

$pluginDownloadAvailable =
    isset($pluginDownloadEnabled)
    && $pluginDownloadEnabled === true;

$pluginVersion =
    "v0.1.0";

$apiStatusUrl =
    "/execute/get_pilots.php";

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($projectName); ?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
            font-family: Arial, sans-serif;
            background:
                radial-gradient(circle at top, #12335a 0%, #07111f 45%, #02050a 100%);
            color: white;
        }

        body {
            overflow-x: hidden;
        }

        .page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .hero {
            flex: 1;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 35px;
            align-items: center;
            padding: 70px 70px 45px 70px;
        }

        .hero h1 {
            margin: 0 0 18px 0;
            font-size: 54px;
            line-height: 1.05;
        }

        .hero p {
            max-width: 650px;
            margin: 0 0 30px 0;
            color: #c8d8ea;
            font-size: 18px;
            line-height: 1.6;
        }

        .button-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 14px 22px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            border: 0;
            cursor: pointer;
            font-size: 15px;
            font-family: Arial, sans-serif;
        }

        .btn-primary {
            background: #1d6cff;
            color: white;
        }

        .btn-primary:hover {
            background: #4083ff;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.12);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.22);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.18);
        }

        .btn-disabled {
            background: rgba(255, 255, 255, 0.12);
            color: rgba(255, 255, 255, 0.45);
            cursor: not-allowed;
            pointer-events: none;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .download-warning {
            margin-top: 10px;
            color: #ffcc66;
            font-size: 14px;
            line-height: 1.5;
        }

        .radar-card {
            background: rgba(255, 255, 255, 0.09);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
        }

        .radar-screen {
            position: relative;
            height: 340px;
            border-radius: 14px;
            overflow: hidden;
            background:
                linear-gradient(rgba(24, 130, 255, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(24, 130, 255, 0.08) 1px, transparent 1px),
                radial-gradient(circle at center, rgba(0, 255, 204, 0.18), rgba(0, 0, 0, 0.8));
            background-size: 34px 34px, 34px 34px, cover;
            border: 1px solid rgba(0, 255, 204, 0.25);
        }

        .radar-line {
            position: absolute;
            width: 50%;
            height: 2px;
            left: 50%;
            top: 50%;
            background: linear-gradient(90deg, rgba(0, 255, 204, 0.9), transparent);
            transform-origin: left center;
            animation: sweep 4s linear infinite;
        }

        @keyframes sweep {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .plane-dot {
            position: absolute;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #00ffcc;
            box-shadow: 0 0 12px #00ffcc;
        }

        .dot-1 {
            left: 64%;
            top: 38%;
        }

        .dot-2 {
            left: 33%;
            top: 61%;
        }

        .dot-3 {
            left: 72%;
            top: 70%;
        }

        .status-panel {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .status-box {
            background: rgba(0, 0, 0, 0.35);
            border-radius: 10px;
            padding: 14px;
            text-align: center;
        }

        .status-value {
            font-size: 24px;
            font-weight: bold;
            color: #00ffcc;
        }

        .status-label {
            margin-top: 4px;
            font-size: 11px;
            color: #aac2d8;
            text-transform: uppercase;
        }

        .features {
            padding: 10px 70px 70px 70px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 22px;
            min-height: 150px;
        }

        .feature-icon {
            font-size: 28px;
            margin-bottom: 12px;
        }

        .feature-card h3 {
            margin: 0 0 8px 0;
            font-size: 17px;
        }

        .feature-card p {
            margin: 0;
            color: #c8d8ea;
            font-size: 14px;
            line-height: 1.5;
        }

        .download-section {
            padding: 0 70px 70px 70px;
        }

        .download-box {
            background: rgba(255, 255, 255, 0.09);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 18px;
            padding: 28px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 25px;
            align-items: center;
        }

        .download-box h2 {
            margin: 0 0 8px 0;
        }

        .download-box p {
            margin: 0;
            color: #c8d8ea;
            line-height: 1.5;
        }

        .footer {
            padding: 18px 40px;
            color: #8fa9c4;
            font-size: 13px;
            background: rgba(0, 0, 0, 0.35);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
.status-message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999999;
            padding: 14px 22px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: bold;
            box-shadow: 0 10px 30px rgba(0,0,0,0.45);
            animation:
                fadeIn 0.25s ease,
                fadeOut 0.4s ease 5s forwards;
        }

        .status-message.success {
            background: #1e8f46;
            color: white;
        }

        .status-message.error {
            background: #b62929;
            color: white;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                visibility: hidden;
            }
        }

        @media (max-width: 950px) {
            .hero {
                grid-template-columns: 1fr;
                padding: 45px 25px;
            }

            .hero h1 {
                font-size: 38px;
            }

            .features {
                grid-template-columns: 1fr;
                padding: 10px 25px 45px 25px;
            }

            .download-section {
                padding: 0 25px 45px 25px;
            }

            .download-box {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<?php
$statusType =
    $_GET['type'] ?? '';

$statusMessage =
    $_GET['message'] ?? '';

if ($statusMessage !== '') {
    $statusMessage =
        t($statusMessage);
}
?>

<?php if ($statusMessage !== ''): ?>

<div class="status-message <?php echo htmlspecialchars($statusType); ?>">
    <?php echo htmlspecialchars($statusMessage); ?>
</div>

<?php endif; ?>

<div class="page">

    <?php require_once 'includes/header.php'; ?>

    <main class="hero">

        <section>
            <h1>
                <?php echo htmlspecialchars(t('hero_title')); ?>
            </h1>

            <p>
                <?php echo htmlspecialchars(t('hero_text')); ?>
            </p>

            <div class="button-row">

                <a class="btn btn-primary"
                   href="<?php echo htmlspecialchars($mapUrl); ?>">
                    <?php echo htmlspecialchars(t('hero_open_map')); ?>
                </a>

                <?php if ($pluginDownloadAvailable): ?>

                    <a class="btn btn-secondary"
                       href="#download">
                        <?php echo htmlspecialchars(t('hero_download_plugin')); ?>
                    </a>

                <?php else: ?>

                    <span class="btn btn-disabled">
                        <?php echo htmlspecialchars(t('download_disabled')); ?>
                    </span>

                <?php endif; ?>

            </div>
        </section>

        <section class="radar-card">

            <div class="radar-screen">
                <div class="radar-line"></div>

                <div class="plane-dot dot-1"></div>
                <div class="plane-dot dot-2"></div>
                <div class="plane-dot dot-3"></div>
            </div>

            <div class="status-panel">

                <div class="status-box">
                    <div class="status-value" id="activePilots">-</div>
                    <div class="status-label">
                        <?php echo htmlspecialchars(t('status_active_pilots')); ?>
                    </div>
                </div>

                <div class="status-box">
                    <div class="status-value" id="apiStatus">...</div>
                    <div class="status-label">
                        <?php echo htmlspecialchars(t('status_system')); ?>
                    </div>
                </div>

                <div class="status-box">
                    <div class="status-value" id="lastUpdate">--:--</div>
                    <div class="status-label">
                        <?php echo htmlspecialchars(t('status_update')); ?>
                    </div>
                </div>

            </div>

        </section>

    </main>

    <section class="features">

        <div class="feature-card">
            <div class="feature-icon">🛩️</div>

            <h3>
                <?php echo htmlspecialchars(t('feature_tracking_title')); ?>
            </h3>

            <p>
                <?php echo htmlspecialchars(t('feature_tracking_text')); ?>
            </p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🧭</div>

            <h3>
                <?php echo htmlspecialchars(t('feature_flightplan_title')); ?>
            </h3>

            <p>
                <?php echo htmlspecialchars(t('feature_flightplan_text')); ?>
            </p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">📡</div>

            <h3>
                <?php echo htmlspecialchars(t('feature_radio_title')); ?>
            </h3>

            <p>
                <?php echo htmlspecialchars(t('feature_radio_text')); ?>
            </p>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🗺️</div>

            <h3>
                <?php echo htmlspecialchars(t('feature_routes_title')); ?>
            </h3>

            <p>
                <?php echo htmlspecialchars(t('feature_routes_text')); ?>
            </p>
        </div>

    </section>

    <section class="download-section" id="download">

        <div class="download-box">

            <div>
                <h2>
                    <?php echo htmlspecialchars(t('download_title')); ?>
                </h2>

                <p>
                    <?php echo htmlspecialchars(t('download_text')); ?>
                    <br>
                    Version:
                    <strong>
                        <?php echo htmlspecialchars($pluginVersion); ?>
                    </strong>
                </p>
            </div>

            <?php if ($pluginDownloadAvailable): ?>

                <a class="btn btn-primary"
                   href="<?php echo htmlspecialchars($pluginDownloadButtonUrl); ?>">
                    <?php echo htmlspecialchars(t('download_button')); ?>
                </a>

            <?php else: ?>

                <span class="btn btn-disabled">
                    <?php echo htmlspecialchars(t('download_disabled')); ?>
                </span>

            <?php endif; ?>

            <?php if (!$pluginDownloadAvailable): ?>

                <div class="download-warning">
                    <?php echo htmlspecialchars(t('download_unavailable')); ?>
                </div>

            <?php endif; ?>

        </div>

    </section>

    <footer class="footer">
        <?php echo htmlspecialchars($projectName); ?>
        · Local Development Version ·
        <?php echo date("Y"); ?>
    </footer>

</div>

<?php require_once 'includes/auth_modals.php'; ?>

<script>
    async function loadStatus()
    {
        try
        {
            const response =
                await fetch(
                    '<?php echo htmlspecialchars($apiStatusUrl); ?>?time=' + Date.now()
                );

            const data =
                await response.json();

            if (data.success)
            {
                document.getElementById('activePilots').innerText =
                    data.count;

                document.getElementById('apiStatus').innerText =
                    '<?php echo htmlspecialchars(t('status_online')); ?>';

                document.getElementById('lastUpdate').innerText =
                    formatUtcTime();
            }
            else
            {
                document.getElementById('apiStatus').innerText =
                    '<?php echo htmlspecialchars(t('status_error')); ?>';
            }
        }
        catch(error)
        {
            document.getElementById('apiStatus').innerText =
                '<?php echo htmlspecialchars(t('status_offline')); ?>';

            document.getElementById('activePilots').innerText =
                '0';
        }
    }

    loadStatus();

    setInterval(
        loadStatus,
        5000
    );
</script>

</body>
</html>