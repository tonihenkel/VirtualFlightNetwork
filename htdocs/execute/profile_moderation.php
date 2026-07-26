<?php

header('Content-Type: text/html; charset=utf-8');

require_once 'admin_auth.php';
require_once '../includes/ban_status.php';

function moderationRedirect(int $targetId, string $type, string $message): void
{
    header(
        'Location: ../profile.php?'
        . http_build_query([
            'id' => $targetId,
            'a' => 'moderation',
            'type' => $type,
            'message' => $message
        ])
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$pdo = createAdminPdo();
$actor = requireAdminUser($pdo, 2);
$targetId = (int)($_POST['target_user_id'] ?? 0);
$action = (string)($_POST['moderation_action'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));
$csrf = (string)($_POST['csrf'] ?? '');

if (
    empty($_SESSION['profile_moderation_csrf'])
    || !hash_equals((string)$_SESSION['profile_moderation_csrf'], $csrf)
) {
    moderationRedirect($targetId, 'error', 'moderation_invalid_request');
}

if ($targetId <= 0 || $reason === '' || mb_strlen($reason) > 255) {
    moderationRedirect($targetId, 'error', 'moderation_reason_required');
}

$targetStmt = $pdo->prepare(
    "SELECT id, username, op_permission
     FROM users
     WHERE id = :id
     LIMIT 1"
);
$targetStmt->execute(['id' => $targetId]);
$target = $targetStmt->fetch(PDO::FETCH_ASSOC);

if (
    !$target
    || (int)$target['id'] === (int)$actor['id']
    || (int)$target['op_permission'] >= (int)$actor['op_permission']
) {
    moderationRedirect($targetId, 'error', 'moderation_not_allowed');
}

try {
    $pdo->beginTransaction();

    if ($action === 'revoke_warning') {
        if ((int)$actor['op_permission'] < 4) {
            $pdo->rollBack();
            moderationRedirect($targetId, 'error', 'moderation_warning_revoke_requires_op4');
        }
        $warningId = (int)($_POST['warning_id'] ?? 0);
        $warningStmt = $pdo->prepare(
            "SELECT id FROM user_warnings
             WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL
             FOR UPDATE"
        );
        $warningStmt->execute(['id' => $warningId, 'user_id' => $targetId]);
        if (!$warningStmt->fetchColumn()) {
            $pdo->rollBack();
            moderationRedirect($targetId, 'error', 'moderation_warning_not_active');
        }
        $pdo->prepare(
            "UPDATE user_warnings
             SET revoked_at = NOW(), revoked_by_user_id = :actor_id,
                 revoke_reason = :reason
             WHERE id = :id"
        )->execute(['actor_id' => $actor['id'], 'reason' => $reason, 'id' => $warningId]);
        $activityKey = 'activity_warning_revoked';
        $activityValue = $reason;
        $successMessage = 'moderation_warning_revoke_success';
    } elseif ($action === 'warning') {
        $unit = (string)($_POST['duration_unit'] ?? '');
        $value = (int)($_POST['duration_value'] ?? 0);
        $limits = ['hours' => 8760, 'days' => 3650, 'weeks' => 520, 'months' => 120];
        if ($unit === 'permanent') {
            $warningExpiresAt = null;
            $durationLabel = 'permanent';
        } elseif (!isset($limits[$unit]) || $value < 1 || $value > $limits[$unit]) {
            $pdo->rollBack();
            moderationRedirect($targetId, 'error', 'moderation_invalid_duration');
        } else {
            $warningExpiresAt = (new DateTimeImmutable('now'))
                ->modify('+' . $value . ' ' . $unit)
                ->format('Y-m-d H:i:s');
            $durationLabel = $value . ' ' . $unit;
        }
        $pdo->prepare(
            "INSERT INTO user_warnings
                (user_id, issued_by_user_id, reason, expires_at)
             VALUES (:user_id, :actor_id, :reason, :expires_at)"
        )->execute([
            'user_id' => $targetId,
            'actor_id' => $actor['id'],
            'reason' => $reason,
            'expires_at' => $warningExpiresAt
        ]);
        $activityKey = 'activity_warning_issued';
        $activityValue = $reason . ' [' . $durationLabel . ']';
        $successMessage = 'moderation_warning_success';
    } elseif ($action === 'unban') {
        if ((int)$actor['op_permission'] < 4) {
            $pdo->rollBack();
            moderationRedirect($targetId, 'error', 'moderation_unban_requires_op4');
        }

        $banStatus = getActiveBanStatus($pdo, $targetId);
        if (!$banStatus['active']) {
            $pdo->rollBack();
            moderationRedirect($targetId, 'error', 'moderation_not_banned');
        }

        $pdo->prepare(
            "UPDATE users
             SET is_banned = 0,
                 ban_reason = NULL,
                 ban_expires_at = NULL,
                 banned_at = NULL,
                 banned_by_user_id = NULL
             WHERE id = :user_id"
        )->execute(['user_id' => $targetId]);

        $activityKey = 'activity_unbanned';
        $activityValue = $reason;
        $successMessage = 'moderation_unban_success';
    } elseif ($action === 'kick') {
        $onlineStmt = $pdo->prepare(
            "SELECT 1
             FROM pilot_positions p
             INNER JOIN user_sessions s
                ON s.user_id = p.user_id
               AND s.is_active = 1
             WHERE p.user_id = :user_id
               AND p.last_update >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
               AND s.last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
             LIMIT 1"
        );
        $onlineStmt->execute(['user_id' => $targetId]);

        if (!$onlineStmt->fetchColumn()) {
            $pdo->rollBack();
            moderationRedirect($targetId, 'error', 'moderation_kick_offline');
        }

        $activityKey = 'activity_kicked';
        $activityValue = $reason;
        $successMessage = 'moderation_kick_success';
    } elseif ($action === 'ban') {
        $unit = (string)($_POST['duration_unit'] ?? '');
        $value = (int)($_POST['duration_value'] ?? 0);
        $limits = [
            'minutes' => 525600,
            'hours' => 8760,
            'days' => 3650,
            'weeks' => 520,
            'months' => 120,
            'years' => 10
        ];

        if ($unit === 'permanent') {
            $expiresAt = null;
            $durationLabel = 'permanent';
        } elseif (!isset($limits[$unit]) || $value < 1 || $value > $limits[$unit]) {
            $pdo->rollBack();
            moderationRedirect($targetId, 'error', 'moderation_invalid_duration');
        } else {
            $expiresAt = (new DateTimeImmutable('now'))
                ->modify('+' . $value . ' ' . $unit)
                ->format('Y-m-d H:i:s');
            $durationLabel = $value . ' ' . $unit;
        }

        $pdo->prepare(
            "UPDATE users
             SET is_banned = 1,
                 ban_reason = :reason,
                 ban_expires_at = :expires_at,
                 banned_at = NOW(),
                 banned_by_user_id = :actor_id
             WHERE id = :user_id"
        )->execute([
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'actor_id' => (int)$actor['id'],
            'user_id' => $targetId
        ]);

        $activityKey = 'activity_banned';
        $activityValue = $reason . ' [' . $durationLabel . ']';
        $successMessage = 'moderation_ban_success';
    } else {
        $pdo->rollBack();
        moderationRedirect($targetId, 'error', 'moderation_invalid_request');
    }

    $pdo->prepare(
        "INSERT INTO user_activity_log
            (user_id, actor_user_id, activity_type, activity_key, activity_value)
         VALUES
            (:user_id, :actor_user_id, :activity_type, :activity_key, :activity_value)"
    )->execute([
        'user_id' => $targetId,
        'actor_user_id' => (int)$actor['id'],
        'activity_type' => $action,
        'activity_key' => $activityKey,
        'activity_value' => $activityValue
    ]);

    if (!in_array($action, ['unban', 'warning', 'revoke_warning'], true)) {
        $pdo->prepare(
            "UPDATE pilot_flights
             SET status = 'aborted', completed_at = NOW()
             WHERE user_id = :user_id AND status = 'active'"
        )->execute(['user_id' => $targetId]);
        $pdo->prepare(
            "UPDATE user_sessions
             SET is_active = 0, last_seen = NOW()
             WHERE user_id = :user_id AND is_active = 1"
        )->execute(['user_id' => $targetId]);
        $pdo->prepare(
            "DELETE FROM pilot_positions WHERE user_id = :user_id"
        )->execute(['user_id' => $targetId]);
    }

    $pdo->commit();
    moderationRedirect($targetId, 'success', $successMessage);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    moderationRedirect($targetId, 'error', 'moderation_failed');
}
