<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/includes/language.php';
$initialAirport = strtoupper(trim((string)($_GET['airport'] ?? '')));
?>
<!doctype html><html lang="<?php echo htmlspecialchars((string)($_SESSION['language'] ?? 'en')); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>VFN ATIS</title><style>
:root{--green:#71ff91;--line:#185b2b;--bg:#010b05;--panel:#031309}*{box-sizing:border-box}
body{margin:0;background:repeating-linear-gradient(0deg,rgba(25,95,42,.07) 0 1px,transparent 1px 4px),var(--bg);color:#9cebaa;font:15px Consolas,monospace}
.window{min-height:100vh;border:1px solid var(--line);background:rgba(3,19,9,.95)}
.bar{height:42px;padding:9px 12px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;color:var(--green);font-weight:bold}.bar button{background:none;border:1px solid var(--line);color:var(--green);padding:4px 9px}
.content{padding:14px}.collapsed .content{display:none}.collapsed{min-height:42px}
select,input,textarea,button{font:inherit;color:var(--green);background:#020b05;border:1px solid var(--line);padding:8px;width:100%}label{display:block;margin:10px 0 4px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}.full{grid-column:1/-1}.actions{display:flex;gap:8px;margin-top:12px}.actions button{cursor:pointer}.status{margin-top:10px;white-space:pre-wrap}.managed{color:#ffd36a}@media(max-width:520px){.grid{grid-template-columns:1fr}}
</style></head><body><section class="window" id="atisWindow"><header class="bar"><span>VFN ATIS MANAGER</span><span><button id="collapse" type="button">–</button> <button onclick="window.close()" type="button">×</button></span></header><div class="content">
<label for="airport">ATIS airport</label><select id="airport"></select>
<form id="form"><div class="grid">
<label>Arrival runways<input name="arrival_runways" maxlength="64"></label><label>Departure runways<input name="departure_runways" maxlength="64"></label>
<label>Transition level<input name="transition_level" maxlength="16"></label><label>Transition altitude<input name="transition_altitude" maxlength="16"></label>
<label class="full">Approach type<input name="approach_type" maxlength="64"></label><label class="full">Remarks<textarea name="remarks" maxlength="500" rows="4"></textarea></label>
</div><div class="actions"><button type="submit">Save manual ATIS</button><button id="automatic" type="button">Automatic</button></div></form><div id="status" class="status">Loading…</div>
</div></section><script>
const initialAirport=<?php echo json_encode($initialAirport); ?>, select=document.getElementById('airport'), form=document.getElementById('form'), statusBox=document.getElementById('status');
const channel=typeof BroadcastChannel==='function'?new BroadcastChannel('vfn-atc-atis'):null;
function fieldsDisabled(value){form.querySelectorAll('input,textarea,button').forEach(el=>el.disabled=value)}
async function loadAirports(){const r=await fetch('execute/atc_atis_airports.php?time='+Date.now());const d=await r.json();if(!d.success)throw Error(d.message);select.innerHTML='';(d.airports||[]).forEach(a=>{const o=document.createElement('option');o.value=a.icao;o.textContent=a.icao+' · '+a.name+' · '+a.frequency+' MHz'+(a.editable?'':' · managed locally');o.disabled=!a.editable;select.appendChild(o)});if(initialAirport&&[...select.options].some(o=>o.value===initialAirport&&!o.disabled))select.value=initialAirport;else{const first=[...select.options].find(o=>!o.disabled);if(first)select.value=first.value}await loadSettings()}
async function loadSettings(){if(!select.value){statusBox.textContent='No editable ATIS airport in this sector.';fieldsDisabled(true);return}const r=await fetch('execute/atc_atis_settings.php?airport='+encodeURIComponent(select.value)+'&time='+Date.now());const d=await r.json();if(!d.success)throw Error(d.message);for(const [key,value] of Object.entries(d.settings||{})){const field=form.elements[key];if(field)field.value=value||''}fieldsDisabled(false);statusBox.textContent=d.broadcast?'Information '+(d.broadcast.info_letter||'–')+' · '+(d.broadcast.frequency||'–')+' MHz\n'+(d.broadcast.atis_text||''):'ATIS is being prepared.'}
async function submit(action){const body=new FormData(form);body.set('airport',select.value);body.set('action',action);const r=await fetch('execute/atc_atis_settings.php',{method:'POST',body});const d=await r.json();if(!d.success)throw Error(d.message);channel?.postMessage({type:'updated',airport:select.value});await loadSettings()}
form.addEventListener('submit',e=>{e.preventDefault();submit('save').catch(e=>statusBox.textContent=e.message)});document.getElementById('automatic').onclick=()=>submit('automatic').catch(e=>statusBox.textContent=e.message);select.onchange=()=>loadSettings().catch(e=>statusBox.textContent=e.message);
document.getElementById('collapse').onclick=()=>{const w=document.getElementById('atisWindow');w.classList.toggle('collapsed');document.getElementById('collapse').textContent=w.classList.contains('collapsed')?'+':'–';try{window.resizeTo(window.outerWidth,w.classList.contains('collapsed')?105:650)}catch(e){}};
channel?.addEventListener('message',event=>{if(event.data?.type==='updated'&&event.data.airport===select.value)loadSettings().catch(()=>{})});loadAirports().catch(e=>statusBox.textContent=e.message);
</script></body></html>
