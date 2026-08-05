<?php
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$divisions = $pdo->query(
    "SELECT d.code, d.name, d.short_name, d.description, d.language_code,
            COUNT(u.id) AS member_total
     FROM divisions d
     LEFT JOIN users u ON u.division_code = d.code AND u.is_active = 1
     WHERE d.is_active = 1
     GROUP BY d.id, d.code, d.name, d.short_name, d.description, d.language_code
     ORDER BY d.name"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($_SESSION['language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars(t('divisions_title')); ?> - <?php echo htmlspecialchars($projectName); ?></title>
    <style>
        body{margin:0;background:#071822;color:#d7e8ff;font-family:Arial,sans-serif}
        .division-list-shell{width:min(1250px,calc(100% - 40px));margin:38px auto 70px}
        .division-list-shell h1{font-size:38px;color:#fff;margin-bottom:8px}
        .division-list-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:18px;margin-top:28px}
        .division-list-card{background:#0d2130;border:1px solid #24506b;border-radius:14px;padding:22px;display:flex;flex-direction:column;gap:12px}
        .division-list-code{display:flex;align-items:center;gap:10px;color:#38e3bd;font-weight:bold}
        .division-list-code img{width:30px;max-height:22px;object-fit:cover}
        .division-list-card h2{margin:0;color:#fff}.division-list-card p{line-height:1.5;flex:1}
        .division-list-button{display:inline-block;padding:10px 15px;border-radius:8px;background:#1478d4;color:#fff;text-decoration:none;text-align:center}
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="division-list-shell">
    <h1><?php echo htmlspecialchars(t('divisions_title')); ?></h1>
    <p><?php echo htmlspecialchars(t('divisions_intro')); ?></p>
    <div class="division-list-grid">
        <?php foreach ($divisions as $division): ?>
            <article class="division-list-card">
                <div class="division-list-code">
                    <img src="images/flags/<?php echo htmlspecialchars(strtolower((string)$division['code'])); ?>.png" alt="">
                    <?php echo htmlspecialchars((string)$division['code']); ?>
                </div>
                <h2><?php echo htmlspecialchars((string)$division['name']); ?></h2>
                <p><?php echo nl2br(htmlspecialchars(mb_substr((string)$division['description'], 0, 280))); ?></p>
                <strong><?php echo (int)$division['member_total']; ?> <?php echo htmlspecialchars(t('divisions_members')); ?></strong>
                <a class="division-list-button" href="division.php?code=<?php echo urlencode((string)$division['code']); ?>"><?php echo htmlspecialchars(t('divisions_open')); ?></a>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; require_once __DIR__ . '/includes/auth_modals.php'; ?>
</body>
</html>
