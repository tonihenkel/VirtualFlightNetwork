<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/web_features_schema.php';
require_once __DIR__ . '/includes/flightplan_import.php';
require_once __DIR__ . '/includes/airport_code.php';

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fpAirport(PDO $pdo, string $value): string { return vfnNormalizeFlightplanAirport($pdo, $value); }

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) { header('Location: index.php?type=error&message=login_required'); exit; }
ensureWebFeatureSchema($pdo);
$userId = (int)$_SESSION['web_user_id'];
$csrf = csrfToken('flightplans');
$error = '';
$imported = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfIsValid($_POST['csrf'] ?? null, 'flightplans')) {
        $error = t('csrf_invalid');
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'save');
            $id = max(0, (int)($_POST['id'] ?? 0));
            if ($action === 'delete') {
                $pdo->prepare("DELETE FROM web_flightplans WHERE id=:id AND user_id=:uid")->execute(['id'=>$id,'uid'=>$userId]);
                header('Location: flightplans.php?deleted=1'); exit;
            }
            if ($action === 'import') {
                $imported = vfnParseFlightplanUpload($_FILES['flightplan_file'] ?? []);
                if ($imported['callsign'] === '') $imported['callsign'] = strtoupper((string)($_SESSION['callsign'] ?? ''));
            } else {
                $values = [
                    'user_id'=>$userId,
                    'callsign'=>mb_substr(strtoupper(preg_replace('/[^A-Z0-9_-]/', '', (string)($_POST['callsign']??''))),0,20),
                    'flight_rules'=>in_array($_POST['flight_rules']??'', ['I','V','Y','Z'], true)?$_POST['flight_rules']:'I',
                    'flight_type'=>in_array($_POST['flight_type']??'', ['S','N','G','M','X'], true)?$_POST['flight_type']:'G',
                    'communication_mode'=>in_array($_POST['communication_mode']??'', ['VOICE','RECEIVE_ONLY','TEXT_ONLY'], true)?$_POST['communication_mode']:'VOICE',
                    'departure_time'=>mb_substr(trim((string)($_POST['departure_time']??'')),0,20),
                    'departure_airport'=>fpAirport($pdo, (string)($_POST['departure_airport']??'')),
                    'arrival_airport'=>fpAirport($pdo, (string)($_POST['arrival_airport']??'')),
                    'alternate1_airport'=>fpAirport($pdo, (string)($_POST['alternate1_airport']??'')),
                    'alternate2_airport'=>fpAirport($pdo, (string)($_POST['alternate2_airport']??'')),
                    'route_text'=>mb_substr(strtoupper(trim((string)($_POST['route_text']??''))),0,5000),
                    'cruising_level'=>mb_substr(strtoupper(trim((string)($_POST['cruising_level']??''))),0,20),
                    'cruising_speed'=>mb_substr(strtoupper(trim((string)($_POST['cruising_speed']??''))),0,20),
                    'remarks'=>mb_substr(trim((string)($_POST['remarks']??'')),0,2000),
                    'status'=>in_array($_POST['status']??'', ['draft','filed','archived'], true)?$_POST['status']:'draft'
                ];
                if ($values['callsign']==='') throw new RuntimeException('callsign_required');
                if ($id > 0) {
                    $sql="UPDATE web_flightplans SET callsign=:callsign,flight_rules=:flight_rules,flight_type=:flight_type,communication_mode=:communication_mode,departure_time=:departure_time,departure_airport=:departure_airport,arrival_airport=:arrival_airport,alternate1_airport=:alternate1_airport,alternate2_airport=:alternate2_airport,route_text=:route_text,cruising_level=:cruising_level,cruising_speed=:cruising_speed,remarks=:remarks,status=:status WHERE id=:id AND user_id=:user_id";
                    $pdo->prepare($sql)->execute($values+['id'=>$id]);
                } else {
                    $sql="INSERT INTO web_flightplans(user_id,callsign,flight_rules,flight_type,communication_mode,departure_time,departure_airport,arrival_airport,alternate1_airport,alternate2_airport,route_text,cruising_level,cruising_speed,remarks,status) VALUES(:user_id,:callsign,:flight_rules,:flight_type,:communication_mode,:departure_time,:departure_airport,:arrival_airport,:alternate1_airport,:alternate2_airport,:route_text,:cruising_level,:cruising_speed,:remarks,:status)";
                    $pdo->prepare($sql)->execute($values); $id=(int)$pdo->lastInsertId();
                }
                header('Location: flightplans.php?saved=1'); exit;
            }
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error=t('flightplan_save_failed').' ('.t($e->getMessage()).')';
        }
    }
}

