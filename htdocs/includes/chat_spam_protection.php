<?php

function registerChatSpamEvent(
    PDO $pdo,
    int $userId,
    string $sessionToken,
    string $message
): ?string {
    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message)));
    $messageHash = hash('sha256', $normalized);

    $pdo->prepare(
        "INSERT INTO chat_spam_events
            (user_id, session_token, message_hash)
         VALUES (:user_id, :session_token, :message_hash)"
    )->execute([
        'user_id' => $userId,
        'session_token' => $sessionToken,
        'message_hash' => $messageHash
    ]);

    // Kleine, gelegentliche Bereinigung ohne Einfluss auf den aktuellen Nutzer.
    if (random_int(1, 50) === 1) {
        $pdo->exec(
            "DELETE FROM chat_spam_events
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
    }

    $stmt = $pdo->prepare(
        "SELECT
            SUM(created_at >= DATE_SUB(NOW(6), INTERVAL 10 SECOND)) AS burst_count,
            SUM(created_at >= DATE_SUB(NOW(6), INTERVAL 60 SECOND)) AS minute_count,
            SUM(
                message_hash = :message_hash
                AND created_at >= DATE_SUB(NOW(6), INTERVAL 30 SECOND)
            ) AS repeat_count
         FROM chat_spam_events
         WHERE user_id = :user_id
           AND created_at >= DATE_SUB(NOW(6), INTERVAL 60 SECOND)"
    );
    $stmt->execute([
        'message_hash' => $messageHash,
        'user_id' => $userId
    ]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if ((int)($counts['repeat_count'] ?? 0) >= 7) {
        return 'repeat';
    }
    if ((int)($counts['burst_count'] ?? 0) >= 15) {
        return 'burst';
    }
    if ((int)($counts['minute_count'] ?? 0) >= 35) {
        return 'minute';
    }
    return null;
}
