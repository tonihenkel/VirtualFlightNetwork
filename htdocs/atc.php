<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/web_session.php';
require_once __DIR__ . '/includes/ratings.php';
require_once __DIR__ . '/includes/atc_permissions.php';

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$user = null;
if (!empty($_SESSION['web_user_id']) && validateVfnWebSession($pdo)) {
    $stmt = $pdo->prepare(
        "SELECT id, username, real_name, rating_atc, rating_special, op_permission
         FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => (int)$_SESSION['web_user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$rating = (int)($user['rating_atc'] ?? 0);
$specialRating = (int)($user['rating_special'] ?? 0);
$ratingInfo = getAtcRating($rating);
$specialRatingInfo = getSpecialRating($specialRating);
$positions = getAtcPositionPermissions($rating, $specialRating);
$canControl = $user !== null && canUseAtcClient($rating, $specialRating);
$canOpen = $user !== null;
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($currentLanguage); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars(t('atc_client_title')); ?> – VFN</title>
<script>
(function(){
    const storageKey='vfn-atc-client-active';let instance=sessionStorage.getItem('vfn-atc-instance-id');
    if(!instance){instance=(window.crypto?.randomUUID?.()||String(Date.now())+'-'+Math.random());sessionStorage.setItem('vfn-atc-instance-id',instance);}
    window.__vfnAtcInstanceId=instance;let active=null;try{active=JSON.parse(localStorage.getItem(storageKey)||'null');}catch(error){}
    if(active&&active.instance!==instance&&Date.now()-Number(active.timestamp||0)<15000){window.__vfnAtcDuplicate=true;try{localStorage.setItem('vfn-atc-focus-request',String(Date.now()));if(typeof BroadcastChannel!=='undefined'){const channel=new BroadcastChannel('vfn-atc-client');channel.postMessage({type:'focus'});channel.close();}}catch(error){}setTimeout(()=>{if(window.opener){window.close();}else{window.location.replace('index.php');}},100);return;}
    window.__vfnAtcDuplicate=false;localStorage.setItem(storageKey,JSON.stringify({instance:instance,timestamp:Date.now()}));
})();
</script>
<style>
:root{--radar:#8dff9b;--bright:#c6ffcb;--dim:#3b7e48;--panel:#07150c;--line:#235f30;--danger:#ff6868}
*{box-sizing:border-box}html,body{width:100%;height:100%;margin:0;overflow:hidden;background:#010603;color:var(--radar);font-family:Consolas,"Courier New",monospace}
body:before{content:"";position:fixed;inset:0;pointer-events:none;background:repeating-linear-gradient(0deg,rgba(140,255,155,.025) 0,rgba(140,255,155,.025) 1px,transparent 1px,transparent 4px);z-index:20}
.screen{height:100%;display:grid;grid-template-rows:58px 1fr 34px;border:1px solid var(--line)}
.top,.status{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:0 18px;background:#041008;border-bottom:1px solid var(--line);letter-spacing:.08em}.top-actions{display:flex;gap:8px}.status{border:0;border-top:1px solid var(--line);font-size:12px;color:#70bd7a}
.brand{font-weight:bold;color:var(--bright)}.lamp{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:7px;background:var(--radar);box-shadow:0 0 10px var(--radar)}
.workspace{position:relative;display:grid;place-items:center;overflow:auto;padding:26px;background:radial-gradient(circle at center,rgba(31,95,43,.23),transparent 48%),linear-gradient(rgba(30,91,41,.12) 1px,transparent 1px),linear-gradient(90deg,rgba(30,91,41,.12) 1px,transparent 1px),#010603;background-size:auto,50px 50px,50px 50px,auto}
.radar-ring{position:absolute;width:min(76vh,76vw);aspect-ratio:1;border:1px solid rgba(91,214,108,.15);border-radius:50%;pointer-events:none}.radar-ring:before,.radar-ring:after{content:"";position:absolute;left:50%;top:0;width:1px;height:100%;background:rgba(91,214,108,.12)}.radar-ring:after{transform:rotate(90deg)}
.panel{position:relative;z-index:2;width:min(900px,100%);padding:0;background:rgba(4,16,8,.94);border:1px solid var(--line);box-shadow:0 0 35px rgba(67,226,91,.09)}.window-content{padding:25px}.window-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:40px;padding:0 10px 0 14px;border-bottom:1px solid var(--line);background:#06120a;color:var(--bright);cursor:move;user-select:none;touch-action:none}.window-title{font-weight:bold;letter-spacing:.05em}.window-actions{display:flex;gap:6px}.window-action{min-width:30px;height:28px;border:1px solid var(--line);background:#07150c;color:var(--radar);font:inherit;cursor:pointer}.window-action:hover{border-color:var(--radar);background:#12351a}.window-action[data-action="dock"]{display:none}.panel.is-detached{width:100%;height:100%;max-width:none;border:0}.panel.is-detached .window-bar{cursor:default}.panel.is-detached .window-action[data-action="detach"]{display:none}.panel.is-detached .window-action[data-action="dock"]{display:inline-block}.mode-choice{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:18px 0}.mode-button{padding:18px;border:1px solid var(--line);background:#07150c;color:var(--radar);font:inherit;text-align:left;cursor:pointer}.mode-button strong{display:block;color:var(--bright);font-size:20px;margin-bottom:5px}.mode-button:hover,.mode-button.selected{background:#12351a;border-color:var(--radar)}.mode-button:disabled{opacity:.34;cursor:not-allowed}.selection-area[hidden]{display:none}
h1{margin:0 0 8px;color:var(--bright);font-size:24px}h2{font-size:15px;margin:24px 0 10px;color:var(--bright);text-transform:uppercase;letter-spacing:.09em}.muted{color:#6ea778}.identity{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.badge{padding:8px 11px;border:1px solid var(--line);background:#06120a}.positions{display:grid;grid-template-columns:repeat(7,minmax(92px,1fr));gap:9px}.position{min-height:104px;padding:12px;border:1px solid var(--line);background:#07150c;color:var(--radar);text-align:left}.position strong{display:block;font-size:18px;margin-bottom:7px}.position em{display:block;margin-top:7px;color:#ffd36a;font-size:10px;font-style:normal}.position.allowed{cursor:pointer}.position.allowed:hover,.position.selected{background:#12351a;border-color:var(--radar);box-shadow:inset 0 0 18px rgba(111,255,132,.12)}.position.locked{opacity:.34;cursor:not-allowed}.station-search{position:relative}.station-row{display:grid;grid-template-columns:1fr auto;gap:10px}.station-row input,.action{position:relative;z-index:3;min-height:44px;padding:0 13px;border:1px solid var(--line);background:#020a05;color:var(--bright);font:inherit;text-transform:uppercase;pointer-events:auto}.station-row input{width:100%;outline:none;caret-color:var(--bright);user-select:text}.station-row input:focus{border-color:var(--radar);box-shadow:0 0 0 2px rgba(141,255,155,.12)}.action{cursor:pointer;background:#12351a}.action:disabled{opacity:.35;cursor:not-allowed}.station-results{position:absolute;z-index:30;left:0;right:0;top:49px;max-height:245px;overflow:auto;border:1px solid var(--line);background:#020a05;box-shadow:0 18px 30px rgba(0,0,0,.65)}.station-result{display:block;width:100%;padding:10px 12px;border:0;border-bottom:1px solid #143d1e;background:transparent;color:var(--radar);font:inherit;text-align:left;cursor:pointer}.station-result:hover{background:#12351a}.station-result strong{color:var(--bright)}.station-result small{display:block;color:#70a97a;margin-top:3px;text-transform:none}.station-details{margin-top:10px;padding:10px 12px;border:1px solid var(--line);background:#06120a;color:#8edc98}.error{color:var(--danger);border-color:#773636}.fullscreen{border:1px solid var(--line);background:#07150c;color:var(--radar);padding:8px 11px;cursor:pointer}.denied{text-align:center;padding:45px 20px}.denied .symbol{font-size:55px;color:var(--danger)}
.position .supervision-note[hidden]{display:none}
@media(max-width:800px){.positions{grid-template-columns:1fr 1fr}.screen{grid-template-rows:auto 1fr auto}.top,.status{padding:12px;flex-wrap:wrap}}
</style>
</head>
<body>
<main class="screen">
    <header class="top"><div class="brand"><span class="lamp"></span>VFN ATC RADAR CLIENT</div><div><?php echo gmdate('H:i'); ?> UTC</div><div class="top-actions"><button class="fullscreen" id="fullscreenButton"><?php echo htmlspecialchars(t('atc_fullscreen')); ?></button><button class="fullscreen" id="closeAtcButton"><?php echo htmlspecialchars(t('atc_close')); ?></button></div></header>
    <section class="workspace"><div class="radar-ring"></div>
        <div class="panel detachable-window <?php echo $canOpen ? '' : 'error'; ?>" id="atcSetupWindow" data-window-key="atc-setup">
        <div class="window-bar"><span class="window-title"><?php echo htmlspecialchars(t('atc_setup_window')); ?></span><span class="window-actions"><button type="button" class="window-action" data-action="detach" title="<?php echo htmlspecialchars(t('atc_window_detach')); ?>">↗</button><button type="button" class="window-action" data-action="dock" title="<?php echo htmlspecialchars(t('atc_window_dock')); ?>">↙</button></span></div>
        <div class="window-content">
        <?php if (!$user): ?>
            <div class="denied"><div class="symbol">×</div><h1><?php echo htmlspecialchars(t('atc_login_required')); ?></h1><p><?php echo htmlspecialchars(t('atc_login_required_text')); ?></p></div>
        <?php else: ?>
            <h1><?php echo htmlspecialchars(t('atc_mode_selection')); ?></h1>
            <p class="muted"><?php echo htmlspecialchars(t('atc_mode_selection_text')); ?></p>
            <div class="identity"><span class="badge"><?php echo htmlspecialchars($user['real_name'] ?: $user['username']); ?></span><span class="badge"><?php echo htmlspecialchars($ratingInfo['code'] . ' – ' . $ratingInfo['name']); ?></span><?php if ($specialRatingInfo): ?><span class="badge"><?php echo htmlspecialchars($specialRatingInfo['code'] . ' – ' . $specialRatingInfo['name']); ?></span><?php endif; ?></div>
            <div class="mode-choice">
                <button class="mode-button" id="controllerMode" <?php echo $canControl ? '' : 'disabled'; ?>><strong><?php echo htmlspecialchars(t('atc_mode_controller')); ?></strong><span><?php echo htmlspecialchars($canControl ? t('atc_mode_controller_text') : t('atc_mode_controller_denied')); ?></span></button>
                <button class="mode-button" id="spectatorMode"><strong><?php echo htmlspecialchars(t('atc_mode_spectator')); ?></strong><span><?php echo htmlspecialchars(t('atc_mode_spectator_text')); ?></span></button>
            </div>
            <div class="selection-area" id="selectionArea" hidden>
            <h1><?php echo htmlspecialchars(t('atc_position_selection')); ?></h1>
            <h2><?php echo htmlspecialchars(t('atc_station_identifier')); ?></h2>
            <div class="station-search"><div class="station-row"><input id="station" type="search" maxlength="80" placeholder="EDDM / München / EDMM" autocomplete="off" spellcheck="false" autofocus><button class="action" id="continueButton" disabled><?php echo htmlspecialchars(t('atc_continue')); ?></button></div><div class="station-results" id="stationResults" hidden></div></div>
            <div class="station-details" id="stationDetails" hidden></div>
            <h2><?php echo htmlspecialchars(t('atc_allowed_positions')); ?></h2>
            <div class="positions">
            <?php foreach ($positions as $position): ?>
                <button class="position <?php echo $position['allowed'] ? 'allowed' : 'locked'; ?>" data-code="<?php echo htmlspecialchars($position['code']); ?>" data-rank-allowed="<?php echo $position['allowed'] ? '1' : '0'; ?>" <?php echo $position['allowed'] ? '' : 'disabled'; ?>>
                    <strong><?php echo htmlspecialchars($position['code']); ?></strong>
                    <span><?php echo htmlspecialchars(t($position['name_key'])); ?></span>
                    <?php if ($position['supervision_required']): ?><em class="supervision-note"><?php echo htmlspecialchars(t('atc_supervision_required')); ?></em><?php endif; ?>
                </button>
            <?php endforeach; ?>
            </div>
            <p class="muted" id="selectionHint"><?php echo htmlspecialchars(t('atc_choose_station_position')); ?></p>
            </div>
        <?php endif; ?>
        </div></div>
    </section>
    <footer class="status"><span>SYS: ONLINE</span><span>AUTH: <?php echo $canOpen ? 'VALID' : 'DENIED'; ?></span><span>VFN ATC CORE / PHASE 1</span></footer>
</main>
<script>
if(!window.__vfnAtcDuplicate){
document.getElementById('fullscreenButton').addEventListener('click',function(){document.documentElement.requestFullscreen?.().catch(()=>{});});
document.getElementById('closeAtcButton').addEventListener('click',async function(){this.disabled=true;try{await fetch('execute/atc_session_stop.php',{method:'POST',keepalive:true});}catch(error){}try{window.close();}catch(error){}setTimeout(()=>{if(!window.closed)window.location.replace('index.php');},120);});
const atcInstanceId=window.__vfnAtcInstanceId;const detachedAtcWindows=new Set();let atcChannel=null;
function writeAtcHeartbeat(){localStorage.setItem('vfn-atc-client-active',JSON.stringify({instance:atcInstanceId,timestamp:Date.now()}));}
function focusActiveAtcWindow(){for(const popup of detachedAtcWindows){if(popup&&!popup.closed){popup.focus();return;}}window.focus();}
writeAtcHeartbeat();const atcHeartbeatTimer=setInterval(writeAtcHeartbeat,1000);
if(typeof BroadcastChannel!=='undefined'){atcChannel=new BroadcastChannel('vfn-atc-client');atcChannel.addEventListener('message',event=>{if(event.data?.type==='focus')focusActiveAtcWindow();});}
window.addEventListener('storage',event=>{if(event.key==='vfn-atc-focus-request')focusActiveAtcWindow();});
window.addEventListener('beforeunload',()=>{clearInterval(atcHeartbeatTimer);atcChannel?.close();try{const active=JSON.parse(localStorage.getItem('vfn-atc-client-active')||'null');if(active?.instance===atcInstanceId)localStorage.removeItem('vfn-atc-client-active');}catch(error){}});
function initializeAtcWindow(panel){
    const workspace=panel.closest('.workspace');const bar=panel.querySelector('.window-bar');const detachButton=panel.querySelector('[data-action="detach"]');const dockButton=panel.querySelector('[data-action="dock"]');const key=panel.dataset.windowKey||panel.id;let popup=null,drag=null,closeWatch=null;
    function savePosition(){if(panel.classList.contains('is-detached'))return;localStorage.setItem('vfn-atc-window-'+key,JSON.stringify({left:panel.style.left,top:panel.style.top}));}
    function restorePosition(){try{const saved=JSON.parse(localStorage.getItem('vfn-atc-window-'+key)||'null');if(saved?.left&&saved?.top){panel.style.position='absolute';panel.style.left=saved.left;panel.style.top=saved.top;return true;}}catch(error){}return false;}
    function dock(){if(!popup)return;detachedAtcWindows.delete(popup);workspace.appendChild(panel);panel.classList.remove('is-detached');if(!restorePosition()){panel.style.position='relative';panel.style.left='';panel.style.top='';}try{popup.close();}catch(error){}popup=null;if(closeWatch){clearInterval(closeWatch);closeWatch=null;}}
    function detach(){if(popup&&!popup.closed){popup.focus();return;}popup=window.open('','vfn_atc_'+key.replace(/[^a-z0-9]/gi,'_'),'popup=yes,width=1100,height=760,resizable=yes,scrollbars=yes');if(!popup)return;detachedAtcWindows.add(popup);popup.document.open();popup.document.write('<!doctype html><html lang="<?php echo addslashes($currentLanguage); ?>"><head><meta charset="utf-8"><title>'+panel.querySelector('.window-title').textContent.replace(/[<>&]/g,'')+' – VFN</title>'+Array.from(document.querySelectorAll('style')).map(style=>'<style>'+style.textContent+'</style>').join('')+'</head><body></body></html>');popup.document.close();popup.document.body.appendChild(panel);panel.classList.add('is-detached');panel.style.position='relative';panel.style.left='';panel.style.top='';popup.document.documentElement.style.overflow='auto';popup.document.body.style.overflow='auto';closeWatch=setInterval(()=>{if(popup&&popup.closed)dock();},400);}
    detachButton.addEventListener('click',event=>{event.stopPropagation();detach();});dockButton.addEventListener('click',event=>{event.stopPropagation();dock();});
    bar.addEventListener('pointerdown',event=>{if(panel.classList.contains('is-detached')||event.target.closest('button'))return;const panelRect=panel.getBoundingClientRect();const workspaceRect=workspace.getBoundingClientRect();panel.style.position='absolute';panel.style.left=(panelRect.left-workspaceRect.left+workspace.scrollLeft)+'px';panel.style.top=(panelRect.top-workspaceRect.top+workspace.scrollTop)+'px';drag={x:event.clientX,y:event.clientY,left:parseFloat(panel.style.left),top:parseFloat(panel.style.top),pointer:event.pointerId};bar.setPointerCapture(event.pointerId);});
    bar.addEventListener('pointermove',event=>{if(!drag||event.pointerId!==drag.pointer)return;const maxLeft=Math.max(0,workspace.scrollWidth-panel.offsetWidth);const maxTop=Math.max(0,workspace.scrollHeight-panel.offsetHeight);panel.style.left=Math.max(0,Math.min(maxLeft,drag.left+event.clientX-drag.x))+'px';panel.style.top=Math.max(0,Math.min(maxTop,drag.top+event.clientY-drag.y))+'px';});
    function finishDrag(event){if(!drag||event.pointerId!==drag.pointer)return;drag=null;savePosition();}
    bar.addEventListener('pointerup',finishDrag);bar.addEventListener('pointercancel',finishDrag);restorePosition();
}
document.querySelectorAll('.detachable-window').forEach(initializeAtcWindow);
<?php if ($canOpen): ?>
let selectedMode='',selectedPosition='',selectedStation=null,searchTimer=null,searchController=null,searchSequence=0;const atcPanel=document.getElementById('atcSetupWindow');const station=document.getElementById('station');const next=document.getElementById('continueButton');const results=document.getElementById('stationResults');const details=document.getElementById('stationDetails');const selectionArea=document.getElementById('selectionArea');const selectionHint=document.getElementById('selectionHint');
function update(){next.disabled=!selectedPosition||!selectedStation;}
function escapeText(value){return String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
function chooseStation(item){selectedStation=item;station.value=item.code;results.hidden=true;details.hidden=false;details.innerHTML='<strong>'+escapeText(item.code)+'</strong> · '+escapeText(item.name)+(item.municipality?' · '+escapeText(item.municipality):'')+'<br><small>'+escapeText(item.kind_label)+(item.operation_label?' · '+escapeText(item.operation_label):'')+'</small>';const eligible=Array.isArray(item.eligible_positions)?item.eligible_positions:[];atcPanel.querySelectorAll('.position').forEach(button=>{const rankAllowed=selectedMode==='spectator'||button.dataset.rankAllowed==='1';const usable=rankAllowed&&eligible.includes(button.dataset.code);button.disabled=!usable;button.classList.toggle('allowed',usable);button.classList.toggle('locked',!usable);if(!usable&&button.classList.contains('selected')){button.classList.remove('selected');selectedPosition='';}});update();station.focus();}
async function searchStations(){const query=station.value.trim();const sequence=++searchSequence;if(searchController)searchController.abort();searchController=new AbortController();selectedStation=null;details.hidden=true;update();if(query.length<2){results.hidden=true;return;}try{const response=await fetch('execute/atc_station_search.php?q='+encodeURIComponent(query)+'&spectator='+(selectedMode==='spectator'?'1':'0'),{signal:searchController.signal});const data=await response.json();if(sequence!==searchSequence)return;if(!response.ok||!data.success)throw new Error(data.message||'search_failed');const items=Array.isArray(data.items)?data.items:[];const exact=items.find(item=>String(item.code).toUpperCase()===query.toUpperCase());if(exact){chooseStation(exact);return;}results.innerHTML='';items.forEach(item=>{const button=document.createElement('button');button.type='button';button.className='station-result';button.innerHTML='<strong>'+escapeText(item.code)+'</strong> · '+escapeText(item.name)+'<small>'+escapeText(item.municipality||'')+' · '+escapeText(item.kind_label)+'</small>';button.addEventListener('mousedown',event=>{event.preventDefault();chooseStation(item);});results.appendChild(button);});if(items.length===0){results.innerHTML='<div class="station-result"><?php echo addslashes(t('atc_station_no_results')); ?></div>';}results.hidden=false;}catch(error){if(error.name==='AbortError'||sequence!==searchSequence)return;results.innerHTML='<div class="station-result"><?php echo addslashes(t('atc_station_search_error')); ?></div>';results.hidden=false;}}
function chooseMode(mode,button){selectedMode=mode;if(searchController)searchController.abort();++searchSequence;atcPanel.querySelectorAll('.mode-button').forEach(item=>item.classList.remove('selected'));button.classList.add('selected');atcPanel.querySelectorAll('.supervision-note').forEach(item=>item.hidden=mode==='spectator');selectionArea.hidden=false;selectedPosition='';selectedStation=null;station.value='';details.hidden=true;results.hidden=true;atcPanel.querySelectorAll('.position').forEach(item=>{item.classList.remove('selected','allowed');item.classList.add('locked');item.disabled=true;});update();station.focus();}
document.getElementById('controllerMode')?.addEventListener('click',function(){chooseMode('controller',this);});
document.getElementById('spectatorMode').addEventListener('click',function(){chooseMode('spectator',this);});
station.addEventListener('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(searchStations,180);});
station.addEventListener('keydown',function(event){if(event.key==='Escape')results.hidden=true;if(event.key==='Enter'){event.preventDefault();const first=results.querySelector('button.station-result');if(first)first.dispatchEvent(new MouseEvent('mousedown',{bubbles:true}));}});
atcPanel.querySelectorAll('.position').forEach(button=>button.addEventListener('click',function(){if(this.disabled)return;atcPanel.querySelectorAll('.position').forEach(item=>item.classList.remove('selected'));this.classList.add('selected');selectedPosition=this.dataset.code;update();}));
next.addEventListener('click',async function(){next.disabled=true;try{const body=new URLSearchParams({station:selectedStation.code,position:selectedPosition,spectator:selectedMode==='spectator'?'1':'0'});const response=await fetch('execute/atc_session_start.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'session_failed');selectionHint.textContent=data.callsign+' – '+(data.spectator?'<?php echo addslashes(t('atc_spectator_ready')); ?>':'<?php echo addslashes(t('atc_position_ready')); ?>');}catch(error){selectionHint.textContent=error.message;}finally{update();}});
<?php endif; ?>
}
</script>
</body></html>
