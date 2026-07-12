<?php

require_once __DIR__ . '/awards_system.php';

function checkFirstFlight(
    PDO $pdo,
    int $userId,
    string $departureAirport,
    string $arrivalAirport
): void {

    if (userHasAward($pdo, $userId, 'award_first_flight')) {
        return;
    }

    if (
        !awardUser(
            $pdo,
            $userId,
            'award_first_flight',
            0
        )
    ) {
        return;
    }

    $activityValue =
        $departureAirport . ' > ' . $arrivalAirport;

    $stmt = $pdo->prepare(
        "SELECT
            id,
            activity_value
         FROM user_activity_log
         WHERE user_id = :user_id
           AND activity_key = 'activity_first_flight'
         LIMIT 1"
    );

    $stmt->execute([
        'user_id' => $userId
    ]);

    $existingActivity =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingActivity) {
        if (
            trim((string)$existingActivity['activity_value']) === ''
            || trim((string)$existingActivity['activity_value']) === 'ZZZZ ? ZZZZ'
            || trim((string)$existingActivity['activity_value']) === 'ZZZZ > ZZZZ'
        ) {
            $updateStmt = $pdo->prepare(
                "UPDATE user_activity_log
                 SET activity_value = :activity_value
                 WHERE id = :id
                 LIMIT 1"
            );

            $updateStmt->execute([
                'activity_value' => $activityValue,
                'id' => (int)$existingActivity['id']
            ]);
        }

        return;
    }

    logActivity(
        $pdo,
        $userId,
        'flight',
        'activity_first_flight',
        $activityValue,
        0
    );
}

function checkLandingAwards(
    PDO $pdo,
    int $userId,
    string $aircraftIcao,
    int $landingRateFpm
): void {

    checkFirstLanding(
        $pdo,
        $userId,
        $aircraftIcao,
        $landingRateFpm
    );

    checkButterLanding(
        $pdo,
        $userId,
        $aircraftIcao,
        $landingRateFpm
    );

    checkHardLanding(
        $pdo,
        $userId,
        $aircraftIcao,
        $landingRateFpm
    );

    checkCrashPilotByLandingRate(
        $pdo,
        $userId,
        $aircraftIcao,
        $landingRateFpm
    );

    checkLandingActivity(
        $pdo,
        $userId,
        $aircraftIcao,
        $landingRateFpm
    );
}

function checkLandingActivity(
    PDO $pdo,
    int $userId,
    string $aircraftIcao,
    int $landingRateFpm
): void {

    if (
        $landingRateFpm >= 1000
        && !userHasAward($pdo, $userId, 'award_crash_pilot')
    ) {
        return;
    }

    if (
        $landingRateFpm >= 600
        && !userHasAward($pdo, $userId, 'award_hard_landing')
    ) {
        return;
    }

    if (
        $landingRateFpm <= 50
        && !userHasAward($pdo, $userId, 'award_butter_landing')
    ) {
        return;
    }

    logActivity(
        $pdo,
        $userId,
        'flight',
        'activity_landing',
        $aircraftIcao . ' > ' . $landingRateFpm . ' fpm',
        0
    );
}

function checkFirstLanding(
    PDO $pdo,
    int $userId,
    string $aircraftIcao,
    int $landingRateFpm
): void {

    $stmt = $pdo->prepare(
        "SELECT total_landings
         FROM users
         WHERE id = :user_id
         LIMIT 1"
    );

    $stmt->execute([
        'user_id' => $userId
    ]);

    $userStats =
        $stmt->fetch(PDO::FETCH_ASSOC);

    $totalLandings =
        (int)($userStats['total_landings'] ?? 0);

    if ($totalLandings !== 1) {
        return;
    }

    logActivity(
        $pdo,
        $userId,
        'flight',
        'activity_first_landing',
        $aircraftIcao . ' > ' . $landingRateFpm . ' fpm',
        0
    );

    awardUser(
        $pdo,
        $userId,
        'award_first_landing',
        0
    );
}


function checkButterLanding(
    PDO $pdo,
    int $userId,
    string $aircraftIcao,
    int $landingRateFpm
): void {

    if ($landingRateFpm < 0 || $landingRateFpm > 50) {
        return;
    }

    if (
        !awardUser(
            $pdo,
            $userId,
            'award_butter_landing',
            0
        )
    ) {
        return;
    }

    logActivity(
        $pdo,
        $userId,
        'flight',
        'activity_butter_landing',
        $aircraftIcao . ' > ' . $landingRateFpm . ' fpm',
        0
    );
}

function checkHardLanding(
    PDO $pdo,
    int $userId,
    string $aircraftIcao,
    int $landingRateFpm
): void {

    if ($landingRateFpm < 600) {
        return;
    }

    if (
        !awardUser(
            $pdo,
            $userId,
            'award_hard_landing',
            0
        )
    ) {
        return;
    }

    logActivity(
        $pdo,
        $userId,
        'flight',
        'activity_hard_landing',
        $aircraftIcao . ' > ' . $landingRateFpm . ' fpm',
        0
    );
}

function checkCrashPilot(
    PDO $pdo,
    int $userId,
    int $hasCrashed
): void {

    if ($hasCrashed !== 1) {
        return;
    }

    if (userHasActivity($pdo, $userId, 'activity_crash_pilot')) {
        return;
    }

    if (!awardUser($pdo, $userId, 'award_crash_pilot', 0)) {
        return;
    }

    logActivity(
        $pdo,
        $userId,
        'warning',
        'activity_crash_pilot',
        'Simulator crash detected',
        0
    );
}

function checkCrashPilotByLandingRate(
    PDO $pdo,
    int $userId,
    string $aircraftIcao,
    int $landingRateFpm
): void {

    if ($landingRateFpm < 1000) {
        return;
    }

    if (userHasActivity($pdo, $userId, 'activity_crash_pilot')) {
        return;
    }

    if (!awardUser($pdo, $userId, 'award_crash_pilot', 0)) {
        return;
    }

    logActivity(
        $pdo,
        $userId,
        'warning',
        'activity_crash_pilot',
        $aircraftIcao . ' · ' . $landingRateFpm . ' fpm',
        0
    );
}

function checkPositionAwards(
    PDO $pdo,
    int $userId,
    float $latitude,
    float $longitude
): void {

    checkFounderHome(
        $pdo,
        $userId,
        $latitude,
        $longitude
    );

}

function checkFounderHome(
    PDO $pdo,
    int $userId,
    float $latitude,
    float $longitude
): void {

    $founderHomeLat = 50.57741266335045;
    $founderHomeLon = 12.696551697981754;

    $distanceNm =
        calculateAwardDistanceNm(
            $latitude,
            $longitude,
            $founderHomeLat,
            $founderHomeLon
        );

    if ($distanceNm > 5.0) {
        return;
    }

    awardUser(
        $pdo,
        $userId,
        'award_founder_home',
        0
    );
}

function calculateAwardDistanceNm(
    float $lat1,
    float $lon1,
    float $lat2,
    float $lon2
): float {

    $earthRadiusKm = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a =
        sin($dLat / 2) * sin($dLat / 2)
        +
        cos(deg2rad($lat1))
        *
        cos(deg2rad($lat2))
        *
        sin($dLon / 2)
        *
        sin($dLon / 2);

    $c =
        2 * atan2(
            sqrt($a),
            sqrt(1 - $a)
        );

    return ($earthRadiusKm * $c) * 0.539957;
}
