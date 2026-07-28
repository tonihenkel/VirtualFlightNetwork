<?php

session_start();

require_once 'execute/config.php';
require_once 'includes/language.php';

if (!isset($projectName) || trim($projectName) === '') {
    $projectName = "Flight Radar Sim Project";
}

$isWebLoggedIn =
    isset($_SESSION['web_user_id']);

$viewerOpPermission = 0;
if ($isWebLoggedIn) {
    try {
        $mapPdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $mapViewerStmt = $mapPdo->prepare(
            "SELECT op_permission FROM users WHERE id = :id LIMIT 1"
        );
        $mapViewerStmt->execute(['id' => (int)$_SESSION['web_user_id']]);
        $viewerOpPermission =
            (int)($mapViewerStmt->fetchColumn() ?: 0);
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
        .pilot-directory-meta { color: #95adc4; font-size: 12px; margin-top: 3px; }
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
        }
        .panel-action.following { background: #159447; }
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
        #airportTrafficPanel {
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
    #pilotInfoPanel, #airportTrafficPanel { top: 122px; width: min(360px, 100%); height: calc(100vh - 122px); }
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
        </div>
        <a class="map-statistics-link" href="statistics.php"><?php echo htmlspecialchars(t('map_open_statistics')); ?></a>
        <?php if ($viewerOpPermission > 1): ?>
            <label class="map-invisible-filter">
                <input id="hideInvisiblePilots" type="checkbox">
                <?php echo htmlspecialchars(t('map_hide_invisible_pilots')); ?>
            </label>
        <?php endif; ?>
        <input id="pilotSearch" type="search" placeholder="<?php echo htmlspecialchars(t('map_search_pilot')); ?>">
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
            <div class="panel-airport-name">
                <?php echo htmlspecialchars(t("map_airport_traffic")); ?>
            </div>
        </div>
    </div>

    <div class="panel-content">

        <button type="button" class="panel-action" id="followPilotButton" onclick="toggleFollowSelectedPilot()">
            <?php echo htmlspecialchars(t('map_follow_pilot')); ?>
        </button>
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
        </div>

        <div id="airportTrafficList" class="airport-traffic-list"></div>
    </div>
</div>

<div id="map"></div>

<?php require_once 'includes/auth_modals.php'; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>

<script>
    const IS_WEB_LOGGED_IN =
        <?php echo $isWebLoggedIn ? 'true' : 'false'; ?>;

    const SHOW_RATINGS =
        <?php echo $showRatings ? 'true' : 'false'; ?>;

    const MAP_TEXT =
        <?php
        echo json_encode(
            [
                "loading_pilots" => t("map_loading_pilots"),
                "active_pilots" => t("map_active_pilots"),
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
                "airport_aircraft" => t("map_airport_aircraft"),
                "airport_departure_time" => t("map_airport_departure_time"),
                "follow_pilot" => t("map_follow_pilot"),
                "stop_following" => t("map_stop_following"),
                "no_pilots_found" => t("map_no_pilots_found")
            ],
            JSON_UNESCAPED_UNICODE
        );
        ?>;
    const TARGET_PILOT_ID =
        <?php echo max(0, (int)($_GET['pilot_id'] ?? 0)); ?>;
    const TARGET_FOLLOW =
        <?php echo !empty($_GET['follow']) ? 'true' : 'false'; ?>;
    const INITIAL_HEATMAP =
        <?php echo !empty($_GET['heatmap']) ? 'true' : 'false'; ?>;
    const map = L.map(
        'map',
        {
            zoomControl: true
        }
    ).setView(
        [51.0, 10.0],
        5
    );

    L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            maxZoom: 22,
            minzoom: 1,
            attribution: '&copy; Virtual Flightnetwork'
        }
    ).addTo(map);

    const statusBox =
        document.getElementById('statusBox');

    const pilotInfoPanel =
        document.getElementById('pilotInfoPanel');

    const airportTrafficPanel =
        document.getElementById('airportTrafficPanel');

    const pilotMarkers = {};

    const pilotTracks = {};

    const pilotTrackLastIds = {};

    const airportRouteLines = {};

    const airportMarkers = {};

    const trafficAirportMarkers = {};

    let airportTrafficData = {};

    let selectedCallsign = null;
    let selectedPilotData = null;
    let followedUserId = TARGET_FOLLOW ? TARGET_PILOT_ID : 0;
    let latestPilots = [];
    const hideInvisiblePilots =
        document.getElementById('hideInvisiblePilots');
    let initialTargetHandled = false;
    let heatmapLayer = null;

    let selectedAirportCode = null;

    let selectedAirportTab = 'inbound';

    function escapeHtml(value)
    {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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

    function renderPilotDirectory()
    {
        const list = document.getElementById('pilotDirectoryList');
        const query = document.getElementById('pilotSearch').value
            .trim().toLocaleLowerCase();
        const pilots = latestPilots.filter(function(pilot) {
            const flightplan = getFlightplan(pilot);
            return [
                pilot.callsign, pilot.username, pilot.aircraft_icao,
                flightplan.departure_airport, flightplan.arrival_airport
            ].join(' ').toLocaleLowerCase().includes(query);
        });
        if (pilots.length === 0) {
            list.innerHTML = '<div class="pilot-directory-meta">'
                + escapeHtml(MAP_TEXT.no_pilots_found) + '</div>';
            return;
        }
        list.innerHTML = pilots.map(function(pilot) {
            const flightplan = getFlightplan(pilot);
            const active = selectedCallsign === pilot.callsign ? ' active' : '';
            const following = followedUserId === Number(pilot.user_id) ? ' · ◉' : '';
            return '<button type="button" class="pilot-directory-item' + active
                + '" data-pilot-user-id="' + Number(pilot.user_id) + '">'
                + '<div class="pilot-directory-callsign">' + escapeHtml(pilot.callsign)
                + escapeHtml(following) + '</div>'
                + '<div class="pilot-directory-meta">' + escapeHtml(pilot.aircraft_icao || '----')
                + ' · ' + escapeHtml(getAirportCode(flightplan.departure_airport))
                + ' → ' + escapeHtml(getAirportCode(flightplan.arrival_airport)) + '</div>'
                + '</button>';
        }).join('');
    }

    document.getElementById('pilotSearch').addEventListener('input', renderPilotDirectory);
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

        const invisibleIcon =
            pilot.is_invisible
            ? '👁 '
            : '';

        if (isEmergencySquawk(squawk))
        {
            return `
                <div class="pilot-label-emergency-box">
                    <div>${invisibleIcon}${pilot.callsign}</div>
                    <div>${pilot.aircraft_icao}</div>
                    <div>${dep} - ${arr}</div>
                </div>
            `;
        }

        return `
            <div class="pilot-label-normal-box">
                <div><b>${invisibleIcon}${pilot.callsign}</b></div>
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
        Object.keys(pilotTracks).forEach(callsign =>
        {
            map.removeLayer(pilotTracks[callsign]);

            delete pilotTracks[callsign];
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
        if (pilotTracks[callsign])
        {
            map.removeLayer(pilotTracks[callsign]);

            delete pilotTracks[callsign];
        }

        pilotTrackLastIds[callsign] = 0;
    }

    function appendTrackPoints(callsign, points)
    {
        const latLngs =
            points
                .map(point => [
                    Number(point.latitude),
                    Number(point.longitude)
                ])
                .filter(point =>
                    !isNaN(point[0]) &&
                    !isNaN(point[1])
                );

        if (latLngs.length === 0)
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
        }

        const existingPoints =
            pilotTracks[callsign].getLatLngs();

        latLngs.forEach(point =>
        {
            const lastExisting =
                existingPoints[
                    existingPoints.length - 1
                ];

            if (
                !lastExisting ||
                lastExisting.lat !== point[0] ||
                lastExisting.lng !== point[1]
            )
            {
                existingPoints.push(
                    L.latLng(
                        point[0],
                        point[1]
                    )
                );
            }
        });

        pilotTracks[callsign].setLatLngs(existingPoints);
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

        renderAirportTrafficList(airport);
    }

    function openAirportTrafficPanel(code)
    {
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

        updateAirportTrafficPanel(code);

        airportTrafficPanel.classList.add('open');
    }

    function closeAirportTrafficPanel()
    {
        selectedAirportCode =
            null;

        airportTrafficPanel.classList.remove('open');
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
            (pilot.is_invisible ? '👁 ' : '') + (pilot.callsign || '----');

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
            latestPilots = (data.pilots || []).filter(function(pilot) {
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

                    if (selectedCallsign === callsign)
                    {
                        closePilotPanel();
                    }
                }
            });

            updateAirportTrafficMarkers();
            renderPilotDirectory();

            const totalCount =
                Number(data.total_count ?? data.count ?? 0);

            const invisibleCount =
                Number(data.invisible_count ?? 0);

            let statusText =
                MAP_TEXT.active_pilots
                + ': '
                + totalCount;

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

    setInterval(
        loadPilots,
        2000
    );
</script>

<?php require_once 'includes/footer.php'; ?>

</body>
</html>
