<?php

require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();

require_once 'execute/config.php';
require_once 'includes/language.php';

if (!isset($projectName) || trim($projectName) === '') {
    $projectName = "Flight Radar Sim Project";
}

$isWebLoggedIn =
    isset($_SESSION['web_user_id']);

$viewerOpPermission = 0;
$mapWaypointLabelsMode = 'always';
if ($isWebLoggedIn) {
    try {
        $mapPdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $mapViewerStmt = $mapPdo->prepare(
            "SELECT op_permission, map_waypoint_labels_mode
             FROM users WHERE id = :id LIMIT 1"
        );
        $mapViewerStmt->execute(['id' => (int)$_SESSION['web_user_id']]);
        $mapViewer = $mapViewerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $viewerOpPermission = (int)($mapViewer['op_permission'] ?? 0);
        $mapWaypointLabelsMode =
            ($mapViewer['map_waypoint_labels_mode'] ?? 'always') === 'hover'
                ? 'hover'
                : 'always';
    } catch (Throwable $error) {
        error_log('Map viewer permission lookup failed: ' . $error->getMessage());
    }
}

if (!isset($showRatings)) {
    $showRatings = false;
}


?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($projectName); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <style>
        html,
        body {
            margin: 0;
            padding: 0;

            width: 100%;
            height: 100%;

            overflow: hidden;

            background: #111;

            font-family: Arial, sans-serif;
        }

        #map {
            width: 100%;
            height: calc(100vh - 128px);
        }

        #statusBox {
            position: absolute;

            top: 92px;
            left: 65px;

            z-index: 1000;

            background: rgba(0, 0, 0, 0.75);

            color: #00ffcc;

            padding: 10px 14px;

            border-radius: 6px;

            font-size: 14px;

            min-width: 220px;
        }
        #mapLegend{position:absolute;left:14px;bottom:28px;z-index:900;background:rgba(5,20,31,.9);border:1px solid #285475;border-radius:6px;padding:10px 12px;color:#cde4f7;font-size:12px;display:grid;gap:5px}.legend-swatch{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:7px;vertical-align:-2px}.legend-aircraft{background:#168cff}.legend-airport{background:#20b8ff}.legend-nav{background:#8f54ff}.legend-fir{background:rgba(22,140,255,.25);border:1px solid #37a5ff}.legend-atc{background:#ff8a16;border:2px solid #fff3c4}.atc-airport-stack{position:relative;width:110px;height:110px;transform:scale(var(--atc-symbol-scale,.25));transform-origin:50% 50%;filter:drop-shadow(0 2px 3px rgba(0,0,0,.5));pointer-events:auto}.atc-position-symbol{position:absolute;left:50%;top:50%;display:block;transform:translate(-50%,-50%);font-size:0}.atc-position-symbol.dep{z-index:1;width:104px;height:104px;border:3px solid #5430b9;border-radius:50%;background:rgba(112,75,210,.18)}.atc-position-symbol.app{z-index:2;width:86px;height:86px;border:3px solid #172d89;border-radius:50%;background:rgba(70,102,207,.31)}.atc-position-symbol.twr{z-index:3;width:66px;height:66px;border:2px solid #ff3535;border-radius:50%;background:rgba(255,67,67,.31)}.atc-position-symbol.gnd,.atc-position-symbol.del{z-index:4;width:82px;height:82px;background:#232323;clip-path:polygon(50% 0,58% 39%,100% 50%,58% 61%,50% 100%,42% 61%,0 50%,42% 39%)}.atc-position-symbol.gnd:after,.atc-position-symbol.del:after{content:"";position:absolute;inset:2px;background:#fff27a;clip-path:inherit}.atc-position-symbol.del{transform:translate(-50%,-50%) rotate(45deg)}.atc-position-symbol.del:after{background:#f2bd65}.atc-position-symbol.info{z-index:5;width:13px;height:13px;border:2px solid #17256c;border-radius:50%;background:#8797d8}.atc-airport-stack:after{content:"";position:absolute;z-index:6;left:50%;top:50%;width:9px;height:9px;transform:translate(-50%,-50%);border:2px solid #17256c;border-radius:50%;background:#8797d8}.atc-map-tooltip{font-size:12px;line-height:1.45}.atc-map-tooltip strong{color:#1737a6}.atc-fir-label{background:#c65c00;border:2px solid #fff3c4;color:#fff;font-weight:bold;border-radius:4px;padding:3px 6px;box-shadow:0 2px 8px rgba(0,0,0,.7)}

        #mapDirectory {
            position: absolute;
            top: 92px;
            right: 18px;
            z-index: 1800;
            width: 310px;
            max-height: calc(100vh - 160px);
            overflow: hidden;
            border-radius: 8px;
            background: rgba(7, 21, 33, 0.94);
            color: #d7e8ff;
            box-shadow: 0 8px 28px rgba(0,0,0,.42);
            border: 1px solid rgba(68,139,198,.55);
        }

        .map-directory-header {
            padding: 13px 14px;
            background: #1737a6;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
        }

        .map-directory-toggle {
            border: 0;
            color: white;
            background: transparent;
            cursor: pointer;
            font-size: 18px;
        }

        .map-directory-body { padding: 12px; }
        #mapDirectory.collapsed .map-directory-body { display: none; }
        #pilotSearch {
            width: 100%;
            padding: 10px;
            border: 1px solid #285475;
            border-radius: 4px;
            color: white;
            background: #071521;
            box-sizing: border-box;
        }
        .map-search-filters {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
            margin-top: 8px;
            color: #bcd2e8;
            font-size: 12px;
        }
        .map-search-filters select {
            min-width: 0;
            padding: 8px;
            color: white;
            background: #071521;
            border: 1px solid #285475;
            border-radius: 4px;
        }
        .map-search-filters label {
            display: flex;
            gap: 5px;
            align-items: center;
            white-space: nowrap;
        }
        .map-layer-tools {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
            margin-bottom: 10px;
            color: #bcd2e8;
            font-size: 13px;
        }
        .map-layer-tools select {
            padding: 7px;
            color: white;
            background: #071521;
            border: 1px solid #285475;
            border-radius: 4px;
        }
        .map-statistics-link {color:#66bdff;text-decoration:none;font-size:12px;margin-bottom:10px;display:inline-block}
        .map-invisible-filter,
        .map-nearby-filter {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 10px;
            color: #d7e8ff;
            font-size: 14px;
            line-height: 1.25;
            cursor: pointer;
        }
        .map-invisible-filter input,
        .map-nearby-filter input {
            flex: 0 0 auto;
            width: 16px;
            height: 16px;
            margin: 0;
            accent-color: #168cff;
        }
        #pilotDirectoryList {
            display: grid;
            gap: 7px;
            max-height: calc(100vh - 260px);
            overflow-y: auto;
            margin-top: 10px;
        }
        .pilot-directory-item {
            border: 1px solid #244c69;
            border-radius: 5px;
            padding: 9px 10px;
            color: #d7e8ff;
            background: #0b1c29;
            cursor: pointer;
            text-align: left;
        }
        .pilot-directory-item:hover,
        .pilot-directory-item.active { border-color: #55aaff; background: #12304a; }
        .pilot-directory-callsign { color: #55b4ff; font-weight: bold; }
        .pilot-directory-item.airport-result .pilot-directory-callsign { color:#56e0b3; }
        .pilot-directory-meta { color: #95adc4; font-size: 12px; margin-top: 3px; }
        .airport-metar{display:grid;gap:12px}
        .airport-metar-raw{padding:14px;border-radius:5px;background:#091722;color:#65e7bc;font-family:monospace;font-size:14px;line-height:1.5;word-break:break-word}
        .airport-metar-time{color:#60758a;font-size:12px}
        .panel-action {
            display: block;
            width: 100%;
            border: 0;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
            color: white;
            background: #176dcc;
            cursor: pointer;
            font-weight: bold;
            text-align: center;
            text-decoration: none;
        }
        .panel-action.following { background: #159447; }
        .panel-action.route-visible { background: #d97800; }
        .panel-action[hidden] { display: none; }
        .pilot-panel-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
            gap: 8px;
            margin-bottom: 10px;
        }
        .pilot-panel-actions .panel-action { margin-bottom: 0; }
        .flightplan-waypoint-label {
            color: #f28c18;
            background: rgba(7, 20, 31, 0.88);
            border: 1px solid #f28c18;
            box-shadow: none;
            font-weight: bold;
            font-size: 10px;
            padding: 2px 5px;
        }
        .sector-waypoint-label {
            color: #a9e8ff;
            text-shadow: 0 1px 3px #000, 0 0 5px #000;
            font: bold 10px/12px Consolas, monospace;
            white-space: nowrap;
            pointer-events: none;
        }
        .sector-waypoint-label::before {
            content: "";
            display: inline-block;
            width: 6px;
            height: 6px;
            margin-right: 4px;
            border: 1px solid #a9e8ff;
            background: #07141f;
            transform: rotate(45deg);
            vertical-align: 1px;
        }
        .airport-panel-link {
            color: inherit;
            background: transparent;
            border: 0;
            padding: 0;
            cursor: pointer;
            font: inherit;
            font-weight: bold;
        }
        .airport-panel-link:hover { text-decoration: underline; }

        .pilot-label {
            background: rgba(0, 0, 0, 0.75);

            color: white;

            border: 0;

            padding: 5px 8px;

            border-radius: 4px;

            font-size: 11px;

            line-height: 1.3;

            white-space: nowrap;
        }

        .pilot-label-emergency-box {
            background: #d00000;

            color: white;

            border: 2px solid white;

            padding: 7px 10px;

            border-radius: 6px;

            box-shadow: 0 0 12px rgba(255, 0, 0, 0.85);

            font-size: 12px;

            font-weight: bold;

            line-height: 1.35;

            text-align: center;
        }

        .pilot-label-normal-box {
            text-align: center;
        }

        #pilotInfoPanel,
        #airportTrafficPanel,
        #navigationPointPanel,
        #atcInfoPanel {
            position: absolute;

            top: 82px;
            left: 0;

            width: 330px;
            height: calc(100vh - 82px);

            z-index: 2000;

            background: #f1f3f7;

            box-shadow: 3px 0 12px rgba(0, 0, 0, 0.35);

            transform: translateX(-100%);

            transition: transform 0.25s ease-in-out;

            overflow-y: auto;
        }

        #pilotInfoPanel.open {
            transform: translateX(0);
        }

        #airportTrafficPanel.open {
            transform: translateX(0);
        }
        #navigationPointPanel.open { transform:translateX(0); }
        #atcInfoPanel.open { transform:translateX(0); }

        .atc-info-list { position:relative; z-index:3; display:grid; gap:9px; }
        .atc-info-card { background:#fff; border:1px solid #d5deeb; border-left:5px solid #ff8318; border-radius:7px; padding:11px 12px; color:#182536; }
        .atc-info-card strong { color:#1737a6; font-size:15px; }
        .atc-info-card .atc-position-code { float:right; color:#c65c00; font-weight:bold; }
        button.atc-info-card { display:block; width:100%; text-align:left; font:inherit; cursor:pointer; }
        button.atc-info-card:hover, button.atc-info-card:focus-visible { border-color:#2477d4; border-left-color:#ff8318; background:#f2f7ff; outline:none; }
        .atc-info-meta { margin-top:5px; color:#536579; font-size:12px; line-height:1.45; }

        #airportTrafficPanel .panel-route {
            grid-template-columns: 1fr;
            text-align: left;
        }

        .panel-header {
            background: #1737a6;

            color: white;

            padding: 16px;

            font-size: 22px;

            font-weight: bold;

            display: flex;

            align-items: center;
            justify-content: space-between;
        }

        .panel-close {
            cursor: pointer;

            font-size: 24px;

            font-weight: bold;

            user-select: none;
        }

        .panel-route {
            background: #506fc4;

            color: white;

            padding: 14px 16px;

            display: grid;

            grid-template-columns: 1fr 40px 1fr;

            align-items: center;

            text-align: center;

            font-size: 20px;

            font-weight: bold;
        }

        .panel-route-plane {
            font-size: 20px;
        }

        .panel-airport-name {
            font-size: 11px;

            font-weight: normal;

            opacity: 0.95;

            margin-top: 3px;

            line-height: 1.2;
        }

        .panel-content {
            padding: 14px;
        }

        .panel-card {
            background: white;

            border-radius: 8px;

            padding: 12px;

            margin-bottom: 12px;

            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        }

        .panel-card-title {
            color: #1737a6;

            font-weight: bold;

            margin-bottom: 8px;

            font-size: 15px;
        }

        .panel-grid {
            display: grid;

            grid-template-columns: repeat(3, 1fr);

            gap: 8px;

            margin-bottom: 12px;
        }

        .panel-stat {
            background: #1737a6;

            color: white;

            border-radius: 8px;

            padding: 10px 6px;

            text-align: center;
        }

        .panel-stat-value {
            font-size: 17px;

            font-weight: bold;

            line-height: 1.2;
        }

        .panel-stat-label {
            font-size: 10px;

            opacity: 0.9;

            margin-top: 3px;

            text-transform: uppercase;
        }

        .panel-row {
            display: flex;

            justify-content: space-between;

            gap: 10px;

            padding: 6px 0;

            border-bottom: 1px solid #e0e0e0;

            font-size: 14px;
        }

        .panel-row:last-child {
            border-bottom: 0;
        }

        .panel-row-label {
            color: #555;
        }

        .panel-row-value {
            font-weight: bold;

            color: #111;

            text-align: right;
        }

        .panel-status-online {
            color: #0a9f35;

            font-weight: bold;
        }

        .panel-squawk-emergency {
            background: #d00000;

            color: white;

            padding: 3px 7px;

            border-radius: 4px;

            box-shadow: 0 0 8px rgba(255, 0, 0, 0.55);
        }

        .panel-login-notice {
            background: rgba(23, 55, 166, 0.10);
            border: 1px solid rgba(23, 55, 166, 0.25);
            color: #1737a6;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            font-size: 13px;
            line-height: 1.45;
        }

        .airport-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .airport-tab-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            margin-bottom: 12px;
        }

        .airport-tab-button {
            border: 0;
            background: white;
            color: #1737a6;
            padding: 12px 8px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
        }

        .airport-tab-button.active {
            background: #1737a6;
            color: white;
        }

        .airport-traffic-list {
            display: grid;
            gap: 10px;
        }

        .airport-traffic-card {
            background: white;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            cursor: pointer;
        }

        .airport-traffic-card:hover {
            background: #f7f9ff;
        }

        .airport-traffic-main {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .airport-traffic-callsign {
            color: #1737a6;
            font-weight: bold;
            font-size: 16px;
        }

        .airport-traffic-aircraft {
            color: #111;
            font-weight: bold;
            font-size: 13px;
            text-align: right;
            white-space: nowrap;
        }

        .airport-traffic-route {
            margin-top: 5px;
            color: #111;
            font-weight: bold;
            font-size: 15px;
        }

        .airport-traffic-meta {
            margin-top: 4px;
            color: #666;
            font-size: 12px;
        }

        .airport-traffic-empty {
            background: white;
            border-radius: 8px;
            color: #666;
            padding: 14px;
            font-size: 14px;
            text-align: center;
        }

        .rating-container {
            display: grid;
            grid-template-columns: repeat(3, 80px);
            justify-content: start;
            column-gap: 10px;
            align-items: start;
            min-height: 120px;
        }

        .rating-container img {
            width: 80px;
            height: auto;
            cursor: help;
        }

        .rating-empty {
            color: #777;
            font-size: 13px;
            text-align: center;
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

@media (max-width: 760px) {
    #mapDirectory { top: 135px; right: 8px; width: min(300px, calc(100% - 16px)); }
    #mapDirectory:not(.collapsed) { max-height: 48vh; }
    #pilotInfoPanel, #airportTrafficPanel, #navigationPointPanel, #atcInfoPanel { top: 122px; width: min(360px, 100%); height: calc(100vh - 122px); }
    #statusBox { top: 135px; left: 8px; min-width: 0; }
}
</style>
</head>
<body>

<?php require_once 'includes/header.php'; ?>
<?php
// The page is read-only after the header has resolved the authenticated user.
// Do not keep its PHP session locked while the browser loads the map.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
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

<div id="statusBox">
    <?php echo htmlspecialchars(t("map_loading_pilots")); ?>
</div>

<aside id="mapDirectory">
    <div class="map-directory-header">
        <span><?php echo htmlspecialchars(t('map_pilot_directory')); ?></span>
        <button class="map-directory-toggle" type="button" id="mapDirectoryToggle" aria-label="<?php echo htmlspecialchars(t('map_toggle_list')); ?>">−</button>
    </div>
    <div class="map-directory-body">
        <div class="map-layer-tools">
            <label><input id="heatmapToggle" type="checkbox"> <?php echo htmlspecialchars(t('map_show_heatmap')); ?></label>
            <select id="heatmapPeriod">
                <?php foreach ([1, 7, 30, 90] as $mapDays): ?>
                    <option value="<?php echo $mapDays; ?>" <?php echo (int)($_GET['days'] ?? 30)===$mapDays?'selected':''; ?>>
                        <?php echo htmlspecialchars(t('statistics_period_' . $mapDays)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>
                <input id="firBoundaryToggle" type="checkbox">
                <?php echo htmlspecialchars(t('map_show_fir_boundaries')); ?>
            </label>
        </div>
        <a class="map-statistics-link" href="statistics.php"><?php echo htmlspecialchars(t('map_open_statistics')); ?></a>
        <?php if ($viewerOpPermission >= 1): ?>
            <label class="map-invisible-filter">
                <input id="hideInvisiblePilots" type="checkbox">
                <?php echo htmlspecialchars(t('map_hide_invisible_pilots')); ?>
            </label>
        <?php endif; ?>
        <input id="pilotSearch" type="search" placeholder="<?php echo htmlspecialchars(t('map_search_pilot')); ?>">
        <?php if (!empty($_SESSION['web_user_id'])): ?>
            <label class="map-nearby-filter"><input id="nearbyPilotsOnly" type="checkbox"> <?php echo htmlspecialchars(t('map_nearby_30nm')); ?></label>
        <?php endif; ?>
        <div class="map-search-filters">
            <select id="mapSearchType" aria-label="<?php echo htmlspecialchars(t('map_search_type')); ?>">
                <option value="all"><?php echo htmlspecialchars(t('map_search_type_all')); ?></option>
                <option value="pilot"><?php echo htmlspecialchars(t('map_search_type_pilots')); ?></option>
                <option value="airport"><?php echo htmlspecialchars(t('map_search_type_airports')); ?></option>
                <option value="waypoint"><?php echo htmlspecialchars(t('map_search_type_waypoints')); ?></option>
                <option value="navaid"><?php echo htmlspecialchars(t('map_search_type_navaids')); ?></option>
                <option value="airway"><?php echo htmlspecialchars(t('map_search_type_airways')); ?></option>
                <option value="radar"><?php echo htmlspecialchars(t('map_search_type_radars')); ?></option>
                <option value="atc"><?php echo htmlspecialchars(t('map_search_type_atc')); ?></option>
            </select>
            <label>
                <input id="mapSearchExact" type="checkbox">
                <?php echo htmlspecialchars(t('map_search_exact')); ?>
            </label>
        </div>
        <label class="map-nearby-filter" id="sectorWaypointsOption" hidden>
            <input id="sectorWaypointsToggle" type="checkbox">
            <?php echo htmlspecialchars(t('map_show_sector_waypoints')); ?>
        </label>
        <div id="pilotDirectoryList"></div>
    </div>
</aside>

<div id="pilotInfoPanel">
    <div class="panel-header">
        <span id="panelCallsign">----</span>
        <span class="panel-close" onclick="closePilotPanel()">×</span>
    </div>

    <div class="panel-route">
        <div>
            <button type="button" class="airport-panel-link" id="panelDeparture">ZZZZ</button>
            <div class="panel-airport-name" id="panelDepartureName"><?php echo htmlspecialchars(t("map_no_airport")); ?></div>
        </div>

        <div class="panel-route-plane">✈</div>

        <div>
            <button type="button" class="airport-panel-link" id="panelArrival">ZZZZ</button>
            <div class="panel-airport-name" id="panelArrivalName"><?php echo htmlspecialchars(t("map_no_airport")); ?></div>
        </div>
    </div>

    <div class="panel-content">

        <div class="pilot-panel-actions">
            <button type="button" class="panel-action" id="followPilotButton" onclick="toggleFollowSelectedPilot()">
                <?php echo htmlspecialchars(t('map_follow_pilot')); ?>
            </button>
            <button type="button" class="panel-action" id="flightPlanRouteButton" onclick="toggleSelectedFlightPlanRoute()" hidden>
                <?php echo htmlspecialchars(t('map_show_flightplan_route')); ?>
            </button>
        </div>

        <div class="panel-card">
            <div class="panel-card-title"><?php echo htmlspecialchars(t("map_panel_pilot")); ?></div>

            <div class="panel-row">
                <div class="panel-row-label">
                    <?php echo htmlspecialchars(t("map_panel_user")); ?>
                </div>

                <div class="panel-row-value">
                    <a id="panelUsername"
                       href="#"
                       style="
                            color:#1737a6;
                            text-decoration:none;
                            font-weight:bold;
                       ">
                        ----
                    </a>
                </div>
            </div>



            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_aircraft")); ?></div>
                <div class="panel-row-value" id="panelAircraft">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("profile_division")); ?></div>
                <div class="panel-row-value" id="panelDivision">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_category")); ?></div>
                <div class="panel-row-value" id="panelCategory">----</div>
            </div>
        </div>

        <?php if ($showRatings): ?>

            <div class="panel-card" id="panelRatingsCard">
                <div class="panel-card-title">Ratings</div>

                <div
                    id="panelRatings"
                    class="rating-container">
                </div>
            </div>

        <?php endif; ?>

        <?php if (!$isWebLoggedIn): ?>

            <div class="panel-login-notice">
                <?php echo htmlspecialchars(t("map_login_required_more_info")); ?>
            </div>

        <?php endif; ?>

        <div class="panel-card member-only">
            <div class="panel-card-title"><?php echo htmlspecialchars(t("map_panel_flightplan")); ?></div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_flight_rules")); ?></div>
                <div class="panel-row-value" id="panelFlightRules">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_flight_type")); ?></div>
                <div class="panel-row-value" id="panelFlightType">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_departure_time")); ?></div>
                <div class="panel-row-value" id="panelDepartureTime">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_alternate1")); ?></div>
                <div class="panel-row-value" id="panelAlternate1">ZZZZ</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_alternate2")); ?></div>
                <div class="panel-row-value" id="panelAlternate2">ZZZZ</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_cruising_level")); ?></div>
                <div class="panel-row-value" id="panelCruisingLevel">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_cruising_speed")); ?></div>
                <div class="panel-row-value" id="panelCruisingSpeed">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_route")); ?></div>
                <div class="panel-row-value" id="panelRouteText">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_info")); ?></div>
                <div class="panel-row-value" id="panelRemarks">----</div>
            </div>
        </div>

        <div class="panel-grid">
            <div class="panel-stat">
                <div class="panel-stat-value" id="panelAltitude">0</div>
                <div class="panel-stat-label"><?php echo htmlspecialchars(t("map_panel_altitude")); ?></div>
            </div>

            <div class="panel-stat">
                <div class="panel-stat-value" id="panelSpeed">0</div>
                <div class="panel-stat-label"><?php echo htmlspecialchars(t("map_panel_speed")); ?></div>
            </div>

            <div class="panel-stat">
                <div class="panel-stat-value" id="panelHeading">0°</div>
                <div class="panel-stat-label"><?php echo htmlspecialchars(t("map_panel_heading")); ?></div>
            </div>
        </div>

        <div class="panel-card member-only">
            <div class="panel-card-title"><?php echo htmlspecialchars(t('map_panel_flight_progress')); ?></div>
            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t('map_panel_elapsed_flight_time')); ?></div>
                <div class="panel-row-value" id="panelElapsedFlightTime">--:--</div>
            </div>
            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t('map_panel_remaining_flight_time')); ?></div>
                <div class="panel-row-value" id="panelRemainingFlightTime">----</div>
            </div>
            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t('map_panel_route_distance')); ?></div>
                <div class="panel-row-value" id="panelEstimatedRouteDistance">----</div>
            </div>
            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t('map_panel_remaining_route_distance')); ?></div>
                <div class="panel-row-value" id="panelRemainingRouteDistance">----</div>
            </div>
            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t('map_panel_route_calculation')); ?></div>
                <div class="panel-row-value" id="panelRouteCalculation">----</div>
            </div>
        </div>

        <div class="panel-card member-only">
            <div class="panel-card-title"><?php echo htmlspecialchars(t("map_panel_position")); ?></div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_latitude")); ?></div>
                <div class="panel-row-value" id="panelLatitude">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_longitude")); ?></div>
                <div class="panel-row-value" id="panelLongitude">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_pitch")); ?></div>
                <div class="panel-row-value" id="panelPitch">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_roll")); ?></div>
                <div class="panel-row-value" id="panelRoll">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_vertical_speed")); ?></div>
                <div class="panel-row-value" id="panelVerticalSpeed">----</div>
            </div>
        </div>

        <div class="panel-card member-only">
            <div class="panel-card-title"><?php echo htmlspecialchars(t("map_panel_radio")); ?></div>

            <div class="panel-row">
                <div class="panel-row-label">COM1</div>
                <div class="panel-row-value" id="panelCom1">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label">COM2</div>
                <div class="panel-row-value" id="panelCom2">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label">COM3</div>
                <div class="panel-row-value" id="panelCom3">----</div>
            </div>

            <div class="panel-row">
                <div class="panel-row-label">Squawk</div>
                <div class="panel-row-value" id="panelTransponder">----</div>
            </div>
        </div>

        <div class="panel-card">
            <div class="panel-card-title"><?php echo htmlspecialchars(t("map_panel_update")); ?></div>

            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t("map_panel_last_update")); ?></div>
                <div class="panel-row-value" id="panelLastUpdate">----</div>
            </div>
        </div>

    </div>
</div>

<div id="airportTrafficPanel">
    <div class="panel-header">
        <span id="airportPanelIcao">----</span>
        <span class="panel-close" onclick="closeAirportTrafficPanel()">×</span>
    </div>

    <div class="panel-route">
        <div>
            <div id="airportPanelName">
                <?php echo htmlspecialchars(t("map_unknown_airport")); ?>
            </div>
            <div class="panel-airport-name" id="airportPanelKind">
                <?php echo htmlspecialchars(t("map_airport_information")); ?>
            </div>
        </div>
    </div>

    <div class="panel-content">

        <a class="panel-action" id="airportDetailsLink" href="airport.php">
            <?php echo htmlspecialchars(t('map_airport_details')); ?>
        </a>
        <div class="panel-grid airport-summary">
            <div class="panel-stat">
                <div class="panel-stat-value" id="airportPanelInboundCount">0</div>
                <div class="panel-stat-label"><?php echo htmlspecialchars(t("map_airport_inbound")); ?></div>
            </div>

            <div class="panel-stat">
                <div class="panel-stat-value" id="airportPanelOutboundCount">0</div>
                <div class="panel-stat-label"><?php echo htmlspecialchars(t("map_airport_outbound")); ?></div>
            </div>
        </div>

        <div class="airport-tab-buttons">
            <button
                type="button"
                class="airport-tab-button active"
                id="airportInboundTab"
                onclick="setAirportTrafficTab('inbound')">
                <?php echo htmlspecialchars(t("map_airport_inbound")); ?>
            </button>

            <button
                type="button"
                class="airport-tab-button"
                id="airportOutboundTab"
                onclick="setAirportTrafficTab('outbound')">
                <?php echo htmlspecialchars(t("map_airport_outbound")); ?>
            </button>

            <button
                type="button"
                class="airport-tab-button"
                id="airportMetarTab"
                onclick="setAirportTrafficTab('metar')">
                METAR
            </button>
        </div>

        <div id="airportTrafficList" class="airport-traffic-list"></div>

        <div class="panel-card">
            <div class="panel-card-title"><?php echo htmlspecialchars(t('map_atc_online')); ?></div>
            <div id="airportAtcList" class="atc-info-list"></div>
        </div>
    </div>
</div>

<div id="atcInfoPanel">
    <div class="panel-header">
        <span id="atcInfoIdentifier">ATC</span>
        <span class="panel-close" onclick="closeAtcInfoPanel()">×</span>
    </div>
    <div class="panel-route">
        <div>
            <div id="atcInfoName"><?php echo htmlspecialchars(t('map_atc_online')); ?></div>
            <div class="panel-airport-name" id="atcInfoKind">----</div>
        </div>
    </div>
    <div class="panel-content">
        <button type="button" class="panel-action" id="atcAirportBackButton"
            onclick="openAtcAirportDetails()" style="display:none">
            <?php echo htmlspecialchars(t('map_open_airport_information')); ?>
        </button>
        <div class="panel-card">
            <div class="panel-card-title"><?php echo htmlspecialchars(t('map_atc_positions')); ?></div>
            <div id="atcInfoList" class="atc-info-list"></div>
        </div>
    </div>
</div>

<div id="navigationPointPanel">
    <div class="panel-header">
        <span id="navigationPointIdentifier">----</span>
        <span class="panel-close" onclick="closeNavigationPointPanel()">×</span>
    </div>
    <div class="panel-route">
        <div>
            <div id="navigationPointName">----</div>
            <div class="panel-airport-name" id="navigationPointKind">----</div>
        </div>
    </div>
    <div class="panel-content">
        <div class="panel-card">
            <div class="panel-card-title"><?php echo htmlspecialchars(t('map_navigation_details')); ?></div>
            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t('map_navigation_type')); ?></div>
                <div class="panel-row-value" id="navigationPointType">----</div>
            </div>
            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t('map_navigation_region')); ?></div>
                <div class="panel-row-value" id="navigationPointRegion">----</div>
            </div>
            <div class="panel-row">
                <div class="panel-row-label"><?php echo htmlspecialchars(t('map_navigation_frequency')); ?></div>
                <div class="panel-row-value" id="navigationPointFrequency">----</div>
            </div>
            <div class="panel-row">
                <div class="panel-row-label">AIRAC</div>
                <div class="panel-row-value" id="navigationPointCycle">----</div>
            </div>
        </div>
    </div>
</div>

<div id="map"></div>
<div id="mapLegend"><strong><?php echo htmlspecialchars(t('map_legend')); ?></strong><span><i class="legend-swatch legend-aircraft"></i><?php echo htmlspecialchars(t('map_legend_aircraft')); ?></span><span><i class="legend-swatch legend-airport"></i><?php echo htmlspecialchars(t('map_legend_airports')); ?></span><span><i class="legend-swatch legend-nav"></i><?php echo htmlspecialchars(t('map_legend_navigation')); ?></span><span><i class="legend-swatch legend-fir"></i><?php echo htmlspecialchars(t('map_legend_fir')); ?></span><span><i class="legend-swatch legend-atc"></i>ATC</span></div>

<?php require_once 'includes/auth_modals.php'; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

<script>
    const IS_WEB_LOGGED_IN =
        <?php echo $isWebLoggedIn ? 'true' : 'false'; ?>;

    const WEB_USER_ID =
        <?php echo (int)($_SESSION['web_user_id'] ?? 0); ?>;

    const CAN_SEE_AI_CONTROL_STATUS =
        <?php echo $viewerOpPermission >= 1 ? 'true' : 'false'; ?>;

    const MAP_WAYPOINT_LABELS_MODE =
        <?php echo json_encode($mapWaypointLabelsMode); ?>;

    const SHOW_RATINGS =
        <?php echo $showRatings ? 'true' : 'false'; ?>;

    const MAP_TEXT =
        <?php
        echo json_encode(
            [
                "loading_pilots" => t("map_loading_pilots"),
                "active_pilots" => t("map_active_pilots"),
                "active_atc" => t("map_active_atc"),
                "atc_online" => t("map_atc_online"),
                "atc_positions" => t("map_atc_positions"),
                "atc_none" => t("map_atc_none"),
                "atc_controller" => t("map_atc_controller"),
                "atc_trainer" => t("map_atc_trainer"),
                "atc_frequency" => t("map_atc_frequency"),
                "atc_radar_sector" => t("map_atc_radar_sector"),
                "atc_information" => t("map_atc_information"),
                "airport_information" => t("map_airport_information"),
                "atis_information" => t("map_atis_information"),
                "atis_runway" => t("map_atis_runway"),
                "atis_active" => t("map_atis_active"),
                "atis_preparing" => t("map_atis_preparing"),
                "atis_unavailable" => t("map_atis_unavailable"),
                "invisible_pilots" => t("map_invisible_pilots"),
                "last_update" => t("map_last_update"),
                "connection_error" => t("map_connection_error"),
                "error" => t("map_error"),

                "no_airport" => t("map_no_airport"),
                "unknown_airport" => t("map_unknown_airport"),

                "panel_pilot" => t("map_panel_pilot"),
                "panel_user" => t("map_panel_user"),
                "panel_status" => t("map_panel_status"),
                "panel_online" => t("map_panel_online"),
                "panel_aircraft" => t("map_panel_aircraft"),
                "panel_category" => t("map_panel_category"),

                "panel_flightplan" => t("map_panel_flightplan"),
                "panel_flight_rules" => t("map_panel_flight_rules"),
                "panel_flight_type" => t("map_panel_flight_type"),
                "panel_departure_time" => t("map_panel_departure_time"),
                "panel_alternate1" => t("map_panel_alternate1"),
                "panel_alternate2" => t("map_panel_alternate2"),
                "panel_cruising_level" => t("map_panel_cruising_level"),
                "panel_cruising_speed" => t("map_panel_cruising_speed"),
                "panel_route" => t("map_panel_route"),
                "panel_info" => t("map_panel_info"),

                "panel_altitude" => t("map_panel_altitude"),
                "panel_speed" => t("map_panel_speed"),
                "panel_heading" => t("map_panel_heading"),

                "panel_position" => t("map_panel_position"),
                "panel_latitude" => t("map_panel_latitude"),
                "panel_longitude" => t("map_panel_longitude"),
                "panel_pitch" => t("map_panel_pitch"),
                "panel_roll" => t("map_panel_roll"),
                "panel_vertical_speed" => t("map_panel_vertical_speed"),

                "panel_radio" => t("map_panel_radio"),
                "panel_update" => t("map_panel_update"),
                "panel_last_update" => t("map_panel_last_update"),
                "login_required_more_info" => t("map_login_required_more_info"),

                "airport_traffic" => t("map_airport_traffic"),
                "airport_inbound" => t("map_airport_inbound"),
                "airport_outbound" => t("map_airport_outbound"),
                "airport_no_inbound" => t("map_airport_no_inbound"),
                "airport_no_outbound" => t("map_airport_no_outbound"),
                "airport_search_result" => t("map_airport_search_result"),
                "airport_metar_loading" => t("map_airport_metar_loading"),
                "airport_metar_unavailable" => t("map_airport_metar_unavailable"),
                "airport_metar_observed" => t("map_airport_metar_observed"),
                "airport_aircraft" => t("map_airport_aircraft"),
                "airport_departure_time" => t("map_airport_departure_time"),
                "follow_pilot" => t("map_follow_pilot"),
                "stop_following" => t("map_stop_following"),
                "route_via_waypoints" => t("map_route_via_waypoints"),
                "route_direct" => t("map_route_direct"),
                "route_estimating" => t("map_route_estimating"),
                "route_unavailable" => t("map_route_unavailable"),
                "show_flightplan_route" => t("map_show_flightplan_route"),
                "hide_flightplan_route" => t("map_hide_flightplan_route"),
                "no_pilots_found" => t("map_no_pilots_found")
                ,"navigation_waypoint" => t("map_navigation_waypoint")
                ,"navigation_navaid" => t("map_navigation_navaid")
                ,"navigation_airway" => t("map_navigation_airway")
                ,"airway_segments" => t("map_airway_segments")
                ,"airway_paths" => t("map_airway_paths")
                ,"airway_unavailable" => t("map_airway_unavailable")
                ,"navigation_radar" => t("map_navigation_radar")
                ,"fir_region" => t("map_fir_region")
                ,"fir_division" => t("map_fir_division")
                ,"fir_sector" => t("map_fir_sector")
                ,"fir_data_error" => t("map_fir_data_error")
            ],
            JSON_UNESCAPED_UNICODE
        );
        ?>;
    const TARGET_PILOT_ID =
        <?php echo max(0, (int)($_GET['pilot_id'] ?? 0)); ?>;
    const TARGET_FOLLOW =
        <?php echo !empty($_GET['follow']) ? 'true' : 'false'; ?>;
    const TARGET_AIRPORT =
        <?php
        $targetAirport = strtoupper(trim((string)($_GET['airport'] ?? '')));
        echo json_encode(
            preg_match('/^[A-Z0-9][A-Z0-9-]{1,14}$/', $targetAirport)
                ? $targetAirport
                : ''
        );
        ?>;
    const INITIAL_HEATMAP =
        <?php echo !empty($_GET['heatmap']) ? 'true' : 'false'; ?>;
    let initialMapCenter = [51.0, 10.0];
    let initialMapZoom = 5;
    try {
        const savedMapView = JSON.parse(
            localStorage.getItem('vfn_map_last_view') || 'null'
        );
        const savedLatitude = Number(savedMapView?.latitude);
        const savedLongitude = Number(savedMapView?.longitude);
        const savedZoom = Number(savedMapView?.zoom);
        if (
            Number.isFinite(savedLatitude) && savedLatitude >= -90
            && savedLatitude <= 90 && Number.isFinite(savedLongitude)
            && savedLongitude >= -180 && savedLongitude <= 180
            && Number.isFinite(savedZoom) && savedZoom >= 1
            && savedZoom <= 22
        ) {
            initialMapCenter = [savedLatitude, savedLongitude];
            initialMapZoom = savedZoom;
        }
    } catch (error) {
        localStorage.removeItem('vfn_map_last_view');
    }
    const map = L.map(
        'map',
        {
            zoomControl: true
        }
    ).setView(
        initialMapCenter,
        initialMapZoom
    );

    L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            maxZoom: 22,
            minzoom: 1,
            attribution: '&copy; Virtual Flightnetwork'
        }
    ).addTo(map);

    function updateAtcSymbolScale()
    {
        const zoom = map.getZoom();
        const scale = Math.max(0.16, Math.min(1, 0.16 + (zoom - 4) * 0.09));
        map.getContainer().style.setProperty('--atc-symbol-scale', scale.toFixed(2));
    }
    updateAtcSymbolScale();
    map.on('zoomend', updateAtcSymbolScale);
    map.on('moveend', function() {
        const center = map.getCenter();
        try {
            localStorage.setItem('vfn_map_last_view', JSON.stringify({
                latitude: Number(center.lat.toFixed(6)),
                longitude: Number(center.lng.toFixed(6)),
                zoom: map.getZoom()
            }));
        } catch (error) {
            // The map remains usable when browser storage is unavailable.
        }
    });

    const statusBox =
        document.getElementById('statusBox');

    const pilotInfoPanel =
        document.getElementById('pilotInfoPanel');

    const airportTrafficPanel =
        document.getElementById('airportTrafficPanel');
    const navigationPointPanel =
        document.getElementById('navigationPointPanel');
    const atcInfoPanel =
        document.getElementById('atcInfoPanel');

    const pilotMarkers = {};

    const pilotTracks = {};

    const pilotTrackLastIds = {};
    const pilotTrackSegments = {};
    const pilotTrackLastPoints = {};
    let pilotTrackLoadGeneration = 0;

    const airportRouteLines = {};

    const airportMarkers = {};

    const trafficAirportMarkers = {};
    const atcAirportMarkers = {};
    const atisAirportMarkers = {};
    const atcTraconLayers = {};
    const atcFirLayers = {};
    let atcPositionLoadInProgress = false;
    let activeAtcCount = 0;
    let latestAtcPositions = [];
    let latestAtisAirports = [];
    let selectedAtcStationCode = null;

    let airportTrafficData = {};
    const searchedAirports = {};
    let airportSearchResults = [];
    let navigationSearchResults = [];
    let atcSearchResults = [];
    let currentAiracCycle = '';
    let airportSearchTimer = null;
    const airportMetarCache = {};
    const flightRouteEstimateCache = {};
    let flightRouteEstimateGeneration = 0;
    let flightPlanRouteLayer = null;
    let flightPlanRouteWaypointLayer = null;
    let flightPlanRouteVisible = false;
    let flightPlanRouteLoading = false;
    let flightPlanRouteGeneration = 0;
    let flightPlanRouteKey = '';

    let selectedCallsign = null;
    let selectedPilotData = null;
    let followedUserId = TARGET_FOLLOW ? TARGET_PILOT_ID : 0;
    let latestPilots = [];
    const hideInvisiblePilots =
        document.getElementById('hideInvisiblePilots');
    let initialTargetHandled = false;
    let heatmapLayer = null;
    let firBoundaryLayer = null;
    let firBoundaryLoading = null;
    let firDatasetLoading = null;
    let traconDatasetLoading = null;
    let traconAttributionAdded = false;
    const sectorBoundaryCache = {};
    let sectorAttributionAdded = false;

    let selectedAirportCode = null;
    let selectedAirportMarker = null;
    let selectedNavigationMarker = null;
    let selectedRadarLayer = null;
    let selectedRadarIdentifier = '';
    let sectorWaypointLayer = null;
    let sectorWaypointRequest = 0;
    let selectedAirwayLayer = null;

    let selectedAirportTab = 'inbound';

    function clearSectorWaypoints()
    {
        ++sectorWaypointRequest;
        if (sectorWaypointLayer) {
            map.removeLayer(sectorWaypointLayer);
            sectorWaypointLayer = null;
        }
    }

    function resetSectorWaypointOption()
    {
        clearSectorWaypoints();
        selectedRadarIdentifier = '';
        document.getElementById('sectorWaypointsToggle').checked = false;
        document.getElementById('sectorWaypointsOption').hidden = true;
    }

    async function loadSelectedSectorWaypoints()
    {
        clearSectorWaypoints();
        const station = selectedRadarIdentifier;
        const request = sectorWaypointRequest;
        const toggle = document.getElementById('sectorWaypointsToggle');
        if (!station || !toggle.checked) return;
        try {
            const response = await fetch(
                'execute/sector_waypoints.php?v=6&station=' + encodeURIComponent(station),
                {cache: 'no-store'}
            );
            const payload = await response.json();
            if (request !== sectorWaypointRequest || station !== selectedRadarIdentifier
                || !toggle.checked) return;
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'waypoints_unavailable');
            }
            sectorWaypointLayer = L.layerGroup();
            (payload.points || []).forEach(point => {
                const latitude = Number(point.latitude);
                const longitude = Number(point.longitude);
                if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
                L.marker([latitude, longitude], {
                    interactive: false,
                    icon: L.divIcon({
                        className: '',
                        html: '<div class="sector-waypoint-label">'
                            + escapeHtml(point.identifier || '') + '</div>',
                        iconSize: [100, 14],
                        iconAnchor: [4, 7]
                    })
                }).addTo(sectorWaypointLayer);
            });
            sectorWaypointLayer.addTo(map);
        } catch (error) {
            console.warn('Sector waypoints could not be loaded.', error);
            toggle.checked = false;
            clearSectorWaypoints();
        }
    }

    function escapeHtml(value)
    {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function firBoundaryStyle()
    {
        return {
            color: '#37a5ff',
            weight: 1.6,
            opacity: 0.85,
            fillColor: '#168cff',
            fillOpacity: 0.045
        };
    }

    function featureExtentArea(feature)
    {
        let minLatitude = 90;
        let maxLatitude = -90;
        let minLongitude = 180;
        let maxLongitude = -180;

        function inspectCoordinates(value)
        {
            if (
                Array.isArray(value)
                && value.length >= 2
                && Number.isFinite(Number(value[0]))
                && Number.isFinite(Number(value[1]))
            ) {
                const longitude = Number(value[0]);
                const latitude = Number(value[1]);
                minLatitude = Math.min(minLatitude, latitude);
                maxLatitude = Math.max(maxLatitude, latitude);
                minLongitude = Math.min(minLongitude, longitude);
                maxLongitude = Math.max(maxLongitude, longitude);
                return;
            }
            if (Array.isArray(value)) value.forEach(inspectCoordinates);
        }

        inspectCoordinates(feature?.geometry?.coordinates);
        if (minLatitude > maxLatitude || minLongitude > maxLongitude) return 0;
        return (maxLatitude - minLatitude) * (maxLongitude - minLongitude);
    }

    function loadFirDataset()
    {
        if (!firDatasetLoading) {
            firDatasetLoading = Promise.all([
                fetch('execute/fir_boundaries.php'),
                fetch('execute/fir_names.php')
            ]).then(responses => {
                if (!responses[0].ok) throw new Error('FIR data unavailable');
                return Promise.all([
                    responses[0].json(),
                    responses[1].ok
                        ? responses[1].json()
                        : Promise.resolve({names: {}, types: {}})
                ]);
            }).then(results => ({
                data: results[0],
                names: results[1]?.names || {},
                types: results[1]?.types || {}
            })).catch(error => {
                firDatasetLoading = null;
                throw error;
            });
        }
        return firDatasetLoading;
    }

    function loadTraconDataset()
    {
        if (!traconDatasetLoading) {
            traconDatasetLoading = fetch('execute/tracon_boundaries.php')
                .then(response => {
                    if (!response.ok) throw new Error('TRACON data unavailable');
                    return response.json();
                }).then(data => {
                    if (!traconAttributionAdded) {
                        map.attributionControl.addAttribution(
                            '<a href="https://github.com/vatsimnetwork/'
                            + 'simaware-tracon-project" target="_blank"'
                            + ' rel="noopener">SimAware TRACON data</a>'
                            + ' (CC BY-SA 4.0)'
                        );
                        traconAttributionAdded = true;
                    }
                    return data;
                }).catch(error => {
                    traconDatasetLoading = null;
                    throw error;
                });
        }
        return traconDatasetLoading;
    }

    function loadSectorBoundary(station)
    {
        const key = String(station || '').toUpperCase().replace(/-/g, '_');
        if (!key) return Promise.resolve(null);
        if (!sectorBoundaryCache[key]) {
            sectorBoundaryCache[key] = fetch(
                'execute/sector_boundaries.php?station=' + encodeURIComponent(key)
            ).then(response => {
                if (response.status === 404) return null;
                if (!response.ok) throw new Error('Sector boundary unavailable');
                return response.json();
            }).then(data => {
                if (data && !sectorAttributionAdded) {
                    map.attributionControl.addAttribution(
                        '<a href="https://github.com/lennycolton/'
                        + 'vatglasses-data" target="_blank" rel="noopener">'
                        + 'VATGlasses sector data</a> (CC BY-NC-SA 4.0)'
                    );
                    sectorAttributionAdded = true;
                }
                return data;
            }).catch(error => {
                delete sectorBoundaryCache[key];
                console.error(error);
                return null;
            });
        }
        return sectorBoundaryCache[key];
    }

    function displayedAtcCallsign(position)
    {
        const callsign = String(position?.callsign || '');
        return callsign + (position?.is_gca ? ' (GCA)' : '');
    }

    function atcPositionTooltip(position)
    {
        return '<div class="atc-map-tooltip"><strong>'
            + escapeHtml(displayedAtcCallsign(position)) + '</strong><br>'
            + escapeHtml(position.controller_name || '')
            + (position.frequency ? '<br>' + escapeHtml(position.frequency) + ' MHz' : '')
            + '</div>';
    }

    function renderAtcInfoCards(positions, clickable = false, includeAtis = true)
    {
        if (!positions.length) {
            return '<div class="airport-traffic-empty">'
                + escapeHtml(MAP_TEXT.atc_none) + '</div>';
        }
        const positionCards = positions.map(position => {
            const trainer = Number(position.is_trainer || 0) === 1;
            const tag = clickable ? 'button' : 'div';
            const attributes = clickable
                ? ' type="button" data-airport-atc-station="'
                    + escapeHtml(position.station_code || '')
                    + '" onclick="event.stopPropagation(); openAirportAtcDetails(this.dataset.airportAtcStation)"'
                : '';
            return '<' + tag + ' class="atc-info-card"' + attributes + '>'
            + '<span class="atc-position-code">'
            + escapeHtml(trainer ? MAP_TEXT.atc_trainer : (position.position_code || '')) + '</span>'
            + '<strong>' + escapeHtml(displayedAtcCallsign(position)) + '</strong>'
            + '<div class="atc-info-meta">'
            + escapeHtml(MAP_TEXT.atc_controller) + ': '
            + escapeHtml(position.controller_name || '–')
            + (!trainer && position.frequency ? '<br>' + escapeHtml(MAP_TEXT.atc_frequency)
                + ': ' + escapeHtml(position.frequency) + ' MHz' : '')
            + '</div></' + tag + '>';
        }).join('');
        const airportPosition = positions.find(position =>
            Number(position.is_trainer || 0) !== 1
            && String(position.position_code || '').toUpperCase() !== 'CTR');
        const atisFrequency = String(airportPosition?.atis_frequency || '');
        if (!includeAtis || !airportPosition || !atisFrequency) return positionCards;
        const station = String(airportPosition.station_code || '').toUpperCase();
        const tag = clickable ? 'button' : 'div';
        const attributes = clickable
            ? ' type="button" data-airport-atc-station="' + escapeHtml(station)
                + '" onclick="event.stopPropagation(); openAirportAtcDetails(this.dataset.airportAtcStation)"'
            : '';
        return positionCards + '<' + tag + ' class="atc-info-card"' + attributes + '>'
            + '<span class="atc-position-code">ATIS</span>'
            + '<strong>' + escapeHtml(station + '_ATIS') + '</strong>'
            + '<div class="atc-info-meta">'
            + escapeHtml(MAP_TEXT.atc_controller) + ': '
            + escapeHtml(airportPosition.controller_name || '–')
            + '<br>' + escapeHtml(MAP_TEXT.atc_frequency) + ': '
            + escapeHtml(atisFrequency) + ' MHz'
            + '<br>' + escapeHtml(MAP_TEXT.atis_information) + ': '
            + escapeHtml(airportPosition.atis_info_letter || '–')
            + (airportPosition.atis_active_runway
                ? '<br>' + escapeHtml(MAP_TEXT.atis_runway) + ': '
                    + escapeHtml(airportPosition.atis_active_runway) : '')
            + '<br>' + escapeHtml(
                Number(airportPosition.atis_active)
                    ? MAP_TEXT.atis_active : MAP_TEXT.atis_preparing
            )
            + '</div></' + tag + '>';
    }

    function closeAtcInfoPanel()
    {
        selectedAtcStationCode = null;
        atcInfoPanel.classList.remove('open');
    }

    function openAtcInfoPanel(positions, identifier, name, kind)
    {
        closeAirportTrafficPanel();
        closeNavigationPointPanel();
        pilotInfoPanel.classList.remove('open');
        document.getElementById('atcInfoIdentifier').innerText = identifier || 'ATC';
        selectedAtcStationCode = String(identifier || '').toUpperCase();
        document.getElementById('atcInfoName').innerText = name || MAP_TEXT.atc_online;
        document.getElementById('atcInfoKind').innerText =
            String(identifier || 'ATC') + ' ' + MAP_TEXT.atc_information;
        document.getElementById('atcInfoList').innerHTML = renderAtcInfoCards(positions);
        document.getElementById('atcAirportBackButton').style.display =
            positions.some(position =>
                String(position.position_code || '').toUpperCase() !== 'CTR')
                ? 'block' : 'none';
        atcInfoPanel.classList.add('open');
    }

    async function openAtcAirportDetails()
    {
        const station = selectedAtcStationCode;
        if (!station) return;
        if (!airportTrafficData[station]?.info) {
            try {
                const response = await fetch(
                    'execute/airport_lookup.php?q=' + encodeURIComponent(station)
                );
                const data = await response.json();
                const airport = (data.airports || []).find(entry =>
                    String(entry.code || '').toUpperCase() === station);
                if (!airport) return;
                searchedAirports[station] = airport;
                ensureAirportTrafficBucket(airportTrafficData, station, airport);
                updateAirportTrafficMarkers();
            } catch (error) {
                console.error(error);
                return;
            }
        }
        const airport = airportTrafficData[station]?.info;
        const coordinates = airport ? getAirportLatLng(airport) : null;
        if (coordinates) map.setView(coordinates, 11, {animate:true});
        openAirportTrafficPanel(station);
    }

    function refreshOpenAtcInfoPanel()
    {
        if (!selectedAtcStationCode || !atcInfoPanel.classList.contains('open')) return;
        const positions = latestAtcPositions.filter(position =>
            String(position.station_code || '').toUpperCase() === selectedAtcStationCode);
        if (!positions.length) {
            closeAtcInfoPanel();
            return;
        }
        document.getElementById('atcInfoList').innerHTML = renderAtcInfoCards(positions);
    }

    function pointInSectorRing(longitude, latitude, ring)
    {
        let inside = false;
        for (let index = 0, previous = ring.length - 1; index < ring.length; previous = index++) {
            const currentPoint = ring[index] || [];
            const previousPoint = ring[previous] || [];
            const currentX = Number(currentPoint[0]);
            const currentY = Number(currentPoint[1]);
            const previousX = Number(previousPoint[0]);
            const previousY = Number(previousPoint[1]);
            const crosses = ((currentY > latitude) !== (previousY > latitude))
                && (longitude < (previousX - currentX) * (latitude - currentY)
                    / ((previousY - currentY) || Number.EPSILON) + currentX);
            if (crosses) inside = !inside;
        }
        return inside;
    }

    function pointInSectorPolygon(longitude, latitude, polygon)
    {
        if (!Array.isArray(polygon) || !polygon.length
            || !pointInSectorRing(longitude, latitude, polygon[0])) return false;
        return !polygon.slice(1).some(hole =>
            pointInSectorRing(longitude, latitude, hole));
    }

    function pointInSectorFeature(longitude, latitude, feature)
    {
        if (!feature) return false;
        if (feature.type === 'FeatureCollection') {
            return (feature.features || []).some(item =>
                pointInSectorFeature(longitude, latitude, item));
        }
        if (feature.type === 'Feature') {
            return pointInSectorFeature(longitude, latitude, feature.geometry);
        }
        if (feature.type === 'Polygon') {
            return pointInSectorPolygon(longitude, latitude, feature.coordinates || []);
        }
        if (feature.type === 'MultiPolygon') {
            return (feature.coordinates || []).some(polygon =>
                pointInSectorPolygon(longitude, latitude, polygon));
        }
        return false;
    }

    function radarPositionsCoveringAirport(airport)
    {
        const coordinates = getAirportLatLng(airport);
        if (!coordinates) return [];
        const latitude = coordinates[0];
        const longitude = coordinates[1];
        const covered = [];
        [...Object.values(atcTraconLayers), ...Object.values(atcFirLayers)]
            .forEach(layer => {
                if (!pointInSectorFeature(longitude, latitude, layer.__vfnCoverageFeature)) return;
                (layer.__vfnCoveragePositions || []).forEach(position => {
                    if (!covered.some(item => item.callsign === position.callsign)) {
                        covered.push(position);
                    }
                });
            });
        return covered;
    }

    function updateSelectedAirportAtc()
    {
        const list = document.getElementById('airportAtcList');
        if (!list || !selectedAirportCode) return;
        const positions = latestAtcPositions.filter(position =>
            String(position.station_code || '').toUpperCase() === selectedAirportCode
            && String(position.position_code || '').toUpperCase() !== 'CTR');
        const atis = latestAtisAirports.find(entry =>
            String(entry.airport_icao || '').toUpperCase() === selectedAirportCode);
        const controllers = Array.isArray(atis?.controllers) ? atis.controllers : [];
        controllers.forEach(controller => {
            if (!positions.some(position => position.callsign === controller.callsign)) {
                positions.push(controller);
            }
        });
        const airport = airportTrafficData[selectedAirportCode]?.info
            || searchedAirports[selectedAirportCode];
        radarPositionsCoveringAirport(airport).forEach(controller => {
            if (!positions.some(position => position.callsign === controller.callsign)) {
                positions.push(controller);
            }
        });
        let html = positions.length ? renderAtcInfoCards(positions, true, false) : '';
        if (atis) {
            html += '<div class="atc-info-card"><span class="atc-position-code">ATIS</span>'
                + '<strong>' + escapeHtml(selectedAirportCode + '_ATIS') + '</strong>'
                + '<div class="atc-info-meta">' + escapeHtml(MAP_TEXT.atc_frequency) + ': '
                + escapeHtml(atis.frequency || '–') + ' MHz<br>'
                + escapeHtml(MAP_TEXT.atis_information) + ': '
                + escapeHtml(atis.info_letter || '–')
                + (atis.active_runway ? '<br>' + escapeHtml(MAP_TEXT.atis_runway) + ': '
                    + escapeHtml(atis.active_runway) : '')
                + '<br>' + escapeHtml(Number(atis.is_active ?? atis.active)
                    ? MAP_TEXT.atis_active : MAP_TEXT.atis_unavailable) + '</div></div>';
        }
        list.innerHTML = html || '<div class="airport-traffic-empty">'
            + escapeHtml(MAP_TEXT.atc_none) + '</div>';
    }

    async function openAtisAirport(icao)
    {
        const code = String(icao || '').toUpperCase();
        if (!airportTrafficData[code]?.info) {
            try {
                const response = await fetch('execute/airport_lookup.php?q=' + encodeURIComponent(code));
                const data = await response.json();
                const airport = (data.airports || []).find(entry =>
                    String(entry.code || entry.ident || '').toUpperCase() === code);
                if (!airport) return;
                searchedAirports[code] = airport;
                ensureAirportTrafficBucket(airportTrafficData, code, airport);
                updateAirportTrafficMarkers();
            } catch (error) { console.error(error); return; }
        }
        openAirportTrafficPanel(code);
    }

    function renderAtisAirportMarkers(airports)
    {
        if (!map.getPane('atisAirportPane')) {
            const pane = map.createPane('atisAirportPane');
            pane.style.zIndex = '770'; pane.style.pointerEvents = 'auto';
        }
        const active = {};
        airports.forEach(atis => {
            const code = String(atis.airport_icao || '').toUpperCase();
            const latitude = Number(atis.latitude), longitude = Number(atis.longitude);
            if (!code || !Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
            const tooltip = '<strong>' + escapeHtml(code + ' ATIS') + '</strong><br>'
                + escapeHtml(atis.frequency || '') + ' MHz · '
                + escapeHtml(atis.info_letter || '–') + '<br>'
                + escapeHtml(Number(atis.is_active ?? atis.active)
                    ? MAP_TEXT.atis_active : MAP_TEXT.atis_unavailable);
            const radarCovered = Array.isArray(atis.controllers) && atis.controllers.length > 0;
            if (!radarCovered) return;
            active[code] = true;
            if (!atisAirportMarkers[code]) {
                atisAirportMarkers[code] = L.circleMarker([latitude, longitude], {
                    pane:'atisAirportPane', radius:4,
                    color:'#ffffff', weight:2,
                    fillColor:Number(atis.is_active ?? atis.active) ? '#20cfff' : '#102b3a',
                    fillOpacity:1
                }).addTo(map).on('click', () => openAtisAirport(code));
            } else {
                atisAirportMarkers[code].setLatLng([latitude, longitude]);
                atisAirportMarkers[code].setRadius(4);
                atisAirportMarkers[code].setStyle({
                    color:'#ffffff',
                    fillColor:Number(atis.is_active ?? atis.active) ? '#20cfff' : '#102b3a',
                    fillOpacity:1
                });
            }
            atisAirportMarkers[code].bindTooltip(tooltip, {direction:'top'});
        });
        Object.keys(atisAirportMarkers).forEach(code => {
            if (active[code]) return;
            map.removeLayer(atisAirportMarkers[code]); delete atisAirportMarkers[code];
        });
    }

    function openAirportAtcDetails(stationCode)
    {
        const station = String(stationCode || '').toUpperCase();
        const positions = latestAtcPositions.filter(position =>
            String(position.station_code || '').toUpperCase() === station);
        if (!positions.length) return;
        openAtcInfoPanel(
            positions,
            station,
            positions[0].airport_name || station,
            MAP_TEXT.atc_positions
        );
    }

    function renderAtcAirportPositions(positions)
    {
        if (!map.getPane('atcAirportPane')) {
            const pane = map.createPane('atcAirportPane');
            pane.style.zIndex = '760';
            pane.style.pointerEvents = 'auto';
        }
        const grouped = {};
        positions.forEach(position => {
            if (!Number.isFinite(Number(position.latitude))
                || !Number.isFinite(Number(position.longitude))) return;
            const key = String(position.station_code || '').toUpperCase();
            if (!grouped[key]) grouped[key] = [];
            grouped[key].push(position);
        });
        Object.keys(atcAirportMarkers).forEach(key => {
            if (grouped[key]) return;
            map.removeLayer(atcAirportMarkers[key]);
            delete atcAirportMarkers[key];
        });
        Object.keys(grouped).forEach(key => {
            const items = grouped[key];
            const symbols = [];
            items.filter(item => Number(item.is_trainer || 0) !== 1).forEach(item => {
                const symbol = String(item.position_code || '').toLowerCase();
                if (!symbols.includes(symbol)) symbols.push(symbol);
            });
            const symbolOrder = {dep:1,app:2,twr:3,gnd:4,del:5,info:6};
            symbols.sort((left,right) => (symbolOrder[left]||9)-(symbolOrder[right]||9));
            if (!symbols.length) {
                if (atcAirportMarkers[key]) {
                    map.removeLayer(atcAirportMarkers[key]);
                    delete atcAirportMarkers[key];
                }
                return;
            }
            const html = '<div class="atc-airport-stack">'
                + symbols.map(symbol => '<span class="atc-position-symbol '
                    + escapeHtml(symbol) + '"></span>').join('')
                + '</div>';
            const tooltip = items.map(atcPositionTooltip).join('<hr>');
            const latLng = [Number(items[0].latitude), Number(items[0].longitude)];
            const icon = L.divIcon({className:'',html:html,iconSize:[110,110],iconAnchor:[55,55]});
            if (!atcAirportMarkers[key]) {
                atcAirportMarkers[key] = L.marker(latLng,{
                    icon:icon,
                    pane:'atcAirportPane',
                    zIndexOffset:5000,
                    riseOnHover:true
                })
                    .bindTooltip(tooltip,{direction:'top',offset:[0,-18]})
                    .addTo(map);
            } else {
                atcAirportMarkers[key].setLatLng(latLng).setIcon(icon)
                    .setTooltipContent(tooltip);
            }
            atcAirportMarkers[key].off('click').on('click',event => {
                if (event?.originalEvent) L.DomEvent.stopPropagation(event.originalEvent);
                openAtcInfoPanel(
                    items, key, items[0].airport_name || key, MAP_TEXT.atc_positions
                );
            });
        });
    }

    async function renderAtcTraconPositions(positions)
    {
        const approachPositions = positions.filter(position =>
            Number(position.is_trainer || 0) !== 1
            && ['APP','DEP'].includes(String(position.position_code || '').toUpperCase()));
        if (!approachPositions.length) {
            Object.keys(atcTraconLayers).forEach(key => {
                map.removeLayer(atcTraconLayers[key]);
                delete atcTraconLayers[key];
            });
            return;
        }
        let dataset;
        try {
            dataset = await loadTraconDataset();
        } catch (error) {
            console.error(error);
            return;
        }
        if (!map.getPane('atcActiveTraconPane')) {
            const pane = map.createPane('atcActiveTraconPane');
            pane.style.zIndex = '475';
            pane.style.pointerEvents = 'auto';
        }
        const matched = {};
        approachPositions.forEach(position => {
            const station = String(position.station_code || '').toUpperCase();
            const positionCode = String(position.position_code || '').toUpperCase();
            const feature = (dataset.features || []).find(candidate => {
                const properties = candidate?.properties || {};
                const prefixes = Array.isArray(properties.prefix)
                    ? properties.prefix : [properties.prefix];
                const suffix = String(properties.suffix || 'APP').toUpperCase();
                return prefixes.some(prefix => String(prefix || '').toUpperCase() === station)
                    && suffix === positionCode;
            });
            if (!feature) return;
            const key = String(feature.properties?.id || station).toUpperCase()
                + ':' + positionCode;
            if (!matched[key]) matched[key] = {feature:feature,positions:[]};
            matched[key].positions.push(position);
        });
        Object.keys(atcTraconLayers).forEach(key => {
            if (matched[key]) return;
            map.removeLayer(atcTraconLayers[key]);
            delete atcTraconLayers[key];
        });
        Object.keys(matched).forEach(key => {
            const entry = matched[key];
            const tooltip = entry.positions.map(atcPositionTooltip).join('<hr>');
            const isDeparture = key.endsWith(':DEP');
            const style = {
                color: isDeparture ? '#8d63ff' : '#3449a8',
                weight: 3,
                opacity: .9,
                fillColor: isDeparture ? '#9470ff' : '#536ac7',
                fillOpacity: .13,
                dashArray: isDeparture ? '8 5' : null
            };
            if (!atcTraconLayers[key]) {
                atcTraconLayers[key] = L.geoJSON(entry.feature,{
                    pane:'atcActiveTraconPane', style:style
                }).bindTooltip(tooltip,{sticky:true,className:'atc-fir-label'}).addTo(map);
            } else {
                atcTraconLayers[key].setStyle(style).setTooltipContent(tooltip);
            }
            atcTraconLayers[key].__vfnCoverageFeature = entry.feature;
            atcTraconLayers[key].__vfnCoveragePositions = entry.positions;
            atcTraconLayers[key].off('click').on('click',() => {
                const first = entry.positions[0] || {};
                openAtcInfoPanel(
                    entry.positions,
                    String(first.station_code || ''),
                    first.airport_name || first.station_code || key,
                    MAP_TEXT.atc_positions
                );
            });
        });
    }

    async function renderAtcFirPositions(positions)
    {
        const grouped = {};
        positions.forEach(position => {
            if (Number(position.is_trainer || 0) === 1) return;
            const key = String(position.station_code || '').toUpperCase();
            if (!grouped[key]) grouped[key] = [];
            grouped[key].push(position);
        });
        Object.keys(atcFirLayers).forEach(key => {
            if (grouped[key]) return;
            map.removeLayer(atcFirLayers[key]);
            delete atcFirLayers[key];
        });
        if (!Object.keys(grouped).length) return;
        const dataset = await loadFirDataset();
        if (!map.getPane('atcActiveFirPane')) {
            const pane = map.createPane('atcActiveFirPane');
            pane.style.zIndex = '485';
            pane.style.pointerEvents = 'auto';
        }
        const exactSectorResults = await Promise.all(Object.keys(grouped).map(
            key => loadSectorBoundary(key)
        ));
        const activeSectors = Object.keys(grouped).map((key, keyIndex) => {
            const fallbackCode = String(
                grouped[key][0]?.radar_boundary_code || ''
            ).toUpperCase();
            const detailed = exactSectorResults[keyIndex];
            let exact = Boolean(detailed?.geojson?.features?.length);
            let feature = exact ? detailed.geojson : null;
            if (!feature) {
                feature = (dataset.data.features || []).find(item =>
                    String(item?.properties?.id || '').toUpperCase() === key);
                exact = Boolean(feature);
            }
            if (!feature && fallbackCode) {
                exact = false;
                feature = (dataset.data.features || []).find(item =>
                    String(item?.properties?.id || '').toUpperCase() === fallbackCode);
            }
            const featureAreas = feature?.type === 'FeatureCollection'
                ? feature.features.map(featureExtentArea)
                : [featureExtentArea(feature)];
            return feature ? {
                key:key, feature:feature, exact:exact,
                area:Math.max(0, ...featureAreas)
            } : null;
        }).filter(Boolean).sort((left,right) => right.area-left.area);
        activeSectors.forEach((sector,index) => {
            const key = sector.key;
            const feature = sector.feature;
            const tooltip = grouped[key].map(atcPositionTooltip).join('<hr>')
                + (sector.exact ? '' : '<hr><em>Approximate FIR fallback – exact sector geometry unavailable</em>');
            const isSubSector = activeSectors.some(other =>
                other.area > sector.area
                && (key.indexOf(other.key + '-') === 0
                    || key.indexOf(other.key + '_') === 0));
            const sectorStyle = {
                color: sector.exact ? (isSubSector ? '#ffad32' : '#ff8318') : '#a97945',
                weight: isSubSector ? 6 : 5,
                opacity: 1,
                fillColor: isSubSector ? '#ffb13b' : '#ff9a2e',
                fillOpacity: sector.exact ? (isSubSector ? .28 : .12) : .035,
                dashArray: sector.exact ? (isSubSector ? '7 5' : '13 7') : '3 9'
            };
            if (!atcFirLayers[key]) {
                atcFirLayers[key] = L.geoJSON(feature,{
                    pane:'atcActiveFirPane',
                    style:sectorStyle
                }).bindTooltip(tooltip,{sticky:true,className:'atc-fir-label'}).addTo(map);
            } else {
                atcFirLayers[key].setStyle(sectorStyle).setTooltipContent(tooltip);
            }
            atcFirLayers[key].__vfnCoverageFeature = feature;
            atcFirLayers[key].__vfnCoveragePositions = grouped[key];
            atcFirLayers[key].off('click').on('click',() => openAtcInfoPanel(
                grouped[key], key, key, MAP_TEXT.atc_radar_sector
            ));
            // Rendering from the largest parent to the smallest child ensures
            // that a staffed sub-sector owns hover/click interaction inside
            // its boundaries instead of the parent FIR swallowing it.
            atcFirLayers[key].bringToFront();
        });
    }

    async function loadAtcPositions()
    {
        if (atcPositionLoadInProgress) return;
        atcPositionLoadInProgress = true;
        try {
            const response = await fetch('/execute/get_pilots.php?time=' + Date.now() + '&protection=<?php echo rawurlencode((string)$getPilotsProtection); ?>');
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'ATC unavailable');
            const positions = Array.isArray(data.atcs?.items) ? data.atcs.items : [];
            activeAtcCount = Number(data.atcs?.count ?? positions.length ?? 0);
            latestAtcPositions = positions;
            latestAtisAirports = Array.isArray(data.atcs?.atis_airports) ? data.atcs.atis_airports : [];
            renderAtisAirportMarkers(latestAtisAirports);
            refreshOpenAtcInfoPanel();
            const airportPositions = positions.filter(position =>
                !['CTR'].includes(String(position.position_code || '').toUpperCase()));
            const radarPositions = positions.filter(position =>
                String(position.position_code || '').toUpperCase() === 'CTR');
            renderAtcAirportPositions(airportPositions);
            await renderAtcTraconPositions(airportPositions);
            await renderAtcFirPositions(radarPositions);
            updateSelectedAirportAtc();
        } catch (error) {
            console.error(error);
        } finally {
            atcPositionLoadInProgress = false;
        }
    }

    async function loadFirBoundaries()
    {
        const toggle = document.getElementById('firBoundaryToggle');
        localStorage.setItem(
            'vfn_map_show_fir_boundaries',
            toggle.checked ? '1' : '0'
        );

        if (!toggle.checked) {
            if (firBoundaryLayer && map.hasLayer(firBoundaryLayer)) {
                map.removeLayer(firBoundaryLayer);
            }
            return;
        }

        if (firBoundaryLayer) {
            firBoundaryLayer.addTo(map);
            return;
        }

        if (!firBoundaryLoading) {
            firBoundaryLoading = loadFirDataset()
                .then(result => {
                    const data = result.data;
                    const firNames = result.names;
                    const features = Array.isArray(data.features)
                        ? data.features.slice()
                        : [];
                    const identifiers = features.map(feature =>
                        String(feature?.properties?.id || '').toUpperCase()
                    );
                    const visibleFeatures = features
                        .filter(function(feature) {
                            const identifier = String(
                                feature?.properties?.id || ''
                            ).toUpperCase();
                            if (!/^[A-Z]{2}XX$/.test(identifier)) return true;
                            const countryPrefix = identifier.substring(0, 2);
                            return !identifiers.some(other =>
                                other !== identifier
                                && other.startsWith(countryPrefix)
                                && !/^[A-Z]{2}XX$/.test(other)
                            );
                        })
                        .sort(function(left, right) {
                            const leftId = String(left?.properties?.id || '');
                            const rightId = String(right?.properties?.id || '');
                            const areaDifference =
                                featureExtentArea(right)
                                - featureExtentArea(left);
                            if (Math.abs(areaDifference) > 0.000001) {
                                return areaDifference;
                            }
                            return Number(leftId.includes('-'))
                                - Number(rightId.includes('-'));
                        });
                    firBoundaryLayer = L.geoJSON(
                        {
                            type: 'FeatureCollection',
                            features: visibleFeatures
                        },
                        {
                        style: firBoundaryStyle,
                        onEachFeature: function(feature, layer) {
                            const properties = feature.properties || {};
                            const identifier = properties.id || '----';
                            const region = properties.region || '----';
                            const name =
                                firNames[String(identifier).toUpperCase()]
                                || '';
                            const division = properties.division || '----';
                            layer.bindTooltip(
                                name ? identifier + ' · ' + name : identifier,
                                {
                                sticky: true,
                                direction: 'top'
                                }
                            );
                            layer.bindPopup(
                                '<strong>' + escapeHtml(identifier) + '</strong>'
                                + (name
                                    ? '<br>' + escapeHtml(MAP_TEXT.fir_sector)
                                        + ': ' + escapeHtml(name)
                                    : '')
                                + '<br>' + escapeHtml(MAP_TEXT.fir_region)
                                + ': ' + escapeHtml(region)
                                + '<br>' + escapeHtml(MAP_TEXT.fir_division)
                                + ': ' + escapeHtml(division)
                            );
                            layer.on('click', function() {
                                openNavigationPointPanel({
                                    kind: 'radar',
                                    identifier: String(identifier),
                                    name: name || String(identifier),
                                    type: result.types[
                                        String(identifier).toUpperCase()
                                    ] || 'FIR/UIR',
                                    region: [region, division]
                                        .filter(Boolean).join(' / '),
                                    feature: feature,
                                    preserveView: true
                                });
                            });
                        }
                        }
                    );
                    map.attributionControl.addAttribution(
                        '<a href="https://github.com/vatsimnetwork/'
                        + 'vatspy-data-project" target="_blank"'
                        + ' rel="noopener">VATSpy FIR data</a>'
                        + ' (CC BY-SA 4.0)'
                    );
                    return firBoundaryLayer;
                })
                .finally(() => {
                    firBoundaryLoading = null;
                });
        }

        try {
            const layer = await firBoundaryLoading;
            if (toggle.checked && !map.hasLayer(layer)) layer.addTo(map);
        } catch (error) {
            toggle.checked = false;
            localStorage.setItem('vfn_map_show_fir_boundaries', '0');
            console.error(MAP_TEXT.fir_data_error, error);
            alert(MAP_TEXT.fir_data_error);
        }
    }

    function buildRatingImage(rating)
    {
        if (
            !rating ||
            !rating.image
        ) {
            return '';
        }

        const code =
            escapeHtml(rating.code || '');

        const name =
            escapeHtml(rating.name || '');

        const image =
            escapeHtml(rating.image || '');

        const title =
            code !== ''
            ? code + ' - ' + name
            : name;

        return `
            <img
                src="${image}"
                alt="${title}"
                title="${title}">
        `;
    }

    function buildRatingsHtml(pilot)
    {
        if (
            !SHOW_RATINGS ||
            !pilot ||
            !pilot.ratings
        ) {
            return '';
        }

        let html = '';

        html +=
            buildRatingImage(
                pilot.ratings.pilot
            );

        html +=
            buildRatingImage(
                pilot.ratings.atc
            );

        if (pilot.ratings.special) {
            html +=
                buildRatingImage(
                    pilot.ratings.special
                );
        }

        if (html.trim() === '') {
            return '<div class="rating-empty">----</div>';
        }

        return html;
    }

    function updatePanelAccessVisibility()
    {
        document
            .querySelectorAll('.member-only')
            .forEach(function(element)
            {
                element.style.display =
                    IS_WEB_LOGGED_IN
                    ? 'block'
                    : 'none';
            });
    }

    function getAircraftIcon(category)
    {
        switch(category)
        {
            case 'small':
                return 'images/icons/plane_small.png';

            case 'medium':
                return 'images/icons/plane_medium.png';

            case 'large':
                return 'images/icons/plane_large.png';

            case 'super':
                return 'images/icons/plane_super.png';

            case 'helicopter':
                return 'images/icons/helicopter.png';

            case 'military':
                return 'images/icons/military.png';

            case 'drone':
                return 'images/icons/drone.png';

            case 'balloon':
                return 'images/icons/balloon.png';

            case 'groundvehicle':
                return 'images/icons/groundvehicle.png';

            default:
                return 'images/icons/unknown.png';
        }
    }

    function getAircraftIconSize(category)
    {
        switch(category)
        {
            case 'small':
                return 32;

            case 'medium':
                return 38;

            case 'large':
                return 50;

            case 'super':
                return 58;

            case 'helicopter':
                return 36;

            case 'military':
                return 42;

            case 'drone':
                return 24;

            case 'balloon':
                return 40;

            case 'groundvehicle':
                return 28;

            default:
                return 32;
        }
    }

    function createPlaneIcon(category, heading)
    {
        category =
            String(category || 'unknown').toLowerCase();

        const iconPath =
            getAircraftIcon(category);

        const size =
            getAircraftIconSize(category);

        return L.divIcon({
            className: '',

            html: `
                <img
                    src="${iconPath}"
                    style="
                        width: ${size}px;
                        height: ${size}px;
                        transform: rotate(${heading}deg);
                        transform-origin: center center;
                        user-select: none;
                        pointer-events: none;
                    "
                    draggable="false"
                >
            `,

            iconSize: [
                size,
                size
            ],

            iconAnchor: [
                size / 2,
                size / 2
            ]
        });
    }

    function getFlightplan(pilot)
    {
        return pilot.flightplan || {};
    }

    function formatFlightRules(value)
    {
        value =
            String(value || '')
                .trim()
                .toUpperCase();

        switch(value)
        {
            case 'I':
                return 'IFR';

            case 'V':
                return 'VFR';

            case 'Y':
                return 'IFR -> VFR';

            case 'Z':
                return 'VFR -> IFR';

            default:
                return value || '----';
        }
    }

    function formatFlightType(value)
    {
        value =
            String(value || '')
                .trim()
                .toUpperCase();

        switch(value)
        {
            case 'S':
                return 'Scheduled Airline';

            case 'N':
                return 'Non-Scheduled';

            case 'G':
                return 'General Aviation';

            case 'M':
                return 'Military';

            case 'X':
                return 'Other';

            default:
                return value || '----';
        }
    }

    function getSquawkCode(pilot)
    {
        return String(
            pilot.transponder || '0000'
        ).trim();
    }

    function isEmergencySquawk(code)
    {
        code =
            String(code || '').trim();

        return (
            code === '7500' ||
            code === '7600' ||
            code === '7700'
        );
    }

    function getSquawkEmergencyText(code)
    {
        code =
            String(code || '').trim();

        switch(code)
        {
            case '7500':
                return '7500 HIJACK';

            case '7600':
                return '7600 RADIO';

            case '7700':
                return '7700 EMERGENCY';

            default:
                return code || '0000';
        }
    }

    function getAirportCode(value)
    {
        const code =
            String(value || 'ZZZZ')
                .trim()
                .toUpperCase();

        if (code === '')
        {
            return 'ZZZZ';
        }

        return code;
    }

    function getAirportName(info)
    {
        if (!info)
        {
            return MAP_TEXT.no_airport || 'Kein Flughafen';
        }

        return info.name || MAP_TEXT.unknown_airport || 'Unbekannter Flughafen';
    }

    function getAirportLatLng(info)
    {
        if (!info)
        {
            return null;
        }

        const lat =
            Number(info.latitude);

        const lon =
            Number(info.longitude);

        if (
            isNaN(lat) ||
            isNaN(lon)
        )
        {
            return null;
        }

        return [
            lat,
            lon
        ];
    }

    function updateMapUrl(pilot)
    {
        const url = new URL(window.location.href);
        if (pilot && Number(pilot.user_id) > 0) {
            url.searchParams.set('pilot_id', String(pilot.user_id));
            if (followedUserId === Number(pilot.user_id)) {
                url.searchParams.set('follow', '1');
            } else {
                url.searchParams.delete('follow');
            }
        } else {
            url.searchParams.delete('pilot_id');
            url.searchParams.delete('follow');
        }
        window.history.replaceState({}, '', url);
    }

    function updateFollowButton()
    {
        const button = document.getElementById('followPilotButton');
        if (!button) return;
        const following = selectedPilotData
            && followedUserId === Number(selectedPilotData.user_id);
        button.classList.toggle('following', Boolean(following));
        button.textContent = following
            ? MAP_TEXT.stop_following
            : MAP_TEXT.follow_pilot;
    }

    function centerOnPilot(pilot, animated)
    {
        const lat = Number(pilot.latitude);
        const lon = Number(pilot.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
        if (map.getZoom() < 8) {
            map.setView([lat, lon], 8, {animate: Boolean(animated)});
        } else {
            map.panTo([lat, lon], {animate: Boolean(animated)});
        }
    }

    function toggleFollowSelectedPilot()
    {
        if (!selectedPilotData) return;
        const userId = Number(selectedPilotData.user_id);
        followedUserId = followedUserId === userId ? 0 : userId;
        if (followedUserId) centerOnPilot(selectedPilotData, true);
        updateFollowButton();
        updateMapUrl(selectedPilotData);
        renderPilotDirectory();
    }

    function pilotDistanceNm(first, second)
    {
        const toRadians = value => Number(value) * Math.PI / 180;
        const latitude1 = toRadians(first.latitude);
        const latitude2 = toRadians(second.latitude);
        const latitudeDifference = latitude2 - latitude1;
        const longitudeDifference =
            toRadians(second.longitude) - toRadians(first.longitude);
        const value = Math.sin(latitudeDifference / 2) ** 2
            + Math.cos(latitude1) * Math.cos(latitude2)
            * Math.sin(longitudeDifference / 2) ** 2;
        return 3440.065 * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
    }

    function formatFlightDuration(seconds)
    {
        const safeSeconds = Math.max(0, Math.round(Number(seconds) || 0));
        const hours = Math.floor(safeSeconds / 3600);
        const minutes = Math.floor((safeSeconds % 3600) / 60);
        return String(hours).padStart(2, '0')
            + ':' + String(minutes).padStart(2, '0') + ' h';
    }

    function filedCruiseSpeedKnots(value)
    {
        const text = String(value || '').trim().toUpperCase();
        let match = text.match(/^N(\d{3,4})$/);
        if (match) return Number(match[1]);
        match = text.match(/^K(\d{3,4})$/);
        if (match) return Number(match[1]) / 1.852;
        match = text.match(/^M(\d{2,3})$/);
        if (match) return (Number(match[1]) / 100) * 573;
        if (/^\d{2,4}$/.test(text)) return Number(text);
        return 0;
    }

    function estimatedCruiseSpeedKnots(pilot, flightplan, routeDistance, remainingDistance)
    {
        const elapsedSeconds = Number(pilot.active_flight_duration_seconds) || 0;
        const flownDistance = Math.max(0, routeDistance - remainingDistance);
        if (elapsedSeconds >= 300 && flownDistance >= 5) {
            const achievedAverage = flownDistance / (elapsedSeconds / 3600);
            if (achievedAverage >= 40 && achievedAverage <= 700) {
                return achievedAverage;
            }
        }

        const filedSpeed = filedCruiseSpeedKnots(flightplan.cruising_speed);
        if (filedSpeed >= 40 && filedSpeed <= 700) return filedSpeed;

        const liveSpeed = Number(pilot.airspeed) || 0;
        if (liveSpeed >= 60 && liveSpeed <= 700) return liveSpeed;

        const filedLevelNumber = Number(
            String(flightplan.cruising_level || '').replace(/[^0-9]/g, '')
        ) || 0;
        const filedAltitude = filedLevelNumber > 0 && filedLevelNumber <= 600
            ? filedLevelNumber * 100
            : filedLevelNumber;
        const altitude = Math.max(Number(pilot.altitude) || 0, filedAltitude);
        const category = String(pilot.aircraft_category || '').toLowerCase();
        if (category === 'heavy' || category === 'medium') return altitude >= 10000 ? 440 : 300;
        if (category.includes('helicopter')) return 110;
        return altitude >= 10000 ? 260 : 125;
    }

    function routeRemainingDistanceNm(pilot, route)
    {
        const points = Array.isArray(route.points) ? route.points : [];
        if (points.length < 2) return 0;
        if (route.mode === 'direct') {
            return pilotDistanceNm(pilot, points[points.length - 1]);
        }

        let bestSegment = 0;
        let bestDistance = Number.POSITIVE_INFINITY;
        const latitudeScale = 60;
        const longitudeScale = 60 * Math.max(0.15, Math.cos(Number(pilot.latitude) * Math.PI / 180));
        const normalizeLongitude = value => ((Number(value) + 540) % 360) - 180;

        for (let index = 0; index < points.length - 1; index++) {
            const first = points[index];
            const second = points[index + 1];
            const ax = normalizeLongitude(Number(first.longitude) - Number(pilot.longitude)) * longitudeScale;
            const ay = (Number(first.latitude) - Number(pilot.latitude)) * latitudeScale;
            const bx = normalizeLongitude(Number(second.longitude) - Number(pilot.longitude)) * longitudeScale;
            const by = (Number(second.latitude) - Number(pilot.latitude)) * latitudeScale;
            const dx = bx - ax;
            const dy = by - ay;
            const lengthSquared = dx * dx + dy * dy;
            const projection = lengthSquared > 0
                ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / lengthSquared))
                : 0;
            const distance = Math.hypot(ax + projection * dx, ay + projection * dy);
            if (distance < bestDistance) {
                bestDistance = distance;
                bestSegment = index;
            }
        }

        let remaining = pilotDistanceNm(pilot, points[bestSegment + 1]);
        for (let index = bestSegment + 2; index < points.length; index++) {
            remaining += pilotDistanceNm(points[index - 1], points[index]);
        }
        return remaining;
    }

    function requestFlightRouteEstimate(flightplan)
    {
        const departure = getAirportCode(flightplan.departure_airport);
        const arrival = getAirportCode(flightplan.arrival_airport);
        const routeText = String(flightplan.route_text || '').trim();
        const key = [departure, arrival, routeText].join('|');
        if (!flightRouteEstimateCache[key]) {
            const body = new URLSearchParams({
                departure: departure,
                arrival: arrival,
                route: routeText
            });
            flightRouteEstimateCache[key] = fetch('execute/flight_route_estimate.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString()
            }).then(response => response.json()).catch(() => ({success:false}));
        }
        return flightRouteEstimateCache[key];
    }

    async function updateFlightProgressPanel(pilot)
    {
        const elapsedElement = document.getElementById('panelElapsedFlightTime');
        const remainingElement = document.getElementById('panelRemainingFlightTime');
        const distanceElement = document.getElementById('panelEstimatedRouteDistance');
        const remainingDistanceElement = document.getElementById('panelRemainingRouteDistance');
        const calculationElement = document.getElementById('panelRouteCalculation');
        if (!elapsedElement || !IS_WEB_LOGGED_IN) return;

        elapsedElement.textContent = formatFlightDuration(pilot.active_flight_duration_seconds);
        const flightplan = getFlightplan(pilot);
        const departure = getAirportCode(flightplan.departure_airport);
        const arrival = getAirportCode(flightplan.arrival_airport);
        if (departure === 'ZZZZ' || arrival === 'ZZZZ') {
            remainingElement.textContent = MAP_TEXT.route_unavailable;
            distanceElement.textContent = '----';
            if (remainingDistanceElement) remainingDistanceElement.textContent = '----';
            calculationElement.textContent = MAP_TEXT.route_unavailable;
            return;
        }

        calculationElement.textContent = MAP_TEXT.route_estimating;
        const generation = ++flightRouteEstimateGeneration;
        const callsign = String(pilot.callsign || '');
        const route = await requestFlightRouteEstimate(flightplan);
        if (generation !== flightRouteEstimateGeneration || selectedCallsign !== callsign) return;
        if (!route.success || !Array.isArray(route.points)) {
            remainingElement.textContent = MAP_TEXT.route_unavailable;
            distanceElement.textContent = '----';
            if (remainingDistanceElement) remainingDistanceElement.textContent = '----';
            calculationElement.textContent = MAP_TEXT.route_unavailable;
            return;
        }

        const routeDistance = Number(route.distance_nm) || 0;
        const remainingDistance = Math.max(0, routeRemainingDistanceNm(pilot, route));
        const speed = estimatedCruiseSpeedKnots(pilot, flightplan, routeDistance, remainingDistance);
        remainingElement.textContent = speed > 0
            ? formatFlightDuration(remainingDistance / speed * 3600)
            : MAP_TEXT.route_unavailable;
        distanceElement.textContent = routeDistance.toFixed(1) + ' NM';
        if (remainingDistanceElement) remainingDistanceElement.textContent = remainingDistance.toFixed(1) + ' NM';
        calculationElement.textContent = route.mode === 'waypoints'
            ? MAP_TEXT.route_via_waypoints
                + (route.resolved_waypoints?.length
                    ? ' (' + route.resolved_waypoints.join(' · ') + ')' : '')
            : MAP_TEXT.route_direct;
    }

    function selectedFlightPlanRouteKey(pilot)
    {
        const flightplan = getFlightplan(pilot);
        return [
            getAirportCode(flightplan.departure_airport),
            getAirportCode(flightplan.arrival_airport),
            String(flightplan.route_text || '').trim()
        ].join('|');
    }

    function hasUsableFlightPlanRoute(pilot)
    {
        if (!IS_WEB_LOGGED_IN || !pilot) return false;
        const flightplan = getFlightplan(pilot);
        return getAirportCode(flightplan.departure_airport) !== 'ZZZZ'
            && getAirportCode(flightplan.arrival_airport) !== 'ZZZZ';
    }

    function updateFlightPlanRouteButton(pilot)
    {
        const button = document.getElementById('flightPlanRouteButton');
        if (!button) return;
        const available = hasUsableFlightPlanRoute(pilot);
        button.hidden = !available;
        button.disabled = flightPlanRouteLoading;
        button.classList.toggle('route-visible', available && flightPlanRouteVisible);
        button.textContent = flightPlanRouteLoading
            ? MAP_TEXT.route_estimating
            : (flightPlanRouteVisible
            ? MAP_TEXT.hide_flightplan_route
            : MAP_TEXT.show_flightplan_route);
    }

    function removeFlightPlanRoute()
    {
        flightPlanRouteGeneration++;
        if (flightPlanRouteLayer) {
            map.removeLayer(flightPlanRouteLayer);
            flightPlanRouteLayer = null;
        }
        if (flightPlanRouteWaypointLayer) {
            map.removeLayer(flightPlanRouteWaypointLayer);
            flightPlanRouteWaypointLayer = null;
        }
        flightPlanRouteVisible = false;
        flightPlanRouteLoading = false;
        flightPlanRouteKey = '';
    }

    function smoothFlightPlanRoute(points, samplesPerLeg = 8)
    {
        const rawRoutePoints = points.map(point => [
            Number(point.latitude),
            Number(point.longitude)
        ]).filter(point => Number.isFinite(point[0]) && Number.isFinite(point[1]));

        // Consecutive duplicate or almost identical fixes create unstable
        // tangents and can make a curved line fold back on itself.
        const routePoints = rawRoutePoints.filter((point, index) => {
            if (index === 0) return true;
            const previous = rawRoutePoints[index - 1];
            return Math.hypot(point[0] - previous[0], point[1] - previous[1]) > 0.00001;
        });

        // Keep routes crossing the date line on the short side of the world.
        for (let index = 1; index < routePoints.length; index++) {
            while (routePoints[index][1] - routePoints[index - 1][1] > 180) routePoints[index][1] -= 360;
            while (routePoints[index][1] - routePoints[index - 1][1] < -180) routePoints[index][1] += 360;
        }

        if (routePoints.length < 3) return routePoints;

        // Use short, locally constrained Bezier handles. The former uniform
        // Catmull-Rom spline could overshoot strongly when a short leg sat
        // between two long legs, producing hooks or even loops. Every Bezier
        // leg still starts and ends exactly on its original waypoint.
        const result = [];
        for (let index = 0; index < routePoints.length - 1; index++) {
            const p0 = routePoints[Math.max(0, index - 1)];
            const p1 = routePoints[index];
            const p2 = routePoints[index + 1];
            const p3 = routePoints[Math.min(routePoints.length - 1, index + 2)];

            const leg = [p2[0] - p1[0], p2[1] - p1[1]];
            const legLength = Math.max(0.000001, Math.hypot(leg[0], leg[1]));
            const normalized = vector => {
                const length = Math.hypot(vector[0], vector[1]);
                return length > 0.000001
                    ? [vector[0] / length, vector[1] / length]
                    : [leg[0] / legLength, leg[1] / legLength];
            };
            let startTangent = normalized([p2[0] - p0[0], p2[1] - p0[1]]);
            let endTangent = normalized([p3[0] - p1[0], p3[1] - p1[1]]);
            // A handle must never point backwards along its own leg.
            if (startTangent[0] * leg[0] + startTangent[1] * leg[1] <= 0) {
                startTangent = normalized(leg);
            }
            if (endTangent[0] * leg[0] + endTangent[1] * leg[1] <= 0) {
                endTangent = normalized(leg);
            }
            const previousLength = Math.hypot(p1[0] - p0[0], p1[1] - p0[1]) || legLength;
            const nextLength = Math.hypot(p3[0] - p2[0], p3[1] - p2[1]) || legLength;
            const startHandle = Math.min(legLength * 0.16, previousLength * 0.12);
            const endHandle = Math.min(legLength * 0.16, nextLength * 0.12);
            const c1 = [p1[0] + startTangent[0] * startHandle, p1[1] + startTangent[1] * startHandle];
            const c2 = [p2[0] - endTangent[0] * endHandle, p2[1] - endTangent[1] * endHandle];

            for (let sample = 0; sample < samplesPerLeg; sample++) {
                const t = sample / samplesPerLeg;
                const inverse = 1 - t;
                result.push([0, 1].map(axis =>
                    inverse * inverse * inverse * p1[axis]
                    + 3 * inverse * inverse * t * c1[axis]
                    + 3 * inverse * t * t * c2[axis]
                    + t * t * t * p2[axis]
                ));
            }
        }
        result.push(routePoints[routePoints.length - 1]);
        return result;
    }

    async function toggleSelectedFlightPlanRoute()
    {
        const pilot = selectedPilotData;
        if (!hasUsableFlightPlanRoute(pilot)) return;
        if (flightPlanRouteLoading) return;
        if (flightPlanRouteVisible) {
            removeFlightPlanRoute();
            updateFlightPlanRouteButton(pilot);
            return;
        }

        const button = document.getElementById('flightPlanRouteButton');
        const callsign = String(pilot.callsign || '');
        const requestedKey = selectedFlightPlanRouteKey(pilot);
        const generation = ++flightPlanRouteGeneration;
        flightPlanRouteLoading = true;
        flightPlanRouteKey = requestedKey;
        button.disabled = true;
        button.textContent = MAP_TEXT.route_estimating;

        const route = await requestFlightRouteEstimate(getFlightplan(pilot));
        if (
            generation !== flightPlanRouteGeneration
            || selectedCallsign !== callsign
            || !selectedPilotData
            || selectedFlightPlanRouteKey(selectedPilotData) !== requestedKey
        ) return;

        button.disabled = false;
        flightPlanRouteLoading = false;
        if (!route.success || !Array.isArray(route.points) || route.points.length < 2) {
            button.textContent = MAP_TEXT.route_unavailable;
            return;
        }

        const smoothedPoints = smoothFlightPlanRoute(route.points, 8);
        flightPlanRouteLayer = L.polyline(smoothedPoints, {
            color: '#f28c18',
            weight: 3,
            opacity: 0.95,
            dashArray: '10, 8',
            lineCap: 'round',
            lineJoin: 'round',
            smoothFactor: 0.5
        }).addTo(map);
        flightPlanRouteLayer.bringToFront();

        flightPlanRouteWaypointLayer = L.layerGroup();
        route.points.slice(1, -1).forEach(point => {
            const latitude = Number(point.latitude);
            const longitude = Number(point.longitude);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
            const marker = L.circleMarker([latitude, longitude], {
                radius: 4,
                color: '#fff2dc',
                weight: 1,
                fillColor: '#f28c18',
                fillOpacity: 1,
                opacity: 1
            });
            marker.bindTooltip(escapeHtml((point.procedure ? point.procedure + ' · ' : '') + (point.identifier || '')), {
                direction: 'top',
                offset: [0, -4],
                permanent: MAP_WAYPOINT_LABELS_MODE === 'always',
                className: 'flightplan-waypoint-label'
            });
            marker.addTo(flightPlanRouteWaypointLayer);
        });
        flightPlanRouteWaypointLayer.addTo(map);
        flightPlanRouteVisible = true;
        flightPlanRouteKey = requestedKey;
        updateFlightPlanRouteButton(pilot);
    }

    function renderPilotDirectory()
    {
        const list = document.getElementById('pilotDirectoryList');
        const query = document.getElementById('pilotSearch').value
            .trim().toLocaleLowerCase();
        const searchType = document.getElementById('mapSearchType').value;
        const exactOnly = document.getElementById('mapSearchExact').checked;
        const ownPilot = latestPilots.find(pilot =>
            Number(pilot.user_id) === WEB_USER_ID
        );
        const nearbyOnly = Boolean(
            document.getElementById('nearbyPilotsOnly')?.checked
        );
        const pilots = latestPilots.filter(function(pilot) {
            if (searchType !== 'all' && searchType !== 'pilot') return false;
            if (
                nearbyOnly
                && (
                    !ownPilot
                    || Number(pilot.user_id) === WEB_USER_ID
                    || pilotDistanceNm(ownPilot, pilot) > 30
                )
            ) return false;
            const flightplan = getFlightplan(pilot);
            const values = [
                pilot.callsign, pilot.username, pilot.aircraft_icao,
                flightplan.departure_airport, flightplan.arrival_airport
            ].map(value => String(value || '').toLocaleLowerCase());
            return exactOnly
                ? values.includes(query)
                : values.join(' ').includes(query);
        });
        const visibleAirports = airportSearchResults.filter(function(airport) {
            if (searchType !== 'all' && searchType !== 'airport') return false;
            return !exactOnly
                || String(airport.code || '').toLocaleLowerCase() === query;
        });
        const visibleNavigation = navigationSearchResults.filter(function(point) {
            if (searchType !== 'all' && searchType !== point.kind) return false;
            return !exactOnly
                || [point.identifier, point.name].some(value =>
                    String(value || '').toLocaleLowerCase() === query
                );
        });
        const visibleAtc = latestAtcPositions.filter(function(position) {
            if (searchType !== 'all' && searchType !== 'atc') return false;
            const values = [
                position.callsign, position.station_code, position.position_code,
                position.controller_name, position.airport_name, position.frequency
            ].map(value => String(value || '').toLocaleLowerCase());
            return exactOnly ? values.includes(query) : values.join(' ').includes(query);
        });
        const airportHtml = visibleAirports.map(function(airport) {
            return '<button type="button" class="pilot-directory-item airport-result"'
                + ' data-airport-code="' + escapeHtml(airport.code) + '">'
                + '<div class="pilot-directory-callsign">✈ '
                + escapeHtml(airport.code) + '</div>'
                + '<div class="pilot-directory-meta">'
                + escapeHtml(airport.name)
                + (airport.municipality ? ' · ' + escapeHtml(airport.municipality) : '')
                + '</div></button>';
        }).join('');
        const navigationHtml = visibleNavigation.map(function(point) {
            const index = navigationSearchResults.indexOf(point);
            const kind = point.kind === 'radar'
                ? MAP_TEXT.navigation_radar
                : (point.kind === 'airway'
                    ? MAP_TEXT.navigation_airway
                    : (point.kind === 'navaid'
                        ? MAP_TEXT.navigation_navaid
                        : MAP_TEXT.navigation_waypoint));
            const detail = [kind, point.type, point.region]
                .filter(Boolean).join(' · ');
            return '<button type="button" class="pilot-directory-item"'
                + ' data-navigation-index="' + index + '">'
                + '<div class="pilot-directory-callsign">◆ '
                + escapeHtml(point.identifier) + '</div>'
                + '<div class="pilot-directory-meta">'
                + escapeHtml(detail)
                + (point.name && point.name !== point.identifier
                    ? ' · ' + escapeHtml(point.name) : '')
                + '</div></button>';
        }).join('');
        const atcHtml = visibleAtc.map(function(position) {
            const index = latestAtcPositions.indexOf(position);
            return '<button type="button" class="pilot-directory-item"'
                + ' data-atc-index="' + index + '">'
                + '<div class="pilot-directory-callsign">ATC '
                + escapeHtml(displayedAtcCallsign(position)
                    || position.station_code || '') + '</div>'
                + '<div class="pilot-directory-meta">'
                + escapeHtml([
                    position.controller_name,
                    position.position_code,
                    position.frequency ? position.frequency + ' MHz' : ''
                ].filter(Boolean).join(' · '))
                + '</div></button>';
        }).join('');
        if (
            pilots.length === 0
            && visibleAirports.length === 0
            && visibleNavigation.length === 0
            && visibleAtc.length === 0
        ) {
            list.innerHTML = '<div class="pilot-directory-meta">'
                + escapeHtml(MAP_TEXT.no_pilots_found) + '</div>';
            return;
        }
        list.innerHTML = atcHtml + airportHtml + navigationHtml + pilots.map(function(pilot) {
            const flightplan = getFlightplan(pilot);
            const active = selectedCallsign === pilot.callsign ? ' active' : '';
            const following = followedUserId === Number(pilot.user_id) ? ' · ◉' : '';
            const distance = ownPilot && Number(pilot.user_id) !== WEB_USER_ID
                ? ' · ' + pilotDistanceNm(ownPilot, pilot).toFixed(1) + ' NM'
                : '';
            const spectator = pilot.is_spectator ? ' · SPECTATOR' : '';
            return '<button type="button" class="pilot-directory-item' + active
                + '" data-pilot-user-id="' + Number(pilot.user_id) + '">'
                + '<div class="pilot-directory-callsign">' + escapeHtml(pilot.callsign)
                + escapeHtml(following) + '</div>'
                + '<div class="pilot-directory-meta">' + escapeHtml(pilot.aircraft_icao || '----')
                + ' · ' + escapeHtml(getAirportCode(flightplan.departure_airport))
                + ' → ' + escapeHtml(getAirportCode(flightplan.arrival_airport))
                + escapeHtml(distance + spectator) + '</div>'
                + '</button>';
        }).join('');
    }

    function normalizeRadarSearch(value)
    {
        return String(value || '')
            .toLocaleLowerCase()
            .replace(/ä/g, 'ae')
            .replace(/ö/g, 'oe')
            .replace(/ü/g, 'ue')
            .replace(/ß/g, 'ss')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/_/g, '-');
    }

    function radarIdentifierDistance(left, right)
    {
        if (left.length !== right.length) return 99;
        const differences = [];
        for (let index = 0; index < left.length; index++) {
            if (left[index] !== right[index]) differences.push(index);
            if (differences.length > 2) return 99;
        }
        if (differences.length <= 1) return differences.length;
        const first = differences[0];
        const second = differences[1];
        return second === first + 1
            && left[first] === right[second]
            && left[second] === right[first]
                ? 1 : 99;
    }

    async function searchMapEntities(query)
    {
        if (query.trim().length < 2) {
            airportSearchResults = [];
            navigationSearchResults = [];
            atcSearchResults = [];
            renderPilotDirectory();
            return;
        }
        try {
            const encodedQuery = encodeURIComponent(query.trim());
            const results = await Promise.allSettled([
                fetch('execute/airport_lookup.php?q=' + encodedQuery),
                fetch('execute/airac_search.php?q=' + encodedQuery),
                loadFirDataset()
            ]);
            const airportData = results[0].status === 'fulfilled'
                ? await results[0].value.json() : {success:false};
            const airacData = results[1].status === 'fulfilled'
                ? await results[1].value.json() : {success:false};
            airportSearchResults =
                airportData.success ? (airportData.airports || []) : [];
            const airacResults =
                airacData.success ? (airacData.results || []) : [];
            let radarResults = [];
            if (results[2].status === 'fulfilled') {
                const fir = results[2].value;
                const needle = normalizeRadarSearch(query.trim());
                const seen = new Set();
                radarResults = (fir.data.features || []).filter(feature => {
                    const properties = feature.properties || {};
                    const identifier = String(properties.id || '').toUpperCase();
                    const name = String(fir.names[identifier] || '');
                    const normalizedIdentifier =
                        normalizeRadarSearch(identifier);
                    const haystack = normalizeRadarSearch([
                        identifier, name, properties.region,
                        properties.division
                    ].join(' '));
                    const comparableIdentifier =
                        normalizedIdentifier.substring(0, needle.length);
                    const nearIdentifier = needle.length >= 4
                        && radarIdentifierDistance(
                            comparableIdentifier,
                            needle
                        ) <= 1;
                    if (!identifier
                        || (!haystack.includes(needle) && !nearIdentifier)
                        || seen.has(identifier)) return false;
                    seen.add(identifier);
                    return true;
                }).slice(0, 30).map(feature => {
                    const properties = feature.properties || {};
                    const identifier = String(properties.id || '').toUpperCase();
                    return {
                        kind: 'radar',
                        identifier: identifier,
                        name: fir.names[identifier] || identifier,
                        type: fir.types[identifier] || 'FIR/UIR',
                        region: [properties.region, properties.division]
                            .filter(Boolean).join(' / '),
                        feature: feature
                    };
                });
            }
            navigationSearchResults = airacResults.concat(radarResults);
            atcSearchResults = latestAtcPositions.slice();
            currentAiracCycle =
                airacData.success ? (airacData.cycle || '') : '';
        } catch (error) {
            airportSearchResults = [];
            navigationSearchResults = [];
            atcSearchResults = [];
        }
        renderPilotDirectory();
    }

    document.getElementById('pilotSearch').addEventListener('input', function() {
        renderPilotDirectory();
        clearTimeout(airportSearchTimer);
        const query = this.value;
        airportSearchTimer = setTimeout(() => searchMapEntities(query), 250);
    });
    const mapSearchType = document.getElementById('mapSearchType');
    const mapSearchExact = document.getElementById('mapSearchExact');
    document.getElementById('sectorWaypointsToggle').addEventListener('change', function() {
        if (this.checked) loadSelectedSectorWaypoints();
        else clearSectorWaypoints();
    });
    mapSearchType.value =
        localStorage.getItem('vfn_map_search_type') || 'all';
    mapSearchExact.checked =
        localStorage.getItem('vfn_map_search_exact') === '1';
    mapSearchType.addEventListener('change', function() {
        localStorage.setItem('vfn_map_search_type', this.value);
        renderPilotDirectory();
    });
    mapSearchExact.addEventListener('change', function() {
        localStorage.setItem(
            'vfn_map_search_exact',
            this.checked ? '1' : '0'
        );
        renderPilotDirectory();
    });
    const nearbyPilotsOnly = document.getElementById('nearbyPilotsOnly');
    if (nearbyPilotsOnly) {
        nearbyPilotsOnly.checked =
            localStorage.getItem('vfn_map_nearby_only') === '1';
        nearbyPilotsOnly.addEventListener('change', function() {
            localStorage.setItem(
                'vfn_map_nearby_only',
                this.checked ? '1' : '0'
            );
            renderPilotDirectory();
        });
    }
    if (hideInvisiblePilots) {
        hideInvisiblePilots.checked =
            localStorage.getItem('vfn_map_hide_invisible') === '1';
        hideInvisiblePilots.addEventListener('change', function() {
            localStorage.setItem(
                'vfn_map_hide_invisible',
                this.checked ? '1' : '0'
            );
            loadPilots();
        });
    }
    document.getElementById('pilotDirectoryList').addEventListener('click', function(event) {
        const atcItem = event.target.closest('[data-atc-index]');
        if (atcItem) {
            const position = latestAtcPositions[Number(atcItem.dataset.atcIndex)];
            if (position) {
                const station = String(position.station_code || '').toUpperCase();
                const positions = latestAtcPositions.filter(entry =>
                    String(entry.station_code || '').toUpperCase() === station);
                if (String(position.position_code || '').toUpperCase() === 'CTR') {
                    const boundary = String(
                        position.radar_boundary_code || station
                    ).toUpperCase();
                    const layer = atcFirLayers[boundary];
                    if (layer) map.fitBounds(layer.getBounds(), {padding:[30,30]});
                    openAtcInfoPanel(positions, station, station, MAP_TEXT.atc_radar_sector);
                } else {
                    const marker = atcAirportMarkers[station];
                    if (marker) map.setView(marker.getLatLng(), 11, {animate:true});
                    openAtcInfoPanel(
                        positions, station, position.airport_name || station,
                        MAP_TEXT.atc_positions
                    );
                }
            }
            return;
        }
        const navigationItem = event.target.closest('[data-navigation-index]');
        if (navigationItem) {
            const point =
                navigationSearchResults[Number(navigationItem.dataset.navigationIndex)];
            if (point) openNavigationPointPanel(point);
            return;
        }
        const airportItem = event.target.closest('[data-airport-code]');
        if (airportItem) {
            const airport = airportSearchResults.find(entry =>
                entry.code === airportItem.dataset.airportCode);
            if (airport) {
                searchedAirports[airport.code] = airport;
                ensureAirportTrafficBucket(
                    airportTrafficData,
                    airport.code,
                    airport
                );
                updateAirportTrafficMarkers();
                map.setView([airport.latitude, airport.longitude], 11, {animate:true});
                openAirportTrafficPanel(airport.code);
            }
            return;
        }
        const item = event.target.closest('[data-pilot-user-id]');
        if (!item) return;
        const pilot = latestPilots.find(entry =>
            Number(entry.user_id) === Number(item.dataset.pilotUserId));
        if (pilot) {
            openPilotPanel(pilot);
            centerOnPilot(pilot, true);
        }
    });
    document.getElementById('mapDirectoryToggle').addEventListener('click', function() {
        const directory = document.getElementById('mapDirectory');
        directory.classList.toggle('collapsed');
        this.textContent = directory.classList.contains('collapsed') ? '+' : '−';
    });

    async function loadHeatmap()
    {
        const enabled = document.getElementById('heatmapToggle').checked;
        if (heatmapLayer) {
            map.removeLayer(heatmapLayer);
            heatmapLayer = null;
        }
        if (!enabled) return;
        const days = Number(document.getElementById('heatmapPeriod').value) || 30;
        try {
            const response = await fetch(
                'execute/network_statistics.php?include_heatmap=1&days='
                + encodeURIComponent(days)
            );
            const data = await response.json();
            if (!data.success || !Array.isArray(data.heatmap)) return;
            const maximum = data.heatmap.reduce(
                (max, point) => Math.max(max, Number(point.count) || 0), 1
            );
            const points = data.heatmap.map(point => [
                Number(point.latitude),
                Number(point.longitude),
                Math.max(0.08, Math.log((Number(point.count) || 0) + 1) / Math.log(maximum + 1))
            ]);
            heatmapLayer = L.heatLayer(points, {
                radius: 22,
                blur: 18,
                maxZoom: 9,
                minOpacity: 0.25,
                gradient: {0.2:'#2468ff',0.45:'#25e6c8',0.7:'#ffe55c',1:'#ff3131'}
            }).addTo(map);
        } catch (error) {
            console.warn('Heatmap could not be loaded.', error);
        }
    }

    document.getElementById('heatmapToggle').checked = INITIAL_HEATMAP;
    document.getElementById('heatmapToggle').addEventListener('change', loadHeatmap);
    document.getElementById('heatmapPeriod').addEventListener('change', loadHeatmap);
    if (INITIAL_HEATMAP) loadHeatmap();
    const firBoundaryToggle =
        document.getElementById('firBoundaryToggle');
    firBoundaryToggle.checked =
        localStorage.getItem('vfn_map_show_fir_boundaries') === '1';
    firBoundaryToggle.addEventListener('change', loadFirBoundaries);
    if (firBoundaryToggle.checked) loadFirBoundaries();

    function createAirportTrafficMarker()
    {
        return {
            radius: 4,
            color: '#ffffff',
            weight: 2,
            fillColor: '#000000',
            fillOpacity: 1,
            opacity: 1
        };
    }

    function getAirportTrafficEntry(pilot, airportType)
    {
        const flightplan =
            getFlightplan(pilot);

        const departureCode =
            getAirportCode(
                flightplan.departure_airport
            );

        const arrivalCode =
            getAirportCode(
                flightplan.arrival_airport
            );

        return {
            callsign: pilot.callsign || '----',
            aircraft: pilot.aircraft_icao || '----',
            route: departureCode + ' - ' + arrivalCode,
            departure_time: flightplan.departure_time || '',
            pilot: pilot,
            type: airportType
        };
    }

    function ensureAirportTrafficBucket(traffic, code, info)
    {
        if (!traffic[code])
        {
            traffic[code] = {
                code: code,
                info: info,
                inbound: [],
                outbound: []
            };
        }

        if (
            !traffic[code].info &&
            info
        )
        {
            traffic[code].info = info;
        }

        return traffic[code];
    }

    function addAirportTrafficEntry(traffic, pilot, airportType)
    {
        const flightplan =
            getFlightplan(pilot);

        const code =
            airportType === 'inbound'
            ? getAirportCode(flightplan.arrival_airport)
            : getAirportCode(flightplan.departure_airport);

        const info =
            airportType === 'inbound'
            ? flightplan.arrival_airport_info
            : flightplan.departure_airport_info;

        if (
            code === 'ZZZZ' ||
            !getAirportLatLng(info)
        )
        {
            return;
        }

        const bucket =
            ensureAirportTrafficBucket(
                traffic,
                code,
                info
            );

        bucket[airportType].push(
            getAirportTrafficEntry(
                pilot,
                airportType
            )
        );
    }

    function buildAirportTrafficData(pilots)
    {
        const traffic = {};

        pilots.forEach(pilot =>
        {
            addAirportTrafficEntry(
                traffic,
                pilot,
                'inbound'
            );

            addAirportTrafficEntry(
                traffic,
                pilot,
                'outbound'
            );
        });

        Object.keys(searchedAirports).forEach(code => {
            ensureAirportTrafficBucket(
                traffic,
                code,
                searchedAirports[code]
            );
        });

        return traffic;
    }

    function updateAirportTrafficMarkers()
    {
        Object.keys(trafficAirportMarkers).forEach(code =>
        {
            map.removeLayer(
                trafficAirportMarkers[code]
            );

            delete trafficAirportMarkers[code];
        });

        Object.keys(airportTrafficData).forEach(code =>
        {
            const airport =
                airportTrafficData[code];

            const latLng =
                getAirportLatLng(
                    airport.info
                );

            if (!latLng)
            {
                return;
            }

            const marker =
                L.circleMarker(
                    latLng,
                    createAirportTrafficMarker()
                ).addTo(map);

            const inboundCount =
                airport.inbound.length;

            const outboundCount =
                airport.outbound.length;

            marker.bindTooltip(
                '<b>'
                + escapeHtml(code)
                + '</b><br>'
                + escapeHtml(getAirportName(airport.info))
                + '<br>'
                + escapeHtml(MAP_TEXT.airport_inbound)
                + ': '
                + inboundCount
                + ' | '
                + escapeHtml(MAP_TEXT.airport_outbound)
                + ': '
                + outboundCount,
                {
                    permanent: false,
                    direction: 'top'
                }
            );

            marker.on(
                'click',
                function()
                {
                    openAirportTrafficPanel(code);
                }
            );

            trafficAirportMarkers[code] =
                marker;
        });

        if (selectedAirportCode)
        {
            if (airportTrafficData[selectedAirportCode])
            {
                updateAirportTrafficPanel(
                    selectedAirportCode
                );
            }
            else
            {
                closeAirportTrafficPanel();
            }
        }
    }

    function createAirportIcon(label, airportType)
    {
        let backgroundColor =
            '#1f5fd1';

        if (airportType === 'arrival')
        {
            backgroundColor =
                '#159447';
        }

        return L.divIcon({
            className: '',

            html: `
                <div style="
                    background: ${backgroundColor};
                    color: white;
                    border: 2px solid white;
                    border-radius: 5px;
                    padding: 3px 6px;
                    font-size: 11px;
                    font-weight: bold;
                    box-shadow: 0 1px 5px rgba(0,0,0,0.45);
                    white-space: nowrap;
                ">
                    ${label}
                </div>
            `,

            iconSize: [
                60,
                22
            ],

            iconAnchor: [
                30,
                11
            ]
        });
    }

    function getPilotStatusIcons(pilot)
    {
        const invisibleIcon =
            pilot.is_invisible
            ? '👁 '
            : '';

        const aiControlled =
            pilot.ai_controls_aircraft === true
            || Number(pilot.ai_controls_aircraft) === 1;

        const aiIcon =
            CAN_SEE_AI_CONTROL_STATUS && aiControlled
            ? '🤖 '
            : '';

        return invisibleIcon + aiIcon;
    }

    function createTooltipContent(pilot)
    {
        const flightplan =
            getFlightplan(pilot);

        const dep =
            getAirportCode(
                flightplan.departure_airport
            );

        const arr =
            getAirportCode(
                flightplan.arrival_airport
            );

        const squawk =
            getSquawkCode(pilot);

        const statusIcons =
            getPilotStatusIcons(pilot);

        if (isEmergencySquawk(squawk))
        {
            return `
                <div class="pilot-label-emergency-box">
                    <div>${statusIcons}${pilot.callsign}</div>
                    <div>${pilot.aircraft_icao}</div>
                    <div>${dep} - ${arr}</div>
                </div>
            `;
        }

        return `
            <div class="pilot-label-normal-box">
                <div><b>${statusIcons}${pilot.callsign}</b></div>
                <div>${pilot.aircraft_icao}</div>
                <div>${dep} - ${arr}</div>
            </div>
        `;
    }

    function resetMarkerZIndexes()
    {
        Object.keys(pilotMarkers).forEach(callsign =>
        {
            pilotMarkers[callsign].setZIndexOffset(0);
        });
    }

    function removeAllTracks()
    {
        pilotTrackLoadGeneration++;

        Object.keys(pilotTracks).forEach(callsign =>
        {
            map.removeLayer(pilotTracks[callsign]);

            delete pilotTracks[callsign];
            delete pilotTrackLastIds[callsign];
            delete pilotTrackSegments[callsign];
            delete pilotTrackLastPoints[callsign];
        });

        /*
            A track can be removed while an update request is still pending
            or after its polyline has already disappeared. Clear every
            remaining cursor as well. Otherwise reopening a pilot starts
            after the old database ID and only the newly recorded taxi
            section is drawn.
        */
        Object.keys(pilotTrackLastIds).forEach(callsign =>
        {
            delete pilotTrackLastIds[callsign];
            delete pilotTrackSegments[callsign];
            delete pilotTrackLastPoints[callsign];
        });
    }

    function removeAirportRouteOverlays()
    {
        Object.keys(airportRouteLines).forEach(key =>
        {
            map.removeLayer(airportRouteLines[key]);

            delete airportRouteLines[key];
        });

        Object.keys(airportMarkers).forEach(key =>
        {
            map.removeLayer(airportMarkers[key]);

            delete airportMarkers[key];
        });
    }

    function resetTrackForCallsign(callsign)
    {
        pilotTrackLoadGeneration++;

        if (pilotTracks[callsign])
        {
            map.removeLayer(pilotTracks[callsign]);

            delete pilotTracks[callsign];
        }

        pilotTrackLastIds[callsign] = 0;
        delete pilotTrackSegments[callsign];
        delete pilotTrackLastPoints[callsign];
    }

    function appendTrackPoints(callsign, points)
    {
        const trackPoints =
            points
                .map(point => ({
                    latitude: Number(point.latitude),
                    longitude: Number(point.longitude),
                    timestamp: Date.parse(String(point.created_at || '').replace(' ', 'T') + 'Z')
                }))
                .filter(point =>
                    Number.isFinite(point.latitude) &&
                    Number.isFinite(point.longitude)
                );

        if (trackPoints.length === 0)
        {
            return;
        }

        if (!pilotTracks[callsign])
        {
            pilotTracks[callsign] =
                L.polyline(
                    [],
                    {
                        color: '#1737a6',
                        weight: 3,
                        opacity: 0.75,
                        smoothFactor: 1.0
                    }
                ).addTo(map);

            pilotTrackSegments[callsign] = [];
        }

        const segments = pilotTrackSegments[callsign] || [];

        trackPoints.forEach(point =>
        {
            const previous = pilotTrackLastPoints[callsign] || null;
            const distanceNm = previous
                ? pilotDistanceNm(previous, point)
                : 0;
            const timeGapSeconds = previous
                && Number.isFinite(previous.timestamp)
                && Number.isFinite(point.timestamp)
                ? Math.max(0, (point.timestamp - previous.timestamp) / 1000)
                : 0;

            // Never draw a straight line across a simulator reposition, a
            // disconnected update interval, or a resumed/stale track.
            const startsNewSegment =
                !previous
                || distanceNm > 8
                || timeGapSeconds > 60;

            if (startsNewSegment) {
                segments.push([]);
            }

            const currentSegment = segments[segments.length - 1];
            const lastExisting = currentSegment[currentSegment.length - 1];

            if (
                !lastExisting ||
                lastExisting.lat !== point.latitude ||
                lastExisting.lng !== point.longitude
            )
            {
                currentSegment.push(
                    L.latLng(
                        point.latitude,
                        point.longitude
                    )
                );
            }

            pilotTrackLastPoints[callsign] = point;
        });

        pilotTrackSegments[callsign] = segments;
        pilotTracks[callsign].setLatLngs(segments);
    }

    function updateAirportRouteOverlays(pilot)
    {
        if (
            !selectedCallsign ||
            selectedCallsign !== pilot.callsign
        )
        {
            return;
        }

        removeAirportRouteOverlays();

        const flightplan =
            getFlightplan(pilot);

        const planeLat =
            Number(pilot.latitude);

        const planeLon =
            Number(pilot.longitude);

        if (
            isNaN(planeLat) ||
            isNaN(planeLon)
        )
        {
            return;
        }

        const planePoint =
            [
                planeLat,
                planeLon
            ];

        const departureCode =
            getAirportCode(
                flightplan.departure_airport
            );

        const arrivalCode =
            getAirportCode(
                flightplan.arrival_airport
            );

        const departureInfo =
            flightplan.departure_airport_info || null;

        const arrivalInfo =
            flightplan.arrival_airport_info || null;

        const departurePoint =
            getAirportLatLng(
                departureInfo
            );

        const arrivalPoint =
            getAirportLatLng(
                arrivalInfo
            );

        if (
            departureCode !== 'ZZZZ' &&
            departurePoint
        )
        {
            airportMarkers.departure =
                L.marker(
                    departurePoint,
                    {
                        icon:
                            createAirportIcon(
                                departureCode,
                                'departure'
                            )
                    }
                ).addTo(map);

            airportMarkers.departure.bindTooltip(
                getAirportName(departureInfo),
                {
                    permanent: false,
                    direction: 'top'
                }
            );
            airportMarkers.departure.on('click', function() {
                if (airportTrafficData[departureCode]) {
                    openAirportTrafficPanel(departureCode);
                }
            });

            airportRouteLines.departure =
                L.polyline(
                    [
                        planePoint,
                        departurePoint
                    ],
                    {
                        color: '#1f5fd1',
                        weight: 2,
                        opacity: 0.9,
                        dashArray: '6, 6'
                    }
                ).addTo(map);
        }

        if (
            arrivalCode !== 'ZZZZ' &&
            arrivalPoint
        )
        {
            airportMarkers.arrival =
                L.marker(
                    arrivalPoint,
                    {
                        icon:
                            createAirportIcon(
                                arrivalCode,
                                'arrival'
                            )
                    }
                ).addTo(map);

            airportMarkers.arrival.bindTooltip(
                getAirportName(arrivalInfo),
                {
                    permanent: false,
                    direction: 'top'
                }
            );
            airportMarkers.arrival.on('click', function() {
                if (airportTrafficData[arrivalCode]) {
                    openAirportTrafficPanel(arrivalCode);
                }
            });

            airportRouteLines.arrival =
                L.polyline(
                    [
                        planePoint,
                        arrivalPoint
                    ],
                    {
                        color: '#159447',
                        weight: 2,
                        opacity: 0.9,
                        dashArray: '6, 6'
                    }
                ).addTo(map);
        }
    }

    async function loadTrackUpdates(callsign)
    {
        if (!callsign)
        {
            return;
        }

        const lastId =
            pilotTrackLastIds[callsign] || 0;
        const requestGeneration =
            pilotTrackLoadGeneration;

        try
        {
            const response =
                await fetch(
                    '/execute/get_track_updates.php?callsign='
                    + encodeURIComponent(callsign)
                    + '&last_id='
                    + encodeURIComponent(lastId)
                    + '&time='
                    + Date.now()
                );

            const data =
                await response.json();

            if (requestGeneration !== pilotTrackLoadGeneration)
            {
                return;
            }

            if (!data.success)
            {
                return;
            }

            if (
                data.points &&
                data.points.length > 0
            )
            {
                appendTrackPoints(
                    callsign,
                    data.points
                );
            }

            pilotTrackLastIds[callsign] =
                Number(data.last_id) || lastId;

            if (pilotTracks[callsign])
            {
                pilotTracks[callsign].bringToFront();
            }
        }
        catch(error)
        {
            console.error(error);
        }
    }

    function renderAirportTrafficList(airport)
    {
        const listElement =
            document.getElementById('airportTrafficList');

        if (selectedAirportTab === 'metar') {
            renderAirportMetar(airport.code);
            return;
        }

        const entries =
            airport[selectedAirportTab] || [];

        if (entries.length === 0)
        {
            listElement.innerHTML =
                '<div class="airport-traffic-empty">'
                + escapeHtml(
                    selectedAirportTab === 'inbound'
                    ? MAP_TEXT.airport_no_inbound
                    : MAP_TEXT.airport_no_outbound
                )
                + '</div>';

            return;
        }

        listElement.innerHTML =
            entries
                .map(function(entry, index)
                {
                    const timeHtml =
                        entry.departure_time
                        ? `
                            <div class="airport-traffic-meta">
                                ${escapeHtml(MAP_TEXT.airport_departure_time)}:
                                ${escapeHtml(entry.departure_time)}
                            </div>
                        `
                        : '';

                    return `
                        <div
                            class="airport-traffic-card"
                            onclick="openAirportTrafficPilot(${index})">
                            <div class="airport-traffic-main">
                                <div class="airport-traffic-callsign">
                                    ${escapeHtml(entry.callsign)}
                                </div>
                                <div class="airport-traffic-aircraft">
                                    ${escapeHtml(entry.aircraft)}
                                </div>
                            </div>
                            <div class="airport-traffic-route">
                                ${escapeHtml(entry.route)}
                            </div>
                            ${timeHtml}
                        </div>
                    `;
                })
                .join('');
    }

    async function renderAirportMetar(code)
    {
        const listElement = document.getElementById('airportTrafficList');
        const cached = airportMetarCache[code];
        if (cached) {
            showAirportMetar(cached);
            return;
        }
        listElement.innerHTML = '<div class="airport-traffic-empty">'
            + escapeHtml(MAP_TEXT.airport_metar_loading) + '</div>';
        try {
            const response = await fetch(
                'execute/airport_metar.php?airport=' + encodeURIComponent(code)
            );
            const data = await response.json();
            if (!data.success) throw new Error('unavailable');
            airportMetarCache[code] = data;
            if (selectedAirportCode === code && selectedAirportTab === 'metar') {
                showAirportMetar(data);
            }
        } catch (error) {
            if (selectedAirportCode === code && selectedAirportTab === 'metar') {
                listElement.innerHTML = '<div class="airport-traffic-empty">'
                    + escapeHtml(MAP_TEXT.airport_metar_unavailable) + '</div>';
            }
        }
    }

    function showAirportMetar(data)
    {
        document.getElementById('airportTrafficList').innerHTML =
            '<div class="airport-metar">'
            + '<div class="airport-metar-raw">' + escapeHtml(data.raw_text) + '</div>'
            + '<div class="airport-metar-time">'
            + escapeHtml(MAP_TEXT.airport_metar_observed) + ': '
            + escapeHtml(data.observed_at || '—')
            + '</div></div>';
    }

    function updateAirportTrafficPanel(code)
    {
        const airport =
            airportTrafficData[code];

        if (!airport)
        {
            return;
        }

        document.getElementById('airportPanelIcao').innerText =
            code;
        document.getElementById('airportPanelKind').innerText =
            code + ' ' + MAP_TEXT.airport_information;

        document.getElementById('airportPanelName').innerText =
            getAirportName(
                airport.info
            );

        document.getElementById('airportPanelInboundCount').innerText =
            airport.inbound.length;

        document.getElementById('airportPanelOutboundCount').innerText =
            airport.outbound.length;

        document
            .getElementById('airportInboundTab')
            .classList
            .toggle(
                'active',
                selectedAirportTab === 'inbound'
            );

        document
            .getElementById('airportOutboundTab')
            .classList
            .toggle(
                'active',
                selectedAirportTab === 'outbound'
            );

        document
            .getElementById('airportMetarTab')
            .classList
            .toggle('active', selectedAirportTab === 'metar');

        renderAirportTrafficList(airport);
        updateSelectedAirportAtc();
    }

    function openAirportTrafficPanel(code)
    {
        closeNavigationPointPanel();
        closeAtcInfoPanel();

        selectedAirportCode =
            code;

        selectedCallsign =
            null;
        selectedPilotData = null;
        followedUserId = 0;
        updateFollowButton();
        updateMapUrl(null);
        renderPilotDirectory();

        pilotInfoPanel.classList.remove('open');

        resetMarkerZIndexes();

        removeAllTracks();

        removeAirportRouteOverlays();

        if (selectedAirportMarker)
        {
            map.removeLayer(selectedAirportMarker);
            selectedAirportMarker = null;
        }

        const selectedAirport =
            airportTrafficData[code];
        const selectedAirportLatLng =
            selectedAirport
            ? getAirportLatLng(selectedAirport.info)
            : null;

        if (selectedAirportLatLng)
        {
            selectedAirportMarker =
                L.circleMarker(
                    selectedAirportLatLng,
                    {
                        radius: 10,
                        color: '#ffffff',
                        weight: 3,
                        fillColor: '#168cff',
                        fillOpacity: 1,
                        opacity: 1
                    }
                ).addTo(map);

            selectedAirportMarker.bringToFront();
        }

        updateAirportTrafficPanel(code);
        document.getElementById('airportDetailsLink').href =
            'airport.php?icao=' + encodeURIComponent(code);

        airportTrafficPanel.classList.add('open');
    }

    async function openTargetAirport()
    {
        if (!TARGET_AIRPORT) return;
        try {
            const response = await fetch(
                'execute/airport_lookup.php?q='
                + encodeURIComponent(TARGET_AIRPORT)
            );
            const data = await response.json();
            const airport = (data.airports || []).find(entry =>
                String(entry.code).toUpperCase() === TARGET_AIRPORT
                || String(entry.ident).toUpperCase() === TARGET_AIRPORT
            );
            if (!airport) return;
            searchedAirports[airport.code] = airport;
            ensureAirportTrafficBucket(
                airportTrafficData,
                airport.code,
                airport
            );
            updateAirportTrafficMarkers();
            map.setView(
                [Number(airport.latitude), Number(airport.longitude)],
                11
            );
            openAirportTrafficPanel(airport.code);
        } catch (error) {
            console.warn('Target airport could not be opened.', error);
        }
    }

    function closeAirportTrafficPanel()
    {
        selectedAirportCode =
            null;

        if (selectedAirportMarker)
        {
            map.removeLayer(selectedAirportMarker);
            selectedAirportMarker = null;
        }

        airportTrafficPanel.classList.remove('open');
    }

    async function openNavigationPointPanel(point)
    {
        closeAirportTrafficPanel();
        closeAtcInfoPanel();
        pilotInfoPanel.classList.remove('open');
        selectedCallsign = null;
        selectedPilotData = null;
        followedUserId = 0;
        updateFollowButton();
        updateMapUrl(null);
        removeAllTracks();
        removeAirportRouteOverlays();
        resetSectorWaypointOption();

        if (selectedNavigationMarker) {
            map.removeLayer(selectedNavigationMarker);
            selectedNavigationMarker = null;
        }
        if (selectedRadarLayer) {
            map.removeLayer(selectedRadarLayer);
            selectedRadarLayer = null;
        }
        if (selectedAirwayLayer) {
            map.removeLayer(selectedAirwayLayer);
            selectedAirwayLayer = null;
        }
        if (point.kind === 'airway') {
            selectedAirwayLayer = L.layerGroup().addTo(map);
            const airwayLayer = selectedAirwayLayer;
            document.getElementById('navigationPointIdentifier').textContent =
                point.identifier || '----';
            document.getElementById('navigationPointName').textContent =
                point.identifier || '----';
            document.getElementById('navigationPointKind').textContent =
                MAP_TEXT.navigation_airway;
            document.getElementById('navigationPointType').textContent =
                point.type || '----';
            document.getElementById('navigationPointRegion').textContent = '...';
            document.getElementById('navigationPointFrequency').textContent = '...';
            document.getElementById('navigationPointCycle').textContent =
                currentAiracCycle || '----';
            navigationPointPanel.classList.add('open');
            try {
                const response = await fetch(
                    'execute/airway_detail.php?identifier='
                    + encodeURIComponent(point.identifier)
                );
                const payload = await response.json();
                if (!response.ok || !payload.success || !payload.airway) {
                    throw new Error(payload.message || 'airway_unavailable');
                }
                if (selectedAirwayLayer !== airwayLayer) return;
                const airway = payload.airway;
                const bounds = [];
                (airway.paths || []).forEach(path => {
                    const coordinates = path.map(segment => [
                        Number(segment.latitude), Number(segment.longitude)
                    ]).filter(position =>
                        Number.isFinite(position[0]) && Number.isFinite(position[1])
                    );
                    if (coordinates.length < 2) return;
                    coordinates.forEach(position => bounds.push(position));
                    L.polyline(coordinates, {
                        color: '#ff8c1a', weight: 3, opacity: 0.9,
                        dashArray: '10 7'
                    }).addTo(airwayLayer);
                });
                (airway.segments || []).forEach(segment => {
                    const latitude = Number(segment.latitude);
                    const longitude = Number(segment.longitude);
                    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;
                    L.circleMarker([latitude, longitude], {
                        radius: 4, color: '#ffffff', weight: 1.5,
                        fillColor: '#ff8c1a', fillOpacity: 1
                    }).bindTooltip(
                        escapeHtml(segment.identifier || '----'),
                        {direction:'top', sticky:true}
                    ).addTo(airwayLayer);
                });
                document.getElementById('navigationPointType').textContent =
                    airway.type || point.type || '----';
                document.getElementById('navigationPointRegion').textContent =
                    MAP_TEXT.airway_segments + ': ' + (airway.segments || []).length;
                document.getElementById('navigationPointFrequency').textContent =
                    MAP_TEXT.airway_paths + ': ' + (airway.paths || []).length;
                if (bounds.length >= 2) {
                    map.fitBounds(L.latLngBounds(bounds), {padding:[45, 45]});
                }
            } catch (error) {
                document.getElementById('navigationPointRegion').textContent =
                    MAP_TEXT.airway_unavailable;
                document.getElementById('navigationPointFrequency').textContent = '----';
            }
            return;
        } else if (point.kind === 'radar' && point.feature) {
            selectedRadarLayer = L.geoJSON(point.feature, {
                style: {
                    color: '#00d9ff', weight: 4, opacity: 1,
                    fillColor: '#168cff', fillOpacity: 0.18
                }
            }).addTo(map);
            selectedRadarIdentifier = String(point.identifier || '')
                .toUpperCase().replace(/-/g, '_');
            document.getElementById('sectorWaypointsOption').hidden = false;
            const bounds = selectedRadarLayer.getBounds();
            if (!point.preserveView && bounds.isValid()) {
                map.fitBounds(bounds, {padding:[40, 40]});
            }
            const radarFrequencyField =
                document.getElementById('navigationPointFrequency');
            radarFrequencyField.textContent = '...';
            navigationPointPanel.classList.add('open');
            try {
                const response = await fetch(
                    'execute/radar_frequencies.php?station='
                    + encodeURIComponent(selectedRadarIdentifier)
                    + '&v=2',
                    {cache: 'no-store'}
                );
                const data = response.ok ? await response.json() : null;
                if (data?.success && Array.isArray(data.frequencies)) {
                    point.frequency = data.frequencies.join(' / ');
                    point.frequency_unit = data.frequencies.length ? 'MHz' : '';
                }
            } catch (error) {
                console.error('Radar frequency unavailable', error);
            }
            radarFrequencyField.textContent = point.frequency
                ? point.frequency + ' ' + (point.frequency_unit || '')
                : '----';
        } else {
            selectedNavigationMarker = L.circleMarker(
                [Number(point.latitude), Number(point.longitude)],
                {
                    radius: 9,
                    color: '#ffffff',
                    weight: 3,
                    fillColor: point.kind === 'navaid' ? '#8f54ff' : '#168cff',
                    fillOpacity: 1
                }
            ).addTo(map);
            selectedNavigationMarker.bringToFront();
            map.setView(
                [Number(point.latitude), Number(point.longitude)],
                Math.max(map.getZoom(), 10),
                {animate:true}
            );
        }

        document.getElementById('navigationPointIdentifier').textContent =
            point.identifier || '----';
        document.getElementById('navigationPointName').textContent =
            point.name || point.identifier || '----';
        document.getElementById('navigationPointKind').textContent =
            point.kind === 'radar'
                ? MAP_TEXT.navigation_radar
                : (point.kind === 'navaid'
                    ? MAP_TEXT.navigation_navaid
                    : MAP_TEXT.navigation_waypoint);
        document.getElementById('navigationPointType').textContent =
            point.type || '----';
        document.getElementById('navigationPointRegion').textContent =
            point.region || '----';
        document.getElementById('navigationPointFrequency').textContent =
            point.frequency
                ? point.frequency + ' ' + (point.frequency_unit || '')
                : '----';
        document.getElementById('navigationPointCycle').textContent =
            point.kind === 'radar' ? 'VATSpy' : (currentAiracCycle || '----');
        navigationPointPanel.classList.add('open');
    }

    function closeNavigationPointPanel()
    {
        resetSectorWaypointOption();
        navigationPointPanel.classList.remove('open');
        if (selectedNavigationMarker) {
            map.removeLayer(selectedNavigationMarker);
            selectedNavigationMarker = null;
        }
        if (selectedRadarLayer) {
            map.removeLayer(selectedRadarLayer);
            selectedRadarLayer = null;
        }
        if (selectedAirwayLayer) {
            map.removeLayer(selectedAirwayLayer);
            selectedAirwayLayer = null;
        }
    }

    function setAirportTrafficTab(tab)
    {
        selectedAirportTab =
            tab;

        if (selectedAirportCode)
        {
            updateAirportTrafficPanel(
                selectedAirportCode
            );
        }
    }

    function openAirportTrafficPilot(index)
    {
        if (!selectedAirportCode)
        {
            return;
        }

        const airport =
            airportTrafficData[selectedAirportCode];

        if (!airport)
        {
            return;
        }

        const entry =
            (airport[selectedAirportTab] || [])[index];

        if (
            entry &&
            entry.pilot
        )
        {
            closeAirportTrafficPanel();

            openPilotPanel(
                entry.pilot
            );
        }
    }

    function openPilotPanel(pilot)
    {
        closeNavigationPointPanel();
        closeAtcInfoPanel();

        if (selectedCallsign !== pilot.callsign) {
            removeFlightPlanRoute();
        }

        selectedCallsign =
            pilot.callsign;
        selectedPilotData = pilot;

        closeAirportTrafficPanel();

        updatePilotPanel(pilot);

        pilotInfoPanel.classList.add('open');

        resetMarkerZIndexes();

        removeAirportRouteOverlays();

        loadTrackUpdates(
            selectedCallsign
        );

        updateAirportRouteOverlays(
            pilot
        );

        if (pilotMarkers[selectedCallsign])
        {
            pilotMarkers[selectedCallsign].setZIndexOffset(1000);
        }
        updateFollowButton();
        updateMapUrl(pilot);
        renderPilotDirectory();
    }

    function closePilotPanel()
    {
        flightRouteEstimateGeneration++;
        removeFlightPlanRoute();
        selectedCallsign = null;
        selectedPilotData = null;
        followedUserId = 0;

        pilotInfoPanel.classList.remove('open');

        resetMarkerZIndexes();

        removeAllTracks();

        removeAirportRouteOverlays();
        updateFollowButton();
        updateMapUrl(null);
        renderPilotDirectory();
    }

    function updatePilotPanel(pilot)
    {
        updatePanelAccessVisibility();
        const flightplan =
            getFlightplan(pilot);

        const departureCode =
            getAirportCode(
                flightplan.departure_airport
            );

        const arrivalCode =
            getAirportCode(
                flightplan.arrival_airport
            );

        const departureName =
            getAirportName(
                flightplan.departure_airport_info
            );

        const arrivalName =
            getAirportName(
                flightplan.arrival_airport_info
            );

        const squawk =
            getSquawkCode(pilot);

        const transponderElement =
            document.getElementById('panelTransponder');

        document.getElementById('panelCallsign').innerText =
            getPilotStatusIcons(pilot) + (pilot.callsign || '----');

        document.getElementById('panelDeparture').innerText =
            departureCode;
        document.getElementById('panelDeparture').onclick = function() {
            if (airportTrafficData[departureCode]) {
                openAirportTrafficPanel(departureCode);
            }
        };

        document.getElementById('panelArrival').innerText =
            arrivalCode;
        document.getElementById('panelArrival').onclick = function() {
            if (airportTrafficData[arrivalCode]) {
                openAirportTrafficPanel(arrivalCode);
            }
        };

        document.getElementById('panelDepartureName').innerText =
            departureName;

        document.getElementById('panelArrivalName').innerText =
            arrivalName;

        const usernameElement =
            document.getElementById('panelUsername');

        const countryCode =
            String(
                pilot.country_code || ''
            ).toLowerCase();

        if (countryCode !== '')
        {
            usernameElement.innerHTML =
                '<img src="images/flags/'
                + countryCode
                + '.png" '
                + 'style="height:20px;vertical-align:-2px;margin-right:5px;">'
                + escapeHtml(
                    pilot.username || '----'
                );
        }
        else
        {
            usernameElement.innerText =
                pilot.username || '----';
        }

        if (pilot.user_id)
        {
            usernameElement.href =
                'profile.php?id='
                + pilot.user_id;
        }

        document.getElementById('panelAircraft').innerText =
            pilot.aircraft_icao || 'UNKNOWN';

        const divisionElement = document.getElementById('panelDivision');
        const divisionCode = String(pilot.division_code || '').toUpperCase();
        if (divisionCode !== '') {
            divisionElement.innerHTML =
                '<img src="images/flags/' + encodeURIComponent(divisionCode.toLowerCase())
                + '.png" alt="" style="height:20px;max-width:30px;object-fit:cover;vertical-align:-4px;margin-right:6px">'
                + '<a href="division.php?code=' + encodeURIComponent(divisionCode)
                + '" style="color:#1737a6;text-decoration:none;font-weight:bold">'
                + escapeHtml(pilot.division_name || divisionCode) + '</a>';
        } else {
            divisionElement.innerText = '----';
        }

        document.getElementById('panelCategory').innerText =
            pilot.aircraft_category || 'unknown';

        if (SHOW_RATINGS)
        {
            const ratingsElement =
                document.getElementById('panelRatings');

            if (ratingsElement)
            {
                ratingsElement.innerHTML =
                    buildRatingsHtml(pilot);
            }
        }

        document.getElementById('panelFlightRules').innerText =
            formatFlightRules(
                flightplan.flight_rules
            );

        document.getElementById('panelFlightType').innerText =
            formatFlightType(
                flightplan.flight_type
            );

        document.getElementById('panelDepartureTime').innerText =
            flightplan.departure_time || '----';

        document.getElementById('panelAlternate1').innerText =
            getAirportCode(
                flightplan.alternate1_airport
            );

        document.getElementById('panelAlternate2').innerText =
            getAirportCode(
                flightplan.alternate2_airport
            );

        document.getElementById('panelCruisingLevel').innerText =
            flightplan.cruising_level || '----';

        document.getElementById('panelCruisingSpeed').innerText =
            flightplan.cruising_speed || '----';

        document.getElementById('panelRouteText').innerText =
            flightplan.route_text || '----';

        updateFlightProgressPanel(pilot);
        if (
            (flightPlanRouteVisible || flightPlanRouteLoading)
            && flightPlanRouteKey !== selectedFlightPlanRouteKey(pilot)
        ) {
            removeFlightPlanRoute();
        }
        updateFlightPlanRouteButton(pilot);

        document.getElementById('panelRemarks').innerText =
            flightplan.remarks || '----';

        document.getElementById('panelAltitude').innerText =
            Number(pilot.altitude).toFixed(0);

        document.getElementById('panelSpeed').innerText =
            Number(pilot.airspeed).toFixed(0);

        document.getElementById('panelHeading').innerText =
            Number(pilot.heading).toFixed(0) + '°';

        document.getElementById('panelLatitude').innerText =
            Number(pilot.latitude).toFixed(6);

        document.getElementById('panelLongitude').innerText =
            Number(pilot.longitude).toFixed(6);

        document.getElementById('panelPitch').innerText =
            Number(pilot.pitch).toFixed(2);

        document.getElementById('panelRoll').innerText =
            Number(pilot.roll_angle).toFixed(2);

        document.getElementById('panelVerticalSpeed').innerText =
            Number(pilot.vertical_speed).toFixed(0);

        document.getElementById('panelCom1').innerText =
            pilot.com1 || '0.000';

        document.getElementById('panelCom2').innerText =
            pilot.com2 || '0.000';

        document.getElementById('panelCom3').innerText =
            pilot.com3 || '0.000';

        if (isEmergencySquawk(squawk))
        {
            transponderElement.innerText =
                getSquawkEmergencyText(squawk);

            transponderElement.className =
                'panel-row-value panel-squawk-emergency';
        }
        else
        {
            transponderElement.innerText =
                squawk || '0000';

            transponderElement.className =
                'panel-row-value';
        }

        document.getElementById('panelLastUpdate').innerText =
            pilot.last_update || '----';
    }

    let pilotLoadInProgress = false;

    async function loadPilots()
    {
        if (pilotLoadInProgress) {
            return;
        }

        pilotLoadInProgress = true;

        try
        {
            const response =
                await fetch(
                    '/execute/get_pilots.php?time='
                    + Date.now()
                    + '&protection=<?php echo rawurlencode((string)$getPilotsProtection); ?>'
                );

            const data =
                await response.json();

            if (!data.success)
            {
                statusBox.innerHTML =
                    MAP_TEXT.error
                    + ': '
                    + data.message;

                return;
            }

            const activeCallsigns = [];
            latestPilots = ((data.pilots && data.pilots.items) || []).filter(function(pilot) {
                return !hideInvisiblePilots
                    || !hideInvisiblePilots.checked
                    || !pilot.is_invisible;
            });

            airportTrafficData =
                buildAirportTrafficData(
                    latestPilots
                );

            latestPilots.forEach(pilot =>
            {
                const callsign =
                    pilot.callsign;

                const lat =
                    Number(pilot.latitude);

                const lon =
                    Number(pilot.longitude);

                const heading =
                    Number(pilot.heading);

                const category =
                    pilot.aircraft_category || 'unknown';

                if (pilot.is_spectator) {
                    return;
                }

                activeCallsigns.push(callsign);

                if (!pilotMarkers[callsign])
                {
                    const marker =
                        L.marker(
                            [lat, lon],
                            {
                                icon:
                                    createPlaneIcon(
                                        category,
                                        heading
                                    )
                            }
                        ).addTo(map);

                    marker.bindTooltip(
                        createTooltipContent(pilot),
                        {
                            permanent: true,
                            direction: 'top',
                            offset: [0, -25],
                            className: 'pilot-label'
                        }
                    );

                    marker.on(
                        'click',
                        function()
                        {
                            openPilotPanel(pilot);
                        }
                    );

                    pilotMarkers[callsign] =
                        marker;
                }
                else
                {
                    pilotMarkers[callsign].setLatLng(
                        [lat, lon]
                    );

                    pilotMarkers[callsign].setIcon(
                        createPlaneIcon(
                            category,
                            heading
                        )
                    );

                    pilotMarkers[callsign].setTooltipContent(
                        createTooltipContent(pilot)
                    );

                    pilotMarkers[callsign].off('click');

                    pilotMarkers[callsign].on(
                        'click',
                        function()
                        {
                            openPilotPanel(pilot);
                        }
                    );
                }

                if (selectedCallsign === callsign)
                {
                    selectedPilotData = pilot;
                    updatePilotPanel(pilot);

                    loadTrackUpdates(callsign);

                    updateAirportRouteOverlays(pilot);

                    resetMarkerZIndexes();

                    if (pilotMarkers[callsign])
                    {
                        pilotMarkers[callsign].setZIndexOffset(1000);
                    }
                }

                if (followedUserId === Number(pilot.user_id))
                {
                    if (selectedCallsign !== callsign) {
                        openPilotPanel(pilot);
                    }
                    centerOnPilot(pilot, false);
                }
            });

            if (!initialTargetHandled && TARGET_PILOT_ID > 0)
            {
                initialTargetHandled = true;
                const targetPilot = latestPilots.find(pilot =>
                    Number(pilot.user_id) === TARGET_PILOT_ID);
                if (targetPilot) {
                    openPilotPanel(targetPilot);
                    centerOnPilot(targetPilot, true);
                    if (TARGET_FOLLOW) {
                        followedUserId = TARGET_PILOT_ID;
                        updateFollowButton();
                        updateMapUrl(targetPilot);
                    }
                }
            }

            Object.keys(pilotMarkers).forEach(callsign =>
            {
                if (!activeCallsigns.includes(callsign))
                {
                    map.removeLayer(pilotMarkers[callsign]);

                    delete pilotMarkers[callsign];

                    if (pilotTracks[callsign])
                    {
                        map.removeLayer(pilotTracks[callsign]);

                        delete pilotTracks[callsign];
                    }

                    delete pilotTrackLastIds[callsign];
                    delete pilotTrackSegments[callsign];
                    delete pilotTrackLastPoints[callsign];

                    if (selectedCallsign === callsign)
                    {
                        closePilotPanel();
                    }
                }
            });

            updateAirportTrafficMarkers();
            renderPilotDirectory();

            const totalCount =
                Number(data.pilots?.total_count ?? data.pilots?.count ?? 0);

            const invisibleCount =
                Number(data.pilots?.invisible_count ?? 0);

            let statusText =
                MAP_TEXT.active_pilots
                + ': '
                + totalCount
                + '<br>'
                + MAP_TEXT.active_atc
                + ': '
                + activeAtcCount;

            if (invisibleCount > 0)
            {
                statusText +=
                    '<br>'
                    + MAP_TEXT.invisible_pilots
                    + ': '
                    + invisibleCount;
            }

            statusBox.innerHTML =
                statusText
                + '<br>'
                + MAP_TEXT.last_update
                + ': '
                + formatUtcTime();
        }
        catch(error)
        {
            console.error(error);

            statusBox.innerHTML =
                MAP_TEXT.connection_error;
        }
        finally
        {
            pilotLoadInProgress = false;
        }
    }

    loadPilots();
    loadAtcPositions();
    openTargetAirport();

    setInterval(
        loadPilots,
        2000
    );
    setInterval(loadAtcPositions, 5000);
</script>

<?php require_once 'includes/footer.php'; ?>

</body>
</html>
