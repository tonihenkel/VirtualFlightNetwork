<?php

require_once __DIR__ . '/activity_log.php';

function awardUser(
    PDO $pdo,
    int $userId,
    string $awardKey,
    int $createdBy = 0
): bool {

    if (userHasAward($pdo, $userId, $awardKey)) {
        return false;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO user_awards
         (
            user_id,
            award_key,
            awarded_by
         )
         VALUES
         (
            :user_id,
            :award_key,
            :awarded_by
         )"
    );

    $stmt->execute([
        'user_id' => $userId,
        'award_key' => $awardKey,
        'awarded_by' => $createdBy
    ]);

    return true;
}

function userHasAward(
    PDO $pdo,
    int $userId,
    string $awardKey
): bool {

    $stmt = $pdo->prepare(
        "SELECT id
         FROM user_awards
         WHERE user_id = :user_id
           AND award_key = :award_key
         LIMIT 1"
    );

    $stmt->execute([
        'user_id' => $userId,
        'award_key' => $awardKey
    ]);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}