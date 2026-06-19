<?php

require_once __DIR__ . '/activity_log.php';

function awardUser(
    PDO $pdo,
    int $userId,
    string $awardKey,
    int $actorUserId = 0
): bool {

    $checkStmt = $pdo->prepare(
        "SELECT id
         FROM user_awards
         WHERE user_id = :user_id
           AND award_key = :award_key
         LIMIT 1"
    );

    $checkStmt->execute([
        'user_id' => $userId,
        'award_key' => $awardKey
    ]);

    if ($checkStmt->fetch()) {
        return false;
    }

    $insertStmt = $pdo->prepare(
        "INSERT INTO user_awards
        (
            user_id,
            award_key
        )
        VALUES
        (
            :user_id,
            :award_key
        )"
    );

    $insertStmt->execute([
        'user_id' => $userId,
        'award_key' => $awardKey
    ]);

    logActivity(
        $pdo,
        $userId,
        'award',
        'activity_award_unlocked',
        $awardKey,
        $actorUserId
    );

    return true;
}