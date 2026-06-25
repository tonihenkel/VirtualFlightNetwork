<?php

try
{
    require_once 'execute/config.php';

    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass
    );

    $stmt = $pdo->query(
        "SELECT
            code,
            name
         FROM divisions
         ORDER BY name"
    );

    $divisions =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}
catch (Exception $e)
{
}

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

function getUnreadActivityCount(
    PDO $pdo,
    int $userId
): int {

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM user_activity_log
         WHERE user_id = :user_id
           AND is_read = 0"
    );

    $stmt->execute([
        'user_id' => $userId
    ]);

    return (int)$stmt->fetchColumn();
}