<?php

session_start();

if (!isset($_SESSION['web_user_id'])) {
    header(
        'Location: index.php?'
        . http_build_query([
            'type' => 'error',
            'message' => 'login_required'
        ])
    );

    exit;
}

require_once 'execute/config.php';
require_once 'includes/language.php';
require_once 'includes/ratings.php';

if (!isset($projectName) || trim($projectName) === '') {
    $projectName = 'Flight Radar Sim Project';
}

if (!isset($showRatings)) {
    $showRatings = true;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatVfnId(int $id): string
{
    return str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function formatFlightTime(int $seconds): string
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    return $hours . ':' . str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . ' h';
}

$profileUserId =
    isset($_GET['id'])
    ? (int)$_GET['id']
    : (int)$_SESSION['web_user_id'];

if ($profileUserId <= 0) {
    $profileUserId =
        (int)$_SESSION['web_user_id'];
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $stmt = $pdo->prepare(
        "SELECT
            id,
            username,
            email,
            real_name,
            country_code,
            division_code,
            created_at,
            last_login,
            rating_pilot,
            rating_atc,
            rating_special,
            total_flight_seconds,
            total_flight_miles,
            total_landings
         FROM users
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute([
        'id' => $profileUserId
    ]);

    $profileUser =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profileUser) {
        header('Location: index.php?type=error&message=user_not_found');
        exit;
    }
} catch (Exception $e) {
    die('Serverfehler: ' . h($e->getMessage()));
}

$countries =
    require 'includes/countries.php';

$countryCode =
    strtoupper(
        trim(
            $profileUser['country_code'] ?? ''
        )
    );

$countryName =
    $countries[$countryCode]
    ?? htmlspecialchars(t('profile_unknown'));

$divisionCode =
    strtoupper(
        trim(
            $profileUser['division_code'] ?? ''
        )
    );

$divisionName =
    htmlspecialchars(t('profile_unknown_divison'));

$divisionStmt = $pdo->prepare(
    "SELECT
        name
     FROM divisions
     WHERE code = :code
     LIMIT 1"
);

$divisionStmt->execute([
    'code' => $divisionCode
]);

$division =
    $divisionStmt->fetch(PDO::FETCH_ASSOC);

if ($division) {
    $divisionName =
        $division['name'];
}

$favouriteAircraft = '----';

$favouriteAircraftStmt = $pdo->prepare(
    "SELECT aircraft_icao
     FROM pilot_aircraft_stats
     WHERE user_id = :user_id
     ORDER BY total_seconds DESC
     LIMIT 1"
);

$lastFlight = null;

$lastFlightStmt = $pdo->prepare(
    "SELECT
        aircraft_icao,
        landing_rate_fpm,
        created_at
     FROM pilot_landings
     WHERE user_id = :user_id
     ORDER BY created_at DESC
     LIMIT 1"
);

$lastFlightStmt->execute([
    'user_id' => $profileUserId
]);

$lastFlight =
    $lastFlightStmt->fetch(PDO::FETCH_ASSOC);

$totalLandings =
    (int)($profileUser['total_landings'] ?? 0);

$favouriteAircraftStmt->execute([
    'user_id' => $profileUserId
]);

$favouriteAircraftData =
    $favouriteAircraftStmt->fetch(PDO::FETCH_ASSOC);

if ($favouriteAircraftData) {

    $favouriteAircraft =
        $favouriteAircraftData['aircraft_icao'];

}

$onlineStmt = $pdo->prepare(
    "SELECT id
     FROM user_sessions
     WHERE user_id = :user_id
        AND is_active = 1
        AND last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
     LIMIT 1"
);

$onlineStmt->execute([
    'user_id' => $profileUserId
]);

$isNetworkOnline =
    $onlineStmt->fetch(PDO::FETCH_ASSOC)
    ? true
    : false;

$displayName =
    $profileUser['real_name']
    ?: $profileUser['username'];

$vfnId =
    formatVfnId((int)$profileUser['id']);

$pilotRatingValue =
    (int)($profileUser['rating_pilot'] ?? 0);

$atcRatingValue =
    (int)($profileUser['rating_atc'] ?? 0);

$specialRatingValue =
    (int)($profileUser['rating_special'] ?? 0);

$pilotRating =
    getPilotRating($pilotRatingValue);

$atcRating =
    getAtcRating($atcRatingValue);

$specialRating =
    getSpecialRating($specialRatingValue);

$totalFlightSeconds =
    (int)($profileUser['total_flight_seconds'] ?? 0);

$totalFlightMiles =
    (float)($profileUser['total_flight_miles'] ?? 0);

$memberSince =
    !empty($profileUser['created_at'])
    ? date('d.m.Y', strtotime($profileUser['created_at']))
    : '----';

$activeTab =
    $_GET['a'] ?? 'overview';

$allowedTabs = [
    'overview',
    'activity',
    'awards'
];

if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}

