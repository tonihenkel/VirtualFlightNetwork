<?php

function getPilotRating(int $rating): array
{
    $ratings = [
        0 => [
            'code' => 'FC0',
            'name' => 'New Flight Cadet',
            'image' => 'images/ratings/pilots/fc0.png'
        ],
        1 => [
            'code' => 'FC1',
            'name' => 'Flight Cadet',
            'image' => 'images/ratings/pilots/fc1.png'
        ],
        2 => [
            'code' => 'FC2',
            'name' => 'Junior Aviator',
            'image' => 'images/ratings/pilots/fc2.png'
        ],
        3 => [
            'code' => 'FC3',
            'name' => 'Advanced Aviator',
            'image' => 'images/ratings/pilots/fc3.png'
        ],
        4 => [
            'code' => 'PA',
            'name' => 'Private Aviator',
            'image' => 'images/ratings/pilots/fc4.png'
        ],
        5 => [
            'code' => 'SA',
            'name' => 'Senior Aviator',
            'image' => 'images/ratings/pilots/fc5.png'
        ],
        6 => [
            'code' => 'CA',
            'name' => 'Commercial Aviator',
            'image' => 'images/ratings/pilots/fc6.png'
        ],
        7 => [
            'code' => 'AC',
            'name' => 'Airline Captain',
            'image' => 'images/ratings/pilots/fc7.png'
        ],
        8 => [
            'code' => 'FI',
            'name' => 'Flight Instructor',
            'image' => 'images/ratings/pilots/fc8.png'
        ],
        9 => [
            'code' => 'CI',
            'name' => 'Chief Instructor',
            'image' => 'images/ratings/pilots/fc9.png'
        ]
    ];

    return $ratings[$rating] ?? $ratings[0];
}

function getAtcRating(int $rating): array
{
    $ratings = [
        0 => [
            'code' => 'TC0',
            'name' => 'New ATC Cadet',
            'image' => 'images/ratings/atcs/tc0.png'
        ],
        1 => [
            'code' => 'TC1',
            'name' => 'ATC Cadet',
            'image' => 'images/ratings/atcs/tc1.png'
        ],
        2 => [
            'code' => 'TC2',
            'name' => 'Advanced ATC Cadet',
            'image' => 'images/ratings/atcs/tc2.png'
        ],
        3 => [
            'code' => 'TWR',
            'name' => 'Tower Controller',
            'image' => 'images/ratings/atcs/tc3.png'
        ],
        4 => [
            'code' => 'PAT',
            'name' => 'Approach Trainee',
            'image' => 'images/ratings/atcs/tc4.png'
        ],
        5 => [
            'code' => 'APC',
            'name' => 'Approach Controller',
            'image' => 'images/ratings/atcs/tc5.png'
        ],
        6 => [
            'code' => 'RAT',
            'name' => 'Radar Trainee',
            'image' => 'images/ratings/atcs/tc6.png'
        ],
        7 => [
            'code' => 'RAD',
            'name' => 'Radar Controller',
            'image' => 'images/ratings/atcs/tc7.png'
        ],
        8 => [
            'code' => 'SRC',
            'name' => 'Senior Radar Controller',
            'image' => 'images/ratings/atcs/tc8.png'
        ],
        9 => [
            'code' => 'ARC',
            'name' => 'Chief ATC Instructor',
            'image' => 'images/ratings/atcs/tc9.png'
        ]
    ];

    return $ratings[$rating] ?? $ratings[0];
}

function getSpecialRating(int $rating): ?array
{
    $ratings = [
        1 => [
            'code' => 'OO',
            'name' => 'VFN Operations Officer',
            'image' => 'images/ratings/specials/staff1.png'
        ],
        2 => [
            'code' => 'SOO',
            'name' => 'VFN Senior Operations Officer',
            'image' => 'images/ratings/specials/staff2.png'
        ],
        3 => [
            'code' => 'OD',
            'name' => 'VFN Operations Director',
            'image' => 'images/ratings/specials/staff3.png'
        ],
        4 => [
            'code' => 'DND',
            'name' => 'VFN Deputy Network Director',
            'image' => 'images/ratings/specials/staff4.png'
        ],
        5 => [
            'code' => 'ND',
            'name' => 'Virtual Flight Network Director',
            'image' => 'images/ratings/specials/staff5.png'
        ]
    ];

    return $ratings[$rating] ?? null;
}

function getUserRatings(
    int $pilotRating,
    int $atcRating,
    int $specialRating = 0
): array {
    $ratings = [
        'pilot' => getPilotRating($pilotRating),
        'atc' => getAtcRating($atcRating)
    ];

    $special =
        getSpecialRating($specialRating);

    if ($special !== null) {
        $ratings['special'] =
            $special;
    }

    return $ratings;
}