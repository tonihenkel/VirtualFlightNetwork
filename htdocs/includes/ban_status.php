<?php

function getActiveBanStatus(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT is_banned, ban_reason, ban_expires_at
         FROM users
         WHERE id = :user_id
         LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId]);
    $ban = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ban || (int)$ban['is_banned'] !== 1) {
        return ['active' => false, 'reason' => '', 'expires_at' => null];
    }

    $expiresAt = $ban['ban_expires_at'] ?: null;
    if ($expiresAt !== null && strtotime((string)$expiresAt) <= time()) {
        $pdo->prepare(
            "UPDATE users
             SET is_banned = 0,
                 ban_reason = NULL,
                 ban_expires_at = NULL,
                 banned_at = NULL,
                 banned_by_user_id = NULL
             WHERE id = :user_id"
        )->execute(['user_id' => $userId]);

        return ['active' => false, 'reason' => '', 'expires_at' => null];
    }

    return [
        'active' => true,
        'reason' => (string)($ban['ban_reason'] ?? ''),
        'expires_at' => $expiresAt
    ];
}