$profileBaseUrl =
    'profile.php?id='
    . (int)$profileUserId
    . '&lang='
    . urlencode($currentLanguage);

;

$awardImages = [

    // Flight
    'award_first_flight'         => 'images/awards/first_flight.png',
    'award_first_landing'        => 'images/awards/first_landing.png',
    'award_crash_pilot'          => 'images/awards/crash_pilot.png',
    'award_hard_landing'         => 'images/awards/hard_landing.png',
    'award_butter_landing'       => 'images/awards/butter_landing.png',

    // Distance / World
    'award_world_traveler'       => 'images/awards/world_traveler.png',
    'award_global_explorer'      => 'images/awards/global_explorer.png',
    'award_international_ace'    => 'images/awards/international_ace.png',
    'award_globe_master'         => 'images/awards/globe_master.png',

    // Night
    'award_night_owl'            => 'images/awards/night_owl.png',
    'award_moon_walker'          => 'images/awards/moon_walker.png',
    'award_master_of_night'      => 'images/awards/master_of_night.png',

    'award_founder_home'         => 'images/awards/founder_home.png',

];


?>
<!DOCTYPE html>
<html lang="<?php echo h($currentLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(t('profile_title')); ?> - <?php echo h($projectName); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">


    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            color: #ffffff;
            background:
                radial-gradient(circle at top left, rgba(23,55,166,0.25), transparent 35%),
                radial-gradient(circle at top right, rgba(0,255,204,0.10), transparent 28%),
                #06101d;
        }

        .profile-shell {
            width: 100%;
            padding: 22px 30px 45px 30px;
        }

        .breadcrumb {
            max-width: 1500px;
            margin: 0 auto 18px auto;
            color: #7fa8dd;
            font-size: 13px;
        }

        .breadcrumb span { color: #d7e8ff; }

        .profile-layout {
            max-width: 1500px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 22px;
        }

        .profile-sidebar,
        .card {
            background: rgba(3,12,24,0.74);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .profile-sidebar {
            padding: 14px;
            min-height: 650px;
        }

        .side-link {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 3px 14px;
            margin-bottom: 6px;
            border-radius: 8px;
            color: #d7e8ff;
            text-decoration: none;
            font-size: 15px;
        }

        .side-link.active {
            background: linear-gradient(135deg, #1737a6, #244ecb);
            color: white;
        }

        .side-link:hover { background: rgba(255,255,255,0.07); }

        .network-status {
            margin-top: 48px;
            padding: 14px;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 9px;
            background: rgba(255,255,255,0.03);
            color: #b7c8df;
            font-size: 14px;
        }

        .network-status-title {
            text-transform: uppercase;
            color: #90a9c9;
            font-size: 12px;
            margin-bottom: 10px;
        }

        .online-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            background: #36c64b;
            border-radius: 50%;
            margin-right: 8px;
        }

        .hero-card {
            padding: 22px;
            display: grid;
            grid-template-columns: 560px 1fr;
            gap: 20px;
            align-items: center;
            margin-bottom: 14px;
        }

        .user-hero {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 24px;
            align-items: center;
        }

        .avatar-wrap {
            position: relative;
            width: 150px;
            height: 150px;
        }

        .avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.9);
            background: linear-gradient(135deg, #ffae4a, #16385c);
            box-shadow: 0 10px 35px rgba(0,0,0,0.4);
        }

        .avatar-online {
            position: absolute;
            right: 10px;
            bottom: 14px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #36c64b;
            border: 3px solid #06101d;
        }

        .profile-name {
            font-size: 30px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .status-badge {
            display: inline-block;
            margin-left: 8px;
            padding: 4px 8px;
            border-radius: 5px;
            background: #1737a6;
            color: #cfe4ff;
            font-size: 12px;
            vertical-align: middle;
        }

        .profile-meta {
            color: #c9d7ea;
            line-height: 1.9;
            font-size: 15px;
        }

        .profile-country-flag {
            width: 22px;
            height: 15px;
            object-fit: cover;
            border-radius: 2px;
            vertical-align: middle;
            margin-right: 6px;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.25);
        }

        .rating-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .rating-summary-item {
            text-align: center;
            padding: 8px 10px;
            border-left: 1px solid rgba(255,255,255,0.08);
        }

        .rating-summary-title {
            font-size: 12px;
            text-transform: uppercase;
            color: #d7e8ff;
            letter-spacing: 0.6px;
            margin-bottom: 8px;
        }

        .rating-summary-img {
            height: 175px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            display: block;
            margin: 0 auto 6px auto;
        }

        .rating-summary-name {
            color: white;
            font-size: 13px;
            line-height: 1.25;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .content-grid-bottom {
            display: grid;
            grid-template-columns: 1.1fr 1.55fr 1.25fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .card-title {
            padding: 18px 20px 8px 20px;
            font-size: 17px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .card-body { padding: 12px 20px 20px 20px; }

        .stats-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .stats-section-title {
            color: #4aa3ff;
            text-transform: uppercase;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .stats-section-title.atc { color: #51d26b; }

        .stat-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: #c9d7ea;
            font-size: 14px;
            padding: 6px 0;
        }

        .stat-row strong { color: white; }

        .rating-track {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow-x: auto;
            padding: 6px 0 16px 0;
        }

        .track-rating {
            min-width: 58px;
            text-align: center;
            color: #c9d7ea;
            font-size: 12px;
        }

        .track-rating img {
            height: 48px;
            width: auto;
            display: block;
            margin: 0 auto 4px auto;
            opacity: 0.92;
        }

        .track-rating.locked {
            opacity: 0.28;
            filter: grayscale(1);
        }

        .track-arrow {
            color: #7893b8;
            font-size: 18px;
        }

        .current-rating-box {
            margin-top: 10px;
            padding: 13px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            display: grid;
            grid-template-columns: 82px 1fr;
            gap: 14px;
            align-items: center;
        }

        .current-rating-box img {
            height: 72px;
            width: auto;
            display: block;
            margin: auto;
        }

        .current-rating-title {
            font-size: 15px;
            color: white;
            margin-bottom: 6px;
        }

        .current-rating-meta {
            color: #9fb3cf;
            font-size: 13px;
            line-height: 1.5;
        }

        .activity-list { display: grid; gap: 11px; }

        .activity-row {
            display: grid;
            grid-template-columns: 42px 1fr auto;
            gap: 10px;
            align-items: center;
            color: #c9d7ea;
            font-size: 13px;
        }

        .activity-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1737a6;
            font-size: 19px;
        }

        .activity-main strong {
            color: white;
            display: block;
            font-size: 14px;
        }

        .activity-time {
            text-align: right;
            color: #9fb3cf;
            white-space: nowrap;
        }

        .awards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            text-align: center;
        }

        .award-icon {
            width: 62px;
            height: 62px;
            margin: 0 auto 8px auto;
            border-radius: 50%;
            background: radial-gradient(circle, #ffc84a, #9b6412);
            border: 2px solid rgba(255,255,255,0.32);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2d1700;
            font-weight: bold;
            font-size: 20px;
        }

        .award-title {
            color: white;
            font-size: 12px;
            line-height: 1.3;
        }

        .training-card {
            padding: 18px 20px;
            display: grid;
            grid-template-columns: 1.2fr 2fr;
            gap: 22px;
            align-items: center;
        }

        .training-empty {
            display: flex;
            align-items: center;
            gap: 14px;
            color: #c9d7ea;
        }

        .training-icon {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.24);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .role-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }

        .role-item {
            color: #c9d7ea;
            font-size: 13px;
        }

        .role-item strong {
            color: white;
            display: block;
            margin-bottom: 4px;
        }

        @media (max-width: 1150px) {
            .profile-layout,
            .hero-card,
            .profile-row-three,
            .profile-row-full,
            .training-card {
                grid-template-columns: 1fr;
            }

            .profile-sidebar { min-height: auto; }
        }

        @media (max-width: 700px) {
            .profile-shell { padding: 16px; }
            .user-hero { grid-template-columns: 1fr; text-align: center; }
            .avatar-wrap { margin: 0 auto; }
            .rating-summary,
            .stats-columns,
            .awards,
            .role-grid { grid-template-columns: 1fr; }
        }

        .status-badge.offline {
            background: #555;
            color: #ddd;
        }

        .avatar-online.offline {
            background: #777;
        }

        .online-dot.offline {
            background: #777;
        }

        .full-width-row {
            margin-bottom: 14px;
        }

        .award-item {
            text-align: center;
            color: white;
            font-size: 12px;
        }

        .award-image {
            width: 140px;
            height: 140px;
            object-fit: contain;
            display: block;
            margin: 0 auto 8px auto;
        }

        .awards-footer {
            grid-column: 1 / -1;
            margin-top: 15px;
            text-align: center;
            width: 100%;
        }

        .awards-footer a {
            display: inline-block;
            color: #6ea8ff;
            text-decoration: none;
            white-space: nowrap;
        }

        .awards-footer a:hover {
            text-decoration: underline;
        }

        .awards-full {
            grid-template-columns: repeat(6, 1fr);
        }

        .award-date {
            color: #9fb3cf;
            font-size: 11px;
            margin-top: 4px;
        }


    </style>
</head>
<body>

<?php require_once 'includes/header.php'; ?>

<main class="profile-shell">

    <div class="breadcrumb">
        <?php echo htmlspecialchars(t('profile_task_home')); ?> &nbsp;›&nbsp; <?php echo htmlspecialchars(t('profile_title')); ?> &nbsp;›&nbsp;
        <span><?php echo h(strtoupper($displayName)); ?></span>
    </div>

    <div class="profile-layout">

        <aside class="profile-sidebar">

            <a
                class="side-link <?php echo $activeTab === 'overview' ? 'active' : ''; ?>"
                href="<?php echo $profileBaseUrl; ?>&a=overview">
                👤 <?php echo htmlspecialchars(t('profile_overview')); ?>
            </a>

            <a
                class="side-link <?php echo $activeTab === 'activity' ? 'active' : ''; ?>"
                href="<?php echo $profileBaseUrl; ?>&a=activity">
                🕘 <?php echo htmlspecialchars(t('profile_activities')); ?>

                <?php if ($unreadActivityCount > 0): ?>
                    <span class="activity-notification-dot"></span>
                <?php endif; ?>
            </a>

            <a
                class="side-link <?php echo $activeTab === 'awards' ? 'active' : ''; ?>"
                href="<?php echo $profileBaseUrl; ?>&a=awards">
                🏆 <?php echo htmlspecialchars(t('profile_awards')); ?>
            </a>

            <!--
            <a class="side-link" href="#">✈ <?php echo htmlspecialchars(t('profile_pilot')); ?></a>
            <a class="side-link" href="#">🗼 <?php echo htmlspecialchars(t('profile_atc')); ?></a>
            <a class="side-link" href="#">🛡 <?php echo htmlspecialchars(t('profile_ratings_badges')); ?></a>
            <a class="side-link" href="#">⚙ <?php echo htmlspecialchars(t('profile_settings')); ?></a>
            -->
            <?php
                $pilotCountStmt = $pdo->query(
                    "SELECT COUNT(*)
                     FROM pilot_positions
                     WHERE last_update >= DATE_SUB(NOW(), INTERVAL 10 SECOND)"
                );

                $totalPilotsOnline =  (int)$pilotCountStmt->fetchColumn();
            ?>
            <div class="network-status">
                <div class="network-status-title"><?php echo htmlspecialchars(t('profile_network_status')); ?></div>
                <div>
                    <span class="online-dot <?php echo $isNetworkOnline ? '' : 'offline'; ?>"></span>
                    <?php echo $isNetworkOnline ? htmlspecialchars(t('profile_online')) : htmlspecialchars(t('profile_offline')); ?>
                </div>
                <p>
                    <?php echo $totalPilotsOnline; ?>
                    <?php echo htmlspecialchars(t('profile_pilots_online')); ?>
                </p>
                <p>
                    0
                    <?php echo htmlspecialchars(t('profile_controllers_online')); ?>
                </p>
            </div>
        </aside>


        <section class="profile-main">
            <?php

                switch ($activeTab) {

                    case 'activity':
                        require_once 'includes/profile_activity.php';
                        break;

                    case 'overview':
                    default:
                        require_once 'includes/profile_overview.php';
                        break;

                    case 'awards':
                        require_once 'includes/profile_awards.php';
                        break;
                }
            ?>
        </section>

    </div>
</main>

<?php require_once 'includes/auth_modals.php'; ?>

</body>
</html>
