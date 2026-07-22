<?php

session_start();

require_once 'execute/config.php';
require_once 'includes/language.php';

if (!isset($projectName) || trim($projectName) === '') {
    $projectName = "Flight Radar Sim Project";
}

$ownerName =
    $companyOwner ?? 'Toni Henkel';

$companyName =
    $companyName ?? $projectName;

$companyAddress =
    $companyAddress ?? 'Bitte Adresse in config.php eintragen';

$companyZipCity =
    $companyZipCity ?? 'Bitte PLZ / Ort in config.php eintragen';

$companyCountry =
    $companyCountry ?? 'Deutschland';

$companyEmail =
    $companyEmail ?? 'Bitte E-Mail in config.php eintragen';

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLanguage); ?>">
<head>
    <meta charset="UTF-8">

    <title>
        <?php echo htmlspecialchars($projectName); ?>
    </title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;

            font-family: Arial, sans-serif;

            background:
                radial-gradient(
                    circle at top,
                    #12335a 0%,
                    #07111f 45%,
                    #02050a 100%
                );

            color: white;
        }

        .content {
            max-width: 900px;

            margin: 0 auto;

            padding: 60px 25px;
        }

        .card {
            background:
                rgba(255,255,255,0.09);

            border:
                1px solid rgba(255,255,255,0.16);

            border-radius: 18px;

            padding: 32px;

            box-shadow:
                0 20px 60px rgba(0,0,0,0.45);
        }

        h1 {
            margin-top: 0;

            font-size: 38px;
        }

        h2 {
            margin-top: 34px;

            color: #00ffcc;
        }

        p {
            color: #c8d8ea;

            line-height: 1.7;
        }

        a {
            color: #00ffcc;
        }

        .status-message {
            position: fixed;
            top: 95px;
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




    </style>

</head>
<body>

<?php require_once 'includes/header.php';




$statusType =
    $_GET['type'] ?? '';

$statusMessage =
    $_GET['message'] ?? '';

if ($statusMessage !== '')
{
    $statusMessage =
        t($statusMessage);
}
?>

<?php if ($statusMessage !== ''): ?>

<div class="status-message <?php echo htmlspecialchars($statusType); ?>">
    <?php echo htmlspecialchars($statusMessage); ?>
</div>

<?php endif; ?>

<main class="content">

    <div class="card">

        <h1>
            <?php echo htmlspecialchars(t('imprint_title')); ?>
        </h1>

        <h2>
            <?php echo htmlspecialchars(t('imprint_information')); ?>
        </h2>

        <p>
            <?php echo htmlspecialchars($companyName); ?><br>

            <?php echo htmlspecialchars($ownerName); ?><br>

            <?php echo htmlspecialchars($companyAddress); ?><br>

            <?php echo htmlspecialchars($companyZipCity); ?><br>

            <?php echo htmlspecialchars($companyCountry); ?>
        </p>

        <h2>
            <?php echo htmlspecialchars(t('imprint_contact')); ?>
        </h2>

        <p>
            <?php echo htmlspecialchars(t('imprint_email')); ?>:

            <a href="mailto:<?php echo htmlspecialchars($companyEmail); ?>">

                <?php echo htmlspecialchars($companyEmail); ?>

            </a>
        </p>

        <h2>
            <?php echo htmlspecialchars(t('imprint_responsible')); ?>
        </h2>

        <p>
            <?php echo htmlspecialchars($ownerName); ?>
        </p>

        <h2>
            <?php echo htmlspecialchars(t('imprint_liability_content')); ?>
        </h2>

        <p>
            <?php echo htmlspecialchars(t('imprint_liability_content_text')); ?>
        </p>

        <h2>
            <?php echo htmlspecialchars(t('imprint_liability_links')); ?>
        </h2>

        <p>
            <?php echo htmlspecialchars(t('imprint_liability_links_text')); ?>
        </p>

    </div>

</main>

<?php require_once 'includes/footer.php'; ?>

<?php require_once 'includes/auth_modals.php'; ?>

</body>
</html>
