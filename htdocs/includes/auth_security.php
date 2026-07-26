<?php

function authSubjectHash(string $subject): string
{
    return hash('sha256', strtolower(trim($subject)));
}

function authRateIsBlocked(PDO $pdo, string $action, string $subject): bool
{
    $stmt = $pdo->prepare(
        "SELECT blocked_until
         FROM auth_rate_limits
         WHERE action_name = :action_name AND subject_hash = :subject_hash
         LIMIT 1"
    );
    $stmt->execute([
        'action_name' => $action,
        'subject_hash' => authSubjectHash($subject)
    ]);
    $blockedUntil = $stmt->fetchColumn();
    return $blockedUntil && strtotime((string)$blockedUntil) > time();
}

function authRateFail(
    PDO $pdo,
    string $action,
    string $subject,
    int $limit = 5,
    int $windowMinutes = 15,
    int $blockMinutes = 15
): void {
    $subjectHash = authSubjectHash($subject);
    $stmt = $pdo->prepare(
        "INSERT INTO auth_rate_limits
            (action_name, subject_hash, attempts, window_started_at, blocked_until)
         VALUES (:action_name, :subject_hash, 1, NOW(), NULL)
         ON DUPLICATE KEY UPDATE
            attempts = IF(
                window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                1,
                attempts + 1
            ),
            window_started_at = IF(
                window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                NOW(),
                window_started_at
            ),
            blocked_until = IF(
                attempts + 1 >= :attempt_limit,
                DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                blocked_until
            )"
    );
    $stmt->execute([
        'action_name' => $action,
        'subject_hash' => $subjectHash,
        'attempt_limit' => $limit
    ]);
}

function authRateClear(PDO $pdo, string $action, string $subject): void
{
    $stmt = $pdo->prepare(
        "DELETE FROM auth_rate_limits
         WHERE action_name = :action_name AND subject_hash = :subject_hash"
    );
    $stmt->execute([
        'action_name' => $action,
        'subject_hash' => authSubjectHash($subject)
    ]);
}
