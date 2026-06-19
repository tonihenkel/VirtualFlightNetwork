<?php

function logActivity(
    PDO $pdo,
    int $userId,
    string $activityType,
    string $activityKey,
    string $activityValue = '',
    int $actorUserId = 0
): void {

    $stmt = $pdo->prepare(
        "INSERT INTO user_activity_log
        (
            user_id,
            actor_user_id,
            activity_type,
            activity_key,
            activity_value
        )
        VALUES
        (
            :user_id,
            :actor_user_id,
            :activity_type,
            :activity_key,
            :activity_value
        )"
    );

    $stmt->execute([
        'user_id' =>
            $userId,

        'actor_user_id' =>
            $actorUserId,

        'activity_type' =>
            $activityType,

        'activity_key' =>
            $activityKey,

        'activity_value' =>
            $activityValue
    ]);
}