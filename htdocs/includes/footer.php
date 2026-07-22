<?php

$footerLanguage =
    $currentLanguage
    ?? ($_SESSION['language'] ?? 'en');

$footerLanguage =
    strtolower(trim((string)$footerLanguage));

if ($footerLanguage !== 'de' && $footerLanguage !== 'en') {
    $footerLanguage = 'en';
}

$footerImprintUrl =
    'imprint.php?' . http_build_query([
        'lang' => $footerLanguage
    ]);

$footerImprintLabel =
    function_exists('t')
        ? t('nav_imprint')
        : 'Impressum';

?>

<style>
    .site-footer {
        width: 100%;
        min-height: 46px;
        padding: 14px 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 18px;
        flex-wrap: wrap;
        background: rgba(0, 0, 0, 0.42);
        border-top: 1px solid rgba(255, 255, 255, 0.12);
        color: #8fa9c4;
        font-size: 13px;
        line-height: 1.4;
        position: relative;
        z-index: 50000;
        backdrop-filter: blur(12px);
    }

    .site-footer a {
        color: #00ffcc;
        text-decoration: none;
    }

    .site-footer a:hover {
        text-decoration: underline;
    }

    .site-footer-separator {
        color: rgba(255, 255, 255, 0.28);
    }

    @media (max-width: 600px) {
        .site-footer {
            padding: 14px 20px;
            gap: 10px;
            text-align: center;
        }
    }
</style>

<footer class="site-footer">
    <span>
        2026 - CopyRight by Toni Henkel
    </span>

    <span class="site-footer-separator">|</span>

    <a href="<?php echo htmlspecialchars($footerImprintUrl, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($footerImprintLabel, ENT_QUOTES, 'UTF-8'); ?>
    </a>
</footer>
