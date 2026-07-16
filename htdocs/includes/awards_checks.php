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
    int $landingRateFpm,
    ?float $fuelRemainingPercent = null,
    ?string $arrivalAirport = null
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

    checkFuelGambler(
        $pdo,
        $userId,
        $aircraftIcao,
        $fuelRemainingPercent
    );

    checkWorldTravelerAwards(
        $pdo,
        $userId,
        $arrivalAirport
    );

    checkLandingActivity(
        $pdo,
        $userId,
        $aircraftIcao,
        $landingRateFpm
    );
}

function checkWorldTravelerAwards(
    PDO $pdo,
    int $userId,
    ?string $arrivalAirport
): void {

    $airportIcao =
        strtoupper(trim((string)$arrivalAirport));

    if ($airportIcao === '' || $airportIcao === 'ZZZZ') {
        return;
    }

    $countryCode =
        getAirportCountryCode(
            $pdo,
            $airportIcao
        );

    if ($countryCode === null) {
        return;
    }

    $insertStmt = $pdo->prepare(
        "INSERT IGNORE INTO user_visited_countries
        (
            user_id,
            country_code,
            first_airport_icao
        )
        VALUES
        (
            :user_id,
            :country_code,
            :first_airport_icao
        )"
    );

    $insertStmt->execute([
        'user_id' =>
            $userId,

        'country_code' =>
            $countryCode,

        'first_airport_icao' =>
            $airportIcao
    ]);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM user_visited_countries
         WHERE user_id = :user_id"
    );

    $countStmt->execute([
        'user_id' => $userId
    ]);

    $visitedCountryCount =
        (int)$countStmt->fetchColumn();

    awardCountryTravelRanks(
        $pdo,
        $userId,
        $visitedCountryCount
    );
}

function getAirportCountryCode(
    PDO $pdo,
    string $airportIcao
): ?string {

    $stmt = $pdo->prepare(
        "SELECT iso_country
         FROM airports
         WHERE ident = :airport_icao
            OR icao_code = :airport_icao
            OR gps_code = :airport_icao
         LIMIT 1"
    );

    $stmt->execute([
        'airport_icao' => $airportIcao
    ]);

    $countryCode =
        strtoupper(trim((string)$stmt->fetchColumn()));

    if ($countryCode === '') {
        return null;
    }

    return $countryCode;
}

function awardCountryTravelRanks(
    PDO $pdo,
    int $userId,
    int $visitedCountryCount
): void {

    $awardThresholds = [
        10 =>
            'award_world_traveler',

        25 =>
            'award_global_explorer',

        50 =>
            'award_international_ace',

        100 =>
            'award_globe_master'
    ];

    foreach ($awardThresholds as $requiredCountries => $awardKey) {
        if ($visitedCountryCount < $requiredCountries) {
            continue;
        }

        awardUser(
            $pdo,
            $userId,
            $awardKey,
            0
        );
    }
}

function checkFuelGambler(
    PDO $pdo,
    int $userId,
    string $aircraftIcao,
    ?float $fuelRemainingPercent
): void {

    if ($fuelRemainingPercent === null || $fuelRemainingPercent < 0) {
        return;
    }

    if ($fuelRemainingPercent >= 5.0) {
        return;
    }

    if (
        !awardUser(
            $pdo,
            $userId,
            'award_fuel_gambler',
            0
        )
    ) {
        return;
    }

    logActivity(
        $pdo,
        $userId,
        'flight',
        'activity_fuel_gambler',
        $aircraftIcao . ' > ' . number_format($fuelRemainingPercent, 1, '.', '') . '% fuel',
        0
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
