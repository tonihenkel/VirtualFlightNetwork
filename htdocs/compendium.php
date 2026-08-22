<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
if (!empty($_SESSION['web_user_id'])) {
    require_once __DIR__ . '/includes/web_session.php';
    validateVfnWebSession($pdo);
}
$compendiumViewerOpPermission = (int)($_SESSION['web_op_permission'] ?? 0);
if (empty($compendiumPublicEnabled) && $compendiumViewerOpPermission < 1) {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="<?php echo htmlspecialchars($currentLanguage ?? 'en', ENT_QUOTES, 'UTF-8'); ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?php echo htmlspecialchars(t('compendium_access_denied_title'), ENT_QUOTES, 'UTF-8'); ?></title>
        <style>
            *{box-sizing:border-box}body{margin:0;background:#07141f;color:#d8eaff;font-family:Arial,sans-serif}.compendium-denied{width:min(760px,calc(100% - 32px));margin:90px auto;padding:38px;text-align:center;background:#0d1d2a;border:1px solid #8f3942;border-radius:10px}.compendium-denied h1{margin-top:0;font-size:34px}.compendium-denied p{color:#b8c9d8;line-height:1.6}.compendium-denied a{display:inline-block;margin-top:14px;padding:12px 20px;color:#fff;text-decoration:none;background:#176dcc;border:1px solid #3983b7;border-radius:7px}
        </style>
    </head>
    <body>
        <?php require __DIR__ . '/includes/header.php'; ?>
        <main class="compendium-denied">
            <h1><?php echo htmlspecialchars(t('compendium_access_denied_title'), ENT_QUOTES, 'UTF-8'); ?></h1>
            <p><?php echo htmlspecialchars(t('compendium_access_disabled'), ENT_QUOTES, 'UTF-8'); ?></p>
            <a href="index.php">&larr; VFN</a>
        </main>
        <?php require __DIR__ . '/includes/footer.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

require_once __DIR__ . '/includes/compendium_schema.php';
require_once __DIR__ . '/includes/division_content.php';

ensureCompendiumSchema($pdo);
function ch(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

$slug = compendiumNormalizeTerm((string)($_GET['article'] ?? ''));
$query = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$division = strtoupper(trim((string)($_GET['division'] ?? '')));
$article = null;
$showOverview = isset($_GET['all']) || array_key_exists('q', $_GET) || $category !== '' || $division !== '';

if ($slug !== '') {
    $stmt=$pdo->prepare("SELECT a.*,d.name division_name FROM compendium_articles a LEFT JOIN divisions d ON d.code=a.division_code WHERE a.slug=:slug AND a.status='published' LIMIT 1");
    $stmt->execute(['slug'=>$slug]);
    $article=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$article) {
        $alias=$pdo->prepare("SELECT a.*,d.name division_name FROM compendium_aliases x INNER JOIN compendium_articles a ON a.id=x.article_id LEFT JOIN divisions d ON d.code=a.division_code WHERE x.normalized_alias=:alias AND a.status='published' LIMIT 1");
        $alias->execute(['alias'=>$slug]);
        $article=$alias->fetch(PDO::FETCH_ASSOC);
        if ($article) { header('Location: compendium.php?article='.rawurlencode($article['slug']), true, 302); exit; }
    }
}

if ($slug === '' && !$showOverview) {
    $home=$pdo->query("SELECT a.*,d.name division_name FROM compendium_articles a LEFT JOIN divisions d ON d.code=a.division_code WHERE a.is_homepage=1 AND a.status='published' ORDER BY a.updated_at DESC LIMIT 1");
    $article=$home->fetch(PDO::FETCH_ASSOC) ?: null;
}

$params=[];$where=["a.status='published'"];
if ($query!=='') { $where[]="(a.title LIKE :q OR a.summary LIKE :q OR a.airport_code LIKE :q OR EXISTS(SELECT 1 FROM compendium_aliases ca WHERE ca.article_id=a.id AND ca.alias LIKE :q))"; $params['q']='%'.$query.'%'; }
if ($category!=='') { $where[]='a.category=:category'; $params['category']=$category; }
if ($division!=='') { $where[]='a.division_code=:division'; $params['division']=$division; }
$list=$pdo->prepare("SELECT a.id,a.title,a.slug,a.summary,a.category,a.language_code,a.scope_type,a.division_code,a.airport_code,a.updated_at,d.name division_name FROM compendium_articles a LEFT JOIN divisions d ON d.code=a.division_code WHERE ".implode(' AND ',$where)." ORDER BY a.sort_order,a.title LIMIT 200");
$list->execute($params);$articles=$list->fetchAll(PDO::FETCH_ASSOC);
$categories=$pdo->query("SELECT category,COUNT(*) total FROM compendium_articles WHERE status='published' GROUP BY category ORDER BY category")->fetchAll(PDO::FETCH_ASSOC);
$divisions=$pdo->query("SELECT DISTINCT d.code,d.name FROM compendium_articles a INNER JOIN divisions d ON d.code=a.division_code WHERE a.status='published' ORDER BY d.name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="<?php echo ch($currentLanguage ?? 'en'); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo ch(t('compendium_title')); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#07141f;color:#d8eaff;font-family:Arial,sans-serif}.hero{padding:48px 24px;background:linear-gradient(135deg,#092b48,#07141f);border-bottom:1px solid #285475}.hero-inner,.shell{width:min(1380px,calc(100% - 32px));margin:auto}.hero h1{font-size:42px;margin:0 0 10px}.hero p{color:#9fbbd4}.search{display:grid;grid-template-columns:1fr 220px 220px auto;gap:10px;margin-top:24px}.search input,.search select{padding:13px;background:#071521;color:#fff;border:1px solid #34729b;border-radius:7px}.button{padding:12px 18px;border:1px solid #3983b7;border-radius:7px;background:#176dcc;color:#fff;text-decoration:none;cursor:pointer}.shell{display:grid;grid-template-columns:260px 1fr;gap:22px;padding:28px 0 60px}.side,.card,.article{background:#0d1d2a;border:1px solid #285475;border-radius:10px}.side{padding:17px;height:max-content}.side a{display:block;color:#a9d8ff;text-decoration:none;padding:8px;border-radius:5px}.side a:hover{background:#15354d}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:15px}.card{padding:18px;color:#d8eaff;text-decoration:none}.card:hover{border-color:#41a9ed;transform:translateY(-1px)}.meta{color:#72bfff;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.summary{color:#aebfd0;line-height:1.5}.article{padding:30px;line-height:1.65;overflow:hidden}.article h1{font-size:38px;margin-top:0}.article-head{border-bottom:1px solid #29485d;margin-bottom:24px}.article-content img{max-width:100%;height:auto}.article-content table{width:100%;border-collapse:collapse}.article-content th,.article-content td{border:1px solid #365b72;padding:9px}.article-content a{color:#55b8ff}.empty{padding:30px;text-align:center;color:#9ab0c3}@media(max-width:850px){.search{grid-template-columns:1fr}.shell{grid-template-columns:1fr}.hero h1{font-size:32px}}
</style></head><body><?php require __DIR__.'/includes/header.php'; ?><section class="hero"><div class="hero-inner"><h1><?php echo ch(t('compendium_title')); ?></h1><p><?php echo ch(t('compendium_intro')); ?></p><form class="search"><input name="q" value="<?php echo ch($query); ?>" placeholder="<?php echo ch(t('compendium_search_placeholder')); ?>"><select name="category"><option value=""><?php echo ch(t('compendium_all_categories')); ?></option><?php foreach($categories as $item):?><option value="<?php echo ch($item['category']); ?>" <?php echo $category===$item['category']?'selected':''; ?>><?php echo ch(t('compendium_category_'.$item['category'])); ?> (<?php echo (int)$item['total']; ?>)</option><?php endforeach;?></select><select name="division"><option value=""><?php echo ch(t('compendium_all_divisions')); ?></option><?php foreach($divisions as $item):?><option value="<?php echo ch($item['code']); ?>" <?php echo $division===$item['code']?'selected':''; ?>><?php echo ch($item['name']); ?></option><?php endforeach;?></select><button class="button"><?php echo ch(t('search')); ?></button></form></div></section><main class="shell"><aside class="side"><strong><?php echo ch(t('compendium_categories')); ?></strong><a href="compendium.php"><?php echo ch(t('compendium_all_articles')); ?></a><?php foreach($categories as $item):?><a href="?category=<?php echo rawurlencode($item['category']); ?>"><?php echo ch(t('compendium_category_'.$item['category'])); ?></a><?php endforeach;?><?php if((int)($_SESSION['web_op_permission']??0)>=3):?><hr><a href="admin_compendium.php"><?php echo ch(t('compendium_manage')); ?></a><?php endif;?></aside><section><?php if($article):?><article class="article"><header class="article-head"><div class="meta"><?php echo ch(t('compendium_category_'.$article['category'])); ?><?php if($article['division_code']):?> · <?php echo ch($article['division_name']?:$article['division_code']); ?><?php endif;?><?php if($article['airport_code']):?> · <?php echo ch($article['airport_code']); ?><?php endif;?></div><h1><?php echo ch($article['title']); ?></h1><p class="summary"><?php echo ch((string)$article['summary']); ?></p></header><div class="article-content division-content"><?php echo sanitizeDivisionHtml((string)$article['content_html']); ?></div></article><?php elseif($slug!==''):?><div class="article empty"><?php echo ch(t('compendium_not_found')); ?></div><?php else:?><div class="grid"><?php foreach($articles as $item):?><a class="card" href="?article=<?php echo rawurlencode($item['slug']); ?>"><div class="meta"><?php echo ch(t('compendium_category_'.$item['category'])); ?><?php echo $item['division_code']?' · '.ch($item['division_code']):''; ?></div><h2><?php echo ch($item['title']); ?></h2><p class="summary"><?php echo ch((string)$item['summary']); ?></p></a><?php endforeach;?></div><?php if(!$articles):?><div class="article empty"><?php echo ch(t('compendium_no_results')); ?></div><?php endif;?><?php endif;?></section></main><?php require __DIR__.'/includes/footer.php'; ?></body></html>
