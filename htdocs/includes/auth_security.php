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
    $windowStart = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
    $blockedUntil = date('Y-m-d H:i:s', time() + ($blockMinutes * 60));
    $stmt = $pdo->prepare(
        "INSERT INTO auth_rate_limits
            (action_name, subject_hash, attempts, window_started_at, blocked_until)
         VALUES (:action_name, :subject_hash, 1, NOW(), NULL)
         ON DUPLICATE KEY UPDATE
            blocked_until = IF(
                window_started_at < :window_start_limit,
                NULL,
                IF(attempts + 1 >= :attempt_limit, :blocked_until, blocked_until)
            ),
            attempts = IF(window_started_at < :window_start, 1, attempts + 1),
            window_started_at = IF(window_started_at < :window_start_update, NOW(), window_started_at)"
    );
    $stmt->execute([
        'action_name' => $action,
        'subject_hash' => $subjectHash,
        'attempt_limit' => $limit,
        'window_start' => $windowStart,
        'window_start_update' => $windowStart,
        'window_start_limit' => $windowStart,
        'blocked_until' => $blockedUntil
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
