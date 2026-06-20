<?php

$activityStmt = $pdo->prepare(
    "SELECT
        l.activity_type,
        l.activity_key,
        l.activity_value,
        l.actor_user_id,
        l.created_at,
        u.username AS actor_username,
        u.real_name AS actor_real_name
     FROM user_activity_log l
     LEFT JOIN users u ON u.id = l.actor_user_id
     WHERE l.user_id = :user_id
     ORDER BY l.created_at DESC
     LIMIT 100"
);

$activityStmt->execute([
    'user_id' => $profileUserId
]);

$activities =
    $activityStmt->fetchAll(PDO::FETCH_ASSOC);

function activityIcon(string $type): string
{
    switch ($type) {
        case 'registration':
            return '👤';

        case 'email_verified':
            return '✉';

        case 'password_changed':
            return '🔑';

        case 'division_changed':
            return '🌍';

        case 'country_changed':
            return '🏳';

        case 'exam_passed':
            return '🎓';

        case 'rating_changed':
            return '🏆';

        case 'flight':
            return '✈';

        case 'award':
            return '🏅';

        case 'warning':
            return '⚠';

        case 'ban':
            return '🚫';

        default:
            return '📋';
    }
}

?>

<div class="card">

    <div class="card-title">
        <?php echo htmlspecialchars(t('profile_activities')); ?>
    </div>

    <div class="card-body">

        <?php if (empty($activities)): ?>

            <?php echo htmlspecialchars(t('profile_no_data')); ?>

        <?php else: ?>

            <div class="activity-list">

                <?php foreach ($activities as $activity): ?>

                    <?php
                        $actorName =
                            (int)$activity['actor_user_id'] === 0
                                ? t('activity_system')
                                : (
                                    $activity['actor_real_name']
                                    ?: $activity['actor_username']
                                    ?: t('profile_unknown')
                                );
                    ?>

                    <div class="activity-row">

                        <div class="activity-icon">
                            <?php echo activityIcon($activity['activity_type']); ?>
                        </div>

                        <div class="activity-main">

                            <strong>
                                <?php echo htmlspecialchars(t($activity['activity_key'])); ?>
                            </strong>

                            <?php if (!empty($activity['activity_value'])): ?>

                                <?php
                                    $activityValue =
                                        $activity['activity_value'];

                                    if ($activity['activity_type'] === 'award') {
                                        $activityValue =
                                            t($activityValue);
                                    }
                                ?>

                                <?php echo h($activityValue); ?><br>

                            <?php endif; ?>

                            <small>
                                <?php echo htmlspecialchars(t('profile_checked_by')); ?>:
                                <?php echo h($actorName); ?>
                            </small>

                        </div>

                        <div class="activity-time">
                            <?php echo h(date('d.m.Y H:i', strtotime($activity['created_at']))); ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>