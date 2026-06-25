<?php


$awardStmt = $pdo->prepare(
    "SELECT
        award_key,
        awarded_at
     FROM user_awards
     WHERE user_id = :user_id
     ORDER BY awarded_at DESC"
);

$awardStmt->execute([
    'user_id' => $profileUserId
]);

$userAwards =
    $awardStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="card">

    <div class="card-title">
        <?php echo htmlspecialchars(t('profile_awards')); ?>
    </div>

    <div class="card-body">

        <?php if (empty($userAwards)): ?>

            <?php echo htmlspecialchars(t('profile_no_data')); ?>

        <?php else: ?>

            <div class="awards awards-full">

                <?php foreach ($userAwards as $award): ?>

                    <?php
                        $awardKey =
                            $award['award_key'];

                        $awardImage =
                            $awardImages[$awardKey]
                            ?? 'images/awards/default.png';
                    ?>

                    <div class="award-item">
                        <img
                            src="<?php echo h($awardImage); ?>"
                            alt="<?php echo h(t($awardKey)); ?>"
                            class="award-image">

                        <div class="award-title">
                            <?php echo h(t($awardKey)); ?>
                                <hr class="award-separator">
                            <?php echo htmlspecialchars(t('description_'.$awardKey)); ?>
                        </div>

                        <div class="award-date">
                            <?php echo h(date('d.m.Y', strtotime($award['awarded_at']))); ?>
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>