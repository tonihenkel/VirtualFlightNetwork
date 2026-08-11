<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/web_features_schema.php';

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function systemJobNextRun(array $job): string
{
    $details = json_decode((string)($job['details_json'] ?? ''), true);
    if (is_array($details) && !empty($details['next_run_at'])) {
        $nextRun = strtotime((string)$details['next_run_at']);
        if ($nextRun !== false) return date('d.m.Y H:i:s', $nextRun);
    }
    $jobKey = (string)($job['job_key'] ?? '');
    if ($jobKey === 'track_cleanup') {
        $lastRun = strtotime((string)($job['last_success_at'] ?? ''));
        if ($lastRun === false) return t('system_status_next_on_request');
        $nextRun = $lastRun + 86400;
        return $nextRun <= time() ? t('system_status_next_due_on_request') : date('d.m.Y H:i:s', $nextRun);
    }
    if (in_array($jobKey, ['metar_refresh', 'airac_refresh'], true)) return t('system_status_next_on_request');
    if ($jobKey === 'atc_frequency_refresh') return t('system_status_next_manual');
    return t('system_status_next_unknown');
}

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo) || (int)($_SESSION['web_op_permission'] ?? 0) < 5) {
    http_response_code(403); exit('Access denied');
}
ensureWebFeatureSchema($pdo);

$checks = [];
$start = microtime(true);
try { $pdo->query('SELECT 1')->fetchColumn(); $checks[] = ['database', true, round((microtime(true) - $start) * 1000) . ' ms']; }
catch (Throwable $error) { $checks[] = ['database', false, $error->getMessage()]; }
$socket = @fsockopen('127.0.0.1', 8090, $errno, $errstr, 1.5);
$checks[] = ['voice_service', (bool)$socket, $socket ? 'Port 8090 reachable' : $errstr];
if ($socket) fclose($socket);

$jobs = $pdo->query('SELECT * FROM system_job_status ORDER BY job_key')->fetchAll(PDO::FETCH_ASSOC);
$files = ['plugin_api' => __DIR__.'/execute/login.php', 'airac_data' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'vfn_airac_current_cycle.json', 'fir_data' => __DIR__.'/data/atc/fir-boundaries.geojson', 'plugin_release' => __DIR__.'/_downloads_/_FlightRadarPlugin_latest.zip'];
foreach ($files as $key => $file) {
    $exists = is_file($file);
    $detail = $exists ? date('d.m.Y H:i:s', filemtime($file)) : 'missing';
    if ($key === 'airac_data' && $exists) {
        $airacPayload = json_decode((string)@file_get_contents($file), true);
        $airacData = is_array($airacPayload) && isset($airacPayload['data']) && is_array($airacPayload['data'])
            ? $airacPayload['data']
            : (is_array($airacPayload) ? $airacPayload : []);
        $cycle = trim((string)($airacData['cycle'] ?? $airacData['airac_cycle'] ?? $airacData['version'] ?? ''));
        $validFrom = trim((string)($airacData['effective_date'] ?? $airacData['valid_from'] ?? $airacData['start_date'] ?? ''));
        $validUntil = trim((string)($airacData['expiration_date'] ?? $airacData['valid_until'] ?? $airacData['end_date'] ?? ''));
        $parts = [];
        if ($cycle !== '') $parts[] = 'AIRAC ' . $cycle;
        if ($validFrom !== '' || $validUntil !== '') $parts[] = trim($validFrom . ' – ' . $validUntil, ' –');
        $parts[] = t('system_status_cache_updated') . ': ' . date('d.m.Y H:i:s', filemtime($file));
        $detail = implode(' · ', $parts);
    }
    $checks[] = [$key, $exists, $detail];
}

$metarFiles = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'vfn_map_metar_*.json') ?: [];
$latestMetar = 0;
foreach ($metarFiles as $metarFile) $latestMetar = max($latestMetar, (int)filemtime($metarFile));
$metarJob = null;
foreach ($jobs as $job) if ((string)$job['job_key'] === 'metar_refresh') { $metarJob = $job; break; }
$metarLastSuccess = $metarJob && !empty($metarJob['last_success_at']) ? strtotime((string)$metarJob['last_success_at']) : 0;
$metarLastError = $metarJob && !empty($metarJob['last_error_at']) ? strtotime((string)$metarJob['last_error_at']) : 0;
$metarOk = $latestMetar > 0 || ($metarLastSuccess > 0 && $metarLastSuccess >= $metarLastError);
$metarTimestamp = max($latestMetar, $metarLastSuccess);
$metarDetail = $metarTimestamp > 0 ? date('d.m.Y H:i:s', $metarTimestamp).($latestMetar > 0 ? ' · Cache vorhanden' : ' · letzter erfolgreicher Abruf') : 'missing';
$checks[] = ['metar_data', $metarOk, $metarDetail];
$sessions = (int)$pdo->query('SELECT COUNT(*) FROM user_sessions WHERE is_active=1 AND last_seen>=DATE_SUB(NOW(),INTERVAL 30 SECOND)')->fetchColumn();
?>
<!doctype html><html lang="<?php echo h($currentLanguage); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo h(t('system_status_title')); ?></title><style>
body{margin:0;background:#07141f;color:#d7e8ff;font-family:Arial}.shell{width:min(1150px,calc(100% - 32px));margin:28px auto}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.card{background:#0d1d2a;border:1px solid #285475;border-radius:8px;padding:17px}.ok{color:#55e9a5}.bad{color:#ff7777}.value{font-size:22px;font-weight:bold}.meta{color:#8da7bd;font-size:12px;margin-top:7px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}td,th{padding:10px;border-bottom:1px solid #203b50;text-align:left;vertical-align:top}.next-run{white-space:nowrap;color:#9fd3ff}@media(max-width:800px){.grid{grid-template-columns:1fr}}
</style></head><body><?php require __DIR__.'/includes/header.php'; ?><main class="shell"><h1><?php echo h(t('system_status_title')); ?></h1><p><?php echo h(t('system_status_text')); ?></p><div class="grid"><div class="card"><div class="value"><?php echo $sessions; ?></div><?php echo h(t('system_status_sessions')); ?></div><?php foreach ($checks as $check): ?><div class="card"><strong class="<?php echo $check[1] ? 'ok' : 'bad'; ?>"><?php echo $check[1] ? '● OK' : '● ERROR'; ?></strong><h3><?php echo h(t('system_'.$check[0])); ?></h3><div class="meta"><?php echo h($check[2]); ?></div></div><?php endforeach; ?></div>
<section class="card" style="margin-top:16px"><h2><?php echo h(t('system_status_jobs')); ?></h2><?php if (!$jobs): ?><p><?php echo h(t('system_status_no_jobs')); ?></p><?php else: ?><div class="table-wrap"><table><thead><tr><th><?php echo h(t('system_status_job')); ?></th><th><?php echo h(t('system_status_last_success')); ?></th><th><?php echo h(t('system_status_last_error')); ?></th><th><?php echo h(t('system_status_next_run')); ?></th><th><?php echo h(t('system_status_details')); ?></th></tr></thead><tbody><?php foreach ($jobs as $job): ?><tr><td><?php echo h($job['job_key']); ?></td><td><?php echo h($job['last_success_at']); ?></td><td><?php echo h($job['last_error_at']); ?></td><td class="next-run"><?php echo h(systemJobNextRun($job)); ?></td><td><?php echo h($job['last_error']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></main><?php require __DIR__.'/includes/footer.php'; ?></body></html>