$editId=max(0,(int)($_GET['edit']??0)); $edit=null;
if($editId){$s=$pdo->prepare("SELECT * FROM web_flightplans WHERE id=:id AND user_id=:uid");$s->execute(['id'=>$editId,'uid'=>$userId]);$edit=$s->fetch(PDO::FETCH_ASSOC)?:null;}
$showEditor = isset($_GET['new']) || $edit || $imported;
$page=max(1,(int)($_GET['page']??1)); $perPage=20;
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
$where="user_id=:uid"; $queryParams=['uid'=>$userId];
if($search!==''){
    $where.=" AND (callsign LIKE :q1 OR departure_airport LIKE :q2 OR arrival_airport LIKE :q3 OR route_text LIKE :q4 OR CAST(id AS CHAR)=:qid)";
    $like='%'.$search.'%';
    $queryParams+=['q1'=>$like,'q2'=>$like,'q3'=>$like,'q4'=>$like,'qid'=>$search];
}
$count=$pdo->prepare("SELECT COUNT(*) FROM web_flightplans WHERE $where");$count->execute($queryParams);$total=(int)$count->fetchColumn();
$list=$pdo->prepare("SELECT * FROM web_flightplans WHERE $where ORDER BY updated_at DESC,id DESC LIMIT :lim OFFSET :off");
foreach($queryParams as $key=>$value){$list->bindValue(':'.$key,$value,$key==='uid'?PDO::PARAM_INT:PDO::PARAM_STR);}
$list->bindValue(':lim',$perPage,PDO::PARAM_INT);$list->bindValue(':off',($page-1)*$perPage,PDO::PARAM_INT);$list->execute();$plans=$list->fetchAll(PDO::FETCH_ASSOC);$pages=max(1,(int)ceil($total/$perPage));
$f=$imported ?: ($edit ?: vfnFlightplanImportDefaults());
?>
<!doctype html><html lang="<?php echo h($_SESSION['language']??'en'); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo h(t('nav_flightplans')); ?></title>
<style>body{margin:0;background:#07141f;color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1250px,calc(100% - 36px));margin:28px auto}.card{background:#0d1d2a;border:1px solid #285475;border-radius:8px;padding:20px;margin:18px 0}.intro,.actions,.row-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.intro{justify-content:space-between}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}label{display:grid;gap:5px;color:#9ec8e8}.wide{grid-column:1/-1}input,select,textarea{box-sizing:border-box;width:100%;padding:10px;background:#071521;color:#fff;border:1px solid #285475;border-radius:4px}button,.button{display:inline-block;padding:10px 14px;background:#176dcc;color:#fff;border:0;border-radius:4px;text-decoration:none;cursor:pointer}.button.secondary{background:#17344b}.danger{background:#9c2d35}.selected{color:#3ef1b4;font-weight:bold}.notice{padding:12px;border-left:4px solid #18a8ff;background:#092337}.error{border-color:#e75d66;color:#ffc3c6}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #203b50;text-align:left}.pager{display:flex;gap:5px;margin-top:14px}.pager a{padding:7px 10px;border:1px solid #285475;color:#9ed4ff;text-decoration:none}@media(max-width:800px){.grid{grid-template-columns:1fr 1fr}.scroll{overflow:auto}}@media(max-width:520px){.grid{grid-template-columns:1fr}}</style></head>
<body><?php require __DIR__.'/includes/header.php'; ?><main class="shell"><div class="intro"><div><h1><?php echo h(t('nav_flightplans')); ?></h1><p><?php echo h(t('flightplan_list_intro')); ?></p></div><a class="button" href="?new=1"><?php echo h(t('flightplan_new')); ?></a></div>
<?php if(isset($_GET['saved'])):?><p class="notice"><?php echo h(t('flightplan_saved')); ?></p><?php endif; ?><?php if(isset($_GET['deleted'])):?><p class="notice"><?php echo h(t('flightplan_deleted')); ?></p><?php endif; ?><?php if($error):?><p class="notice error"><?php echo h($error); ?></p><?php endif;?>
<section class="card"><h2><?php echo h(t('flightplan_saved_plans')); ?></h2><p class="notice"><?php echo h(t('flightplan_plugin_hint')); ?></p><form method="get" class="actions"><input name="q" value="<?php echo h($search);?>" placeholder="<?php echo h(t('flightplan_search_placeholder'));?>" style="max-width:520px"><button><?php echo h(t('flightplan_search_button'));?></button><?php if($search!==''):?><a class="button secondary" href="flightplans.php"><?php echo h(t('reset'));?></a><?php endif;?></form><div class="scroll"><table><thead><tr><th><?php echo h(t('admin_time')); ?></th><th><?php echo h(t('flightplan_callsign')); ?></th><th><?php echo h(t('flightplan_route')); ?></th><th><?php echo h(t('flightplan_status')); ?></th><th></th></tr></thead><tbody>
<?php if(!$plans):?><tr><td colspan="5"><?php echo h(t('flightplan_none')); ?></td></tr><?php endif;?>
<?php foreach($plans as $p):?><tr><td><?php echo h($p['updated_at']);?></td><td><?php echo h($p['callsign']);?></td><td><a href="airport.php?icao=<?php echo rawurlencode($p['departure_airport']); ?>"><?php echo h($p['departure_airport']); ?></a> → <a href="airport.php?icao=<?php echo rawurlencode($p['arrival_airport']); ?>"><?php echo h($p['arrival_airport']); ?></a></td><td><?php echo h(t('flightplan_status_'.$p['status']));?></td><td><div class="row-actions"><a class="button secondary" href="?edit=<?php echo (int)$p['id'];?>"><?php echo h(t('flightplan_edit'));?></a><form method="post" onsubmit="return confirm('<?php echo h(t('flightplan_delete_confirm'));?>')"><input type="hidden" name="csrf" value="<?php echo h($csrf);?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$p['id'];?>"><button class="danger"><?php echo h(t('delete'));?></button></form></div></td></tr><?php endforeach;?></tbody></table></div><nav class="pager"><?php for($i=1;$i<=$pages;$i++):?><a href="?page=<?php echo $i;?><?php echo $search!==''?'&amp;q='.rawurlencode($search):'';?>"><?php echo $i;?></a><?php endfor;?></nav></section>
<section class="card"><h2><?php echo h(t('flightplan_import_title')); ?></h2><p><?php echo h(t('flightplan_import_help')); ?></p><form method="post" enctype="multipart/form-data" class="actions"><input type="hidden" name="csrf" value="<?php echo h($csrf);?>"><input type="hidden" name="action" value="import"><input type="file" name="flightplan_file" accept=".xml,.fms,.fpl,.pln,.txt" required><button><?php echo h(t('flightplan_import'));?></button></form></section>
<?php if($showEditor):?><section class="card"><h2><?php echo h($edit?t('flightplan_edit'):t('flightplan_new')); ?></h2><?php if($imported):?><p class="notice"><?php echo h(t('flightplan_import_review'));?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>"><input type="hidden" name="action" value="save"><div class="grid">
<label><?php echo h(t('flightplan_callsign')); ?><input name="callsign" value="<?php echo h($f['callsign']); ?>" required></label><label><?php echo h(t('flightplan_rules')); ?><select name="flight_rules"><?php foreach(['I','V','Y','Z'] as $v):?><option <?php echo $f['flight_rules']===$v?'selected':''; ?>><?php echo $v;?></option><?php endforeach;?></select></label><label><?php echo h(t('flightplan_type')); ?><select name="flight_type"><?php foreach(['S','N','G','M','X'] as $v):?><option <?php echo $f['flight_type']===$v?'selected':''; ?>><?php echo $v;?></option><?php endforeach;?></select></label><label><?php echo h(t('flightplan_communication')); ?><select name="communication_mode"><?php foreach(['VOICE','RECEIVE_ONLY','TEXT_ONLY'] as $v):?><option value="<?php echo $v;?>" <?php echo ($f['communication_mode']??'VOICE')===$v?'selected':''; ?>><?php echo h(t('flightplan_communication_'.strtolower($v)));?></option><?php endforeach;?></select></label><label><?php echo h(t('flightplan_status')); ?><select name="status"><?php foreach(['draft','filed','archived'] as $v):?><option <?php echo $f['status']===$v?'selected':''; ?> value="<?php echo $v;?>"><?php echo h(t('flightplan_status_'.$v));?></option><?php endforeach;?></select></label>
<label><?php echo h(t('flightplan_departure')); ?><input name="departure_airport" value="<?php echo h($f['departure_airport']); ?>"></label><label><?php echo h(t('flightplan_arrival')); ?><input name="arrival_airport" value="<?php echo h($f['arrival_airport']); ?>"></label><label>ALT 1<input name="alternate1_airport" value="<?php echo h($f['alternate1_airport']); ?>"></label><label>ALT 2<input name="alternate2_airport" value="<?php echo h($f['alternate2_airport']); ?>"></label><label><?php echo h(t('flightplan_departure_time')); ?><input name="departure_time" value="<?php echo h($f['departure_time']); ?>"></label><label><?php echo h(t('flightplan_level')); ?><input name="cruising_level" value="<?php echo h($f['cruising_level']); ?>"></label><label><?php echo h(t('flightplan_speed')); ?><input name="cruising_speed" value="<?php echo h($f['cruising_speed']); ?>"></label><label class="wide"><?php echo h(t('flightplan_route')); ?><textarea name="route_text" rows="4"><?php echo h($f['route_text']); ?></textarea></label><label class="wide"><?php echo h(t('flightplan_remarks')); ?><textarea name="remarks" rows="3"><?php echo h($f['remarks']); ?></textarea></label></div><p><button><?php echo h(t('flightplan_save')); ?></button> <a class="button secondary" href="flightplans.php"><?php echo h(t('cancel')); ?></a></p></form></section><?php endif;?>
</main><?php require __DIR__.'/includes/footer.php';?></body></html>
