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
            total_flight_miles
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
    ?? 'Unknown';

$divisionCode =
    strtoupper(
        trim(
            $profileUser['division_code'] ?? ''
        )
    );

$divisionName =
    'Unknown Division';

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

?>
<!DOCTYPE html>
<html lang="<?php echo h($currentLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(t('profile_title')); ?> - <?php echo h($projectName); ?></title>
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
            padding: 13px 14px;
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
            grid-template-columns: 1.05fr 1.9fr;
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
            .content-grid,
            .content-grid-bottom,
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
    </style>
</head>
<body>

<?php require_once 'includes/header.php'; ?>

<main class="profile-shell">

    <div class="breadcrumb">
        HOME &nbsp;›&nbsp; <?php echo htmlspecialchars(t('profile_title')); ?> &nbsp;›&nbsp;
        <span><?php echo h(strtoupper($displayName)); ?></span>
    </div>

    <div class="profile-layout">

        <aside class="profile-sidebar">
            <a class="side-link active" href="#">👤 <?php echo htmlspecialchars(t('profile_overview')); ?></a>
            <a class="side-link" href="#">✈ <?php echo htmlspecialchars(t('profile_pilot')); ?></a>
            <a class="side-link" href="#">🗼 <?php echo htmlspecialchars(t('profile_atc')); ?></a>
            <a class="side-link" href="#">🛡 <?php echo htmlspecialchars(t('profile_ratings_badges')); ?></a>
            <a class="side-link" href="#">🏆 <?php echo htmlspecialchars(t('profile_awards')); ?></a>
            <a class="side-link" href="#">🕘 <?php echo htmlspecialchars(t('profile_activities')); ?></a>
            <a class="side-link" href="#">⚙ <?php echo htmlspecialchars(t('profile_settings')); ?></a>
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
                    <?php echo $isNetworkOnline ? 'ONLINE' : 'OFFLINE'; ?>
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

            <div class="card hero-card">
                <div class="user-hero">
                    <div class="avatar-wrap">
                        <div class="avatar"></div>
                        <div class="avatar-online <?php echo $isNetworkOnline ? '' : 'offline'; ?>"></div>
                    </div>

                    <div>
                        <div class="profile-name">
                            <?php echo h($displayName); ?>
                            <span class="status-badge <?php echo $isNetworkOnline ? '' : 'offline'; ?>">
                                <?php echo $isNetworkOnline ? 'ONLINE' : 'OFFLINE'; ?>
                            </span>
                        </div>

                        <div class="profile-meta">
                            VFN-ID: <?php echo h($vfnId); ?><br>
                            <?php echo htmlspecialchars(t('profile_member_since')); ?>: <?php echo h($memberSince); ?><br>
                            <img
                                src="images/flags/<?php echo strtolower($countryCode); ?>.png"
                                class="profile-country-flag"
                                alt="">

                            <?php echo h($countryName); ?><br>

                            <img
                                src="images/flags/<?php echo strtolower($divisionCode); ?>.png"
                                class="profile-country-flag"
                                alt="">

                            <?php echo h($divisionName); ?>
                        </div>
                    </div>
                </div>

                <?php if ($showRatings): ?>
                    <div class="rating-summary">
                        <div class="rating-summary-item">
                            <div class="rating-summary-title">ATC Rating</div>
                            <img class="rating-summary-img" src="<?php echo h($atcRating['image']); ?>" alt="<?php echo h($atcRating['code']); ?>">
                            <div class="rating-summary-name"><?php echo h($atcRating['name']); ?></div>
                        </div>

                        <div class="rating-summary-item">
                            <div class="rating-summary-title">Pilot Rating</div>
                            <img class="rating-summary-img" src="<?php echo h($pilotRating['image']); ?>" alt="<?php echo h($pilotRating['code']); ?>">
                            <div class="rating-summary-name"><?php echo h($pilotRating['name']); ?></div>
                        </div>

                        <?php if ($specialRating): ?>
                            <div class="rating-summary-item">
                                <div class="rating-summary-title"><?php echo htmlspecialchars(t('profile_special_rank')); ?></div>
                                    <img class="rating-summary-img" src="<?php echo h($specialRating['image']); ?>" alt="<?php echo h($specialRating['code']); ?>">
                                    <div class="rating-summary-name"><?php echo h($specialRating['name']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-grid">
                <div class="card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_statistics')); ?></div>
                    <div class="card-body">
                        <div class="stats-columns">
                            <div>
                                <div class="stats-section-title">Pilot</div>
                                <div class="stat-row"><span>✈ <?php echo htmlspecialchars(t('profile_flight_hours')); ?></span><strong><?php echo h(formatFlightTime($totalFlightSeconds)); ?></strong></div>
                                <div class="stat-row"><span>↗ <?php echo htmlspecialchars(t('profile_distance_flown')); ?></span><strong><?php echo h(number_format($totalFlightMiles, 1, ',', '.')); ?> NM</strong></div>
                                <div class="stat-row"><span>🛬 <?php echo htmlspecialchars(t('profile_landings')); ?></span><strong>----</strong></div>
                                <div class="stat-row">
                                    <span>🛧 <?php echo htmlspecialchars(t('profile_favourite_aircraft')); ?></span>
                                    <strong><?php echo h($favouriteAircraft); ?></strong>
                                </div>
                            </div>

                            <div>
                                <div class="stats-section-title atc">ATC</div>
                                <div class="stat-row"><span>🗼 <?php echo htmlspecialchars(t('profile_controller_hours')); ?></span><strong>----</strong></div>
                                <div class="stat-row"><span>📋 <?php echo htmlspecialchars(t('profile_atc_sessions')); ?></span><strong>----</strong></div>
                                <div class="stat-row"><span>📍 <?php echo htmlspecialchars(t('profile_favorite_position')); ?></span><strong>----</strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">ATC Rating <?php echo htmlspecialchars(t('profile_progress')); ?></div>
                    <div class="card-body">
                        <div class="rating-track">
                            <?php for ($i = 0; $i <= 9; $i++): ?>
                                <?php $rating = getAtcRating($i); ?>
                                <div class="track-rating <?php echo $i > $atcRatingValue ? 'locked' : ''; ?>">
                                    <img src="<?php echo h($rating['image']); ?>" title="<?php echo h($rating['code'] . ' - ' . $rating['name']); ?>">
                                    <?php echo h($rating['code']); ?>
                                </div>
                                <?php if ($i < 9): ?><div class="track-arrow">→</div><?php endif; ?>
                            <?php endfor; ?>
                        </div>

                        <div class="current-rating-box">
                            <img src="<?php echo h($atcRating['image']); ?>">
                            <div>
                                <div class="current-rating-title"><?php echo htmlspecialchars(t('profile_current_rating')); ?>: <?php echo h($atcRating['name']); ?></div>
                                <div class="current-rating-meta"><?php echo htmlspecialchars(t('profile_checked_by')); ?>: VFN Staff ✅</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-grid-bottom">
                <div class="card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_latest_activities')); ?></div>
                    <div class="card-body">
                        <div class="activity-list">
                            <div class="activity-row">
                                <div class="activity-icon">✈</div>
                                <div class="activity-main"><strong><?php echo htmlspecialchars(t('profile_last_flight')); ?></strong><?php echo htmlspecialchars(t('profile_no_data')); ?></div>
                                <div class="activity-time">----</div>
                            </div>
                            <div class="activity-row">
                                <div class="activity-icon">🏆</div>
                                <div class="activity-main"><strong>Rating Update</strong><?php echo h($pilotRating['code'] . ' / ' . $atcRating['code']); ?></div>
                                <div class="activity-time">----</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">Pilot Rating <?php echo htmlspecialchars(t('profile_progress')); ?></div>
                    <div class="card-body">
                        <div class="rating-track">
                            <?php for ($i = 0; $i <= 9; $i++): ?>
                                <?php $rating = getPilotRating($i); ?>
                                <div class="track-rating <?php echo $i > $pilotRatingValue ? 'locked' : ''; ?>">
                                    <img src="<?php echo h($rating['image']); ?>" title="<?php echo h($rating['code'] . ' - ' . $rating['name']); ?>">
                                    <?php echo h($rating['code']); ?>
                                </div>
                                <?php if ($i < 9): ?><div class="track-arrow">→</div><?php endif; ?>
                            <?php endfor; ?>
                        </div>

                        <div class="current-rating-box">
                            <img src="<?php echo h($pilotRating['image']); ?>">
                            <div>
                                <div class="current-rating-title"><?php echo htmlspecialchars(t('profile_current_rating')); ?>: <?php echo h($pilotRating['name']); ?></div>
                                <div class="current-rating-meta"><?php echo htmlspecialchars(t('profile_checked_by')); ?>: VFN Staff ✅</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_awards')); ?></div>
                    <div class="card-body">
                        <div class="awards">
                            <div><div class="award-icon">100</div><div class="award-title">100 <?php echo htmlspecialchars(t('profile_flight_hours')); ?></div></div>
                            <div><div class="award-icon">🎓</div><div class="award-title"><?php echo htmlspecialchars(t('profile_first_exam')); ?></div></div>
                            <div><div class="award-icon">✈</div><div class="award-title">Event</div></div>
                            <div><div class="award-icon">VFN</div><div class="award-title">Member</div></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card training-card">
                <div class="training-empty">
                    <div class="training-icon">☑</div>
                    <div>
                        <strong><?php echo htmlspecialchars(t('profile_no_active_training')); ?></strong><br>
                        <span><?php echo htmlspecialchars(t('profile_no_training_text')); ?></span>
                    </div>
                </div>

                <div class="role-grid">
                    <div class="role-item"><strong>Mentor</strong>----</div>
                    <div class="role-item"><strong><?php echo htmlspecialchars(t('profile_examiner')); ?></strong>----</div>
                    <div class="role-item"><strong>Division</strong><?php echo h($divisionName); ?></div>
                    <div class="role-item"><strong>Staff Rolle</strong><?php echo $specialRating ? h($specialRating['name']) : '----'; ?></div>
                </div>
            </div>

        </section>
    </div>
</main>

<?php require_once 'includes/auth_modals.php'; ?>

</body>
</html>
