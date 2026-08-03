<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/execute/config.php';
require_once __DIR__ . '/includes/language.php';
$countries = require __DIR__ . '/includes/countries.php';
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [1,7,30,90,365], true)) {
    $days = 30;
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($currentLanguage); ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars(t('statistics_title')); ?> - <?php echo htmlspecialchars($projectName); ?></title>
<style>
body{margin:0;background:radial-gradient(circle at 20% 10%,rgba(0,132,255,.18),transparent 32%),#07141f;color:#d7e8ff;font-family:Arial,sans-serif}.shell{width:min(1500px,calc(100% - 36px));margin:34px auto}.hero{display:flex;justify-content:space-between;align-items:end;gap:20px}.period{padding:10px;background:#071521;color:white;border:1px solid #285475;border-radius:5px}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:22px 0}.stat,.card{background:#0d1d2a;border:1px solid #285475;border-radius:8px;padding:18px}.stat strong{display:block;color:#55e9c1;font-size:28px}.columns{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.ranking{display:grid;gap:9px}.rank{display:grid;grid-template-columns:42px 1fr auto;gap:10px;align-items:center;padding:11px;background:#081925;border-radius:5px}.number{font-size:20px;color:#55aaff;font-weight:bold}.meta{color:#93acc3;font-size:12px;margin-top:3px}.flag{width:22px;height:15px;object-fit:cover;vertical-align:-2px;margin-right:7px}.map-link{display:inline-block;color:white;background:#176dcc;padding:10px 14px;border-radius:5px;text-decoration:none}.pilot-card{margin-top:18px}.pilot-card-head{display:flex;justify-content:space-between;gap:16px;align-items:center}.pilot-name{color:#65bdff;text-decoration:none;font-weight:bold}.online-dot-small{display:inline-block;width:9px;height:9px;border-radius:50%;background:#36c64b;margin-left:6px;box-shadow:0 0 7px #36c64b}.pilot-value{text-align:right}.live-link{color:#55e9c1;text-decoration:none;font-size:12px}@media(max-width:1050px){.columns{grid-template-columns:1fr}}@media(max-width:800px){.summary{grid-template-columns:1fr 1fr}.hero,.pilot-card-head{align-items:start;flex-direction:column}}
</style></head><body>
<?php require __DIR__ . '/includes/header.php'; ?>
<main class="shell"><section class="hero"><div><h1><?php echo htmlspecialchars(t('statistics_title')); ?></h1><p><?php echo htmlspecialchars(t('statistics_text')); ?></p></div>
<form method="get"><select class="period" name="days" onchange="this.form.submit()"><?php foreach([1,7,30,90,365] as $value): ?><option value="<?php echo $value; ?>" <?php echo $days===$value?'selected':''; ?>><?php echo htmlspecialchars(t('statistics_period_'.$value)); ?></option><?php endforeach; ?></select></form></section>
<div id="statisticsLoading"><?php echo htmlspecialchars(t('statistics_loading')); ?></div>
<section id="statisticsContent" hidden>
<div class="summary"><div class="stat"><strong id="sumFlights">0</strong><?php echo htmlspecialchars(t('statistics_flights')); ?></div><div class="stat"><strong id="sumPilots">0</strong><?php echo htmlspecialchars(t('statistics_pilots')); ?></div><div class="stat"><strong id="sumDistance">0</strong><?php echo htmlspecialchars(t('statistics_distance')); ?></div><div class="stat"><strong id="sumHours">0</strong><?php echo htmlspecialchars(t('statistics_hours')); ?></div></div>
<div class="columns"><section class="card"><h2><?php echo htmlspecialchars(t('statistics_top_airports')); ?></h2><div class="ranking" id="topAirports"></div></section><section class="card"><h2><?php echo htmlspecialchars(t('statistics_top_pilot_countries')); ?></h2><div class="ranking" id="topPilotCountries"></div></section><section class="card"><h2><?php echo htmlspecialchars(t('statistics_top_movement_countries')); ?></h2><div class="ranking" id="topMovementCountries"></div></section></div>
<section class="card pilot-card"><div class="pilot-card-head"><div><h2><?php echo htmlspecialchars(t('statistics_top_pilots')); ?></h2><p><?php echo htmlspecialchars(t('statistics_top_pilots_text')); ?></p></div><label><?php echo htmlspecialchars(t('statistics_sort_by')); ?> <select class="period" id="pilotSort"><option value="flights"><?php echo htmlspecialchars(t('statistics_sort_flights')); ?></option><option value="distance"><?php echo htmlspecialchars(t('statistics_sort_distance')); ?></option><option value="hours"><?php echo htmlspecialchars(t('statistics_sort_hours')); ?></option><option value="landings"><?php echo htmlspecialchars(t('statistics_sort_landings')); ?></option></select></label></div><div class="ranking" id="topPilots"></div></section>
<p><a class="map-link" href="map.php?heatmap=1&days=<?php echo $days; ?>"><?php echo htmlspecialchars(t('statistics_open_heatmap')); ?></a></p>
</section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/auth_modals.php'; ?>
<script>
const COUNTRY_NAMES=<?php echo json_encode($countries, JSON_UNESCAPED_UNICODE); ?>;
const TXT=<?php echo json_encode(['movements'=>t('statistics_movements'),'flights'=>t('statistics_flights'),'pilots'=>t('statistics_pilots'),'airports'=>t('statistics_airports'),'landings'=>t('statistics_landings'),'live'=>t('statistics_view_live'),'empty'=>t('statistics_empty')],JSON_UNESCAPED_UNICODE); ?>;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function metricText(pilot,sort){if(sort==='distance')return Number(pilot.distance_nm).toLocaleString()+' NM';if(sort==='hours')return (Number(pilot.duration_seconds)/3600).toLocaleString(undefined,{maximumFractionDigits:1})+' h';if(sort==='landings')return pilot.landings+' '+TXT.landings;return pilot.flights+' '+TXT.flights}
function loadStatistics(){
const sort=document.getElementById('pilotSort').value;
fetch('execute/network_statistics.php?days=<?php echo $days; ?>&pilot_sort='+encodeURIComponent(sort)).then(r=>r.json()).then(data=>{
 if(!data.success)throw new Error();
 const s=data.summary||{};document.getElementById('sumFlights').textContent=s.flights||0;document.getElementById('sumPilots').textContent=s.pilots||0;document.getElementById('sumDistance').textContent=Number(s.distance_nm||0).toLocaleString()+' NM';document.getElementById('sumHours').textContent=Math.round(Number(s.duration_seconds||0)/3600).toLocaleString()+' h';
 const airports=data.top_airports||[];document.getElementById('topAirports').innerHTML=airports.length?airports.map((a,i)=>`<div class="rank"><span class="number">${i+1}</span><div><a class="pilot-name" href="airport.php?icao=${encodeURIComponent(a.code)}">${esc(a.code)}</a> ${esc(a.name||'')}<div class="meta">${esc(a.municipality||'')}</div></div><strong>${a.movements} ${esc(TXT.movements)}</strong></div>`).join(''):`<p>${esc(TXT.empty)}</p>`;
 const origins=data.top_pilot_countries||[];document.getElementById('topPilotCountries').innerHTML=origins.length?origins.map((c,i)=>{const flag=c.code!=='--'?`<img class="flag" src="images/flags/${esc(c.code.toLowerCase())}.png" alt="">`:'';return `<div class="rank"><span class="number">${i+1}</span><div><strong>${flag}${esc(COUNTRY_NAMES[c.code]||c.code)}</strong><div class="meta">${c.flights} ${esc(TXT.flights)}</div></div><strong>${c.pilots} ${esc(TXT.pilots)}</strong></div>`}).join(''):`<p>${esc(TXT.empty)}</p>`;
 const movements=data.top_movement_countries||[];document.getElementById('topMovementCountries').innerHTML=movements.length?movements.map((c,i)=>{const flag=c.code!=='--'?`<img class="flag" src="images/flags/${esc(c.code.toLowerCase())}.png" alt="">`:'';return `<div class="rank"><span class="number">${i+1}</span><div><strong>${flag}${esc(COUNTRY_NAMES[c.code]||c.code)}</strong><div class="meta">${c.airports} ${esc(TXT.airports)}</div></div><strong>${c.movements} ${esc(TXT.movements)}</strong></div>`}).join(''):`<p>${esc(TXT.empty)}</p>`;
 const pilots=data.top_pilots||[];document.getElementById('topPilots').innerHTML=pilots.length?pilots.map((p,i)=>{const flag=p.country_code?`<img class="flag" src="images/flags/${esc(p.country_code.toLowerCase())}.png" alt="">`:'';const name=p.real_name?`${p.real_name} (${p.username})`:p.username;const live=p.online?`<span class="online-dot-small"></span> <a class="live-link" href="map.php?pilot_id=${p.user_id}&follow=1">${esc(TXT.live)}</a>`:'';return `<div class="rank"><span class="number">${i+1}</span><div>${flag}<a class="pilot-name" href="profile.php?id=${p.user_id}">${esc(name)}</a><div class="meta">${p.flights} ${esc(TXT.flights)} · ${Number(p.distance_nm).toLocaleString()} NM · ${(Number(p.duration_seconds)/3600).toLocaleString(undefined,{maximumFractionDigits:1})} h · ${p.landings} ${esc(TXT.landings)} ${live}</div></div><strong class="pilot-value">${esc(metricText(p,sort))}</strong></div>`}).join(''):`<p>${esc(TXT.empty)}</p>`;
 document.getElementById('statisticsLoading').hidden=true;document.getElementById('statisticsContent').hidden=false;
}).catch(()=>{document.getElementById('statisticsLoading').textContent='Server error';});}
document.getElementById('pilotSort').addEventListener('change',loadStatistics);loadStatistics();
</script></body></html>
