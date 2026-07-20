<?php

require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/chat_system.php';

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
            award_key
         )
         VALUES
         (
            :user_id,
            :award_key
         )"
    );

    $stmt->execute([
        'user_id' => $userId,
        'award_key' => $awardKey
    ]);

    logActivity(
        $pdo,
        $userId,
        'award',
        'activity_award_unlocked',
        $awardKey,
        $createdBy
    );

    insertUserChatSystemMessage(
        $pdo,
        $userId,
        'award',
        'award:' . $awardKey
    );

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

function userHasActivity(
    PDO $pdo,
    int $userId,
    string $activityKey
): bool {

    $stmt = $pdo->prepare(
        "SELECT id
         FROM user_activity_log
         WHERE user_id = :user_id
           AND activity_key = :activity_key
         LIMIT 1"
    );

    $stmt->execute([
        'user_id' => $userId,
        'activity_key' => $activityKey
    ]);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}
