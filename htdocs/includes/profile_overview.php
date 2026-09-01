
            <?php


            $awardStmt = $pdo->prepare(
                "SELECT award_key, awarded_at
                 FROM user_awards
                 WHERE user_id = :user_id
                 ORDER BY awarded_at DESC LIMIT 0,4"
            );

            $awardStmt->execute([
                'user_id' => $profileUserId
            ]);

            $userAwards =
                $awardStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (!empty($profileGcas)): ?>
            <div class="card">
                <div class="card-title"><?php echo htmlspecialchars(t('profile_gca_title')); ?></div>
                <div class="card-body"><div class="role-grid">
                    <?php foreach ($profileGcas as $gca): ?>
                    <div class="role-item"><strong><img src="images/flags/<?php echo htmlspecialchars(strtolower((string)$gca['division_code'])); ?>.png" class="profile-country-flag" alt=""> <?php echo htmlspecialchars((string)($gca['division_name'] ?: $gca['division_code'])); ?></strong><?php echo htmlspecialchars(t('gca_status_'.$gca['status'])); ?></div>
                    <?php endforeach; ?>
                </div></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($profileDivisionStaff)): ?>
            <div class="card">
                <div class="card-title"><?php echo h(t('profile_division_staff_title')); ?></div>
                <div class="card-body"><div class="role-grid">
                    <?php foreach ($profileDivisionStaff as $staffRole): ?>
                    <div class="role-item">
                        <strong>
                            <img src="images/flags/<?php echo h(strtolower((string)$staffRole['division_code'])); ?>.png" class="profile-country-flag" alt="">
                            <?php echo h((string)($staffRole['division_name'] ?: $staffRole['division_code'])); ?>
                        </strong>
                        <?php echo h(trim((string)$staffRole['role_title']) !== '' ? (string)$staffRole['role_title'] : (string)$staffRole['role_code']); ?>
                    </div>
                    <?php endforeach; ?>
                </div></div>
            </div>
            <?php endif; ?>

            <div class="card hero-card">
                <div class="user-hero">
                    <div class="avatar-wrap">
                        <div class="avatar">
                            <?php if ($avatarUrl !== ''): ?>
                                <img src="<?php echo h($avatarUrl); ?>"
                                     alt="<?php echo h(t('profile_avatar_alt')); ?>">
                            <?php endif; ?>
                        </div>
                        <div class="avatar-online <?php echo $isNetworkOnline ? '' : 'offline'; ?>"></div>
                    </div>

                    <div>
                        <div class="profile-name">
                            <?php echo h($displayName); ?>
                            <span class="status-badge <?php echo $isNetworkOnline ? '' : 'offline'; ?>">
                                <?php echo $isNetworkOnline ? htmlspecialchars(t('profile_online')) : htmlspecialchars(t('profile_offline')); ?>
                            </span>
                        </div>

                        <div class="profile-meta">
                            VFN-ID: <?php echo h($vfnId); ?><br>
                            <?php echo htmlspecialchars(t('profile_member_since')); ?>: <?php echo h($memberSince); ?><br>
                            <img
                                src="images/flags/<?php echo strtolower($countryCode); ?>.png"
                                class="profile-country-flag"
                                alt="">

                            <?php echo h($countryName); ?><br>

                            <?php echo h(t('profile_home_airport')); ?>:
                            <?php if ($homeAirportCode !== 'ZZZZ'): ?>
                                <a href="airport.php?icao=<?php echo rawurlencode($homeAirportCode); ?>"><?php echo h($homeAirportLabel); ?></a>
                            <?php else: ?>
                                <?php echo h($homeAirportLabel); ?>
                            <?php endif; ?><br>

                            <img
                                src="images/flags/<?php echo strtolower($divisionCode); ?>.png"
                                class="profile-country-flag"
                                alt="">

                            <a href="division.php?code=<?php echo urlencode($divisionCode); ?>">
                                <?php echo h($divisionName); ?>
                            </a>
                            <?php if ($canShowLiveMapLink): ?>
                                <br>
                                <a class="profile-map-link"
                                   href="map.php?pilot_id=<?php echo (int)$profileUserId; ?>&follow=1">
                                    <?php echo h(t('profile_show_live_map')); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($showRatings): ?>
                    <div class="rating-summary">
                        <div class="rating-summary-item">
                            <div class="rating-summary-title"><?php echo htmlspecialchars(t('profile_atc_rating')); ?></div>
                            <img class="rating-summary-img" src="<?php echo h($atcRating['image']); ?>" alt="<?php echo h($atcRating['code']); ?>">
                            <div class="rating-summary-name"><?php echo h($atcRating['name']); ?></div>
                        </div>

                        <div class="rating-summary-item">
                            <div class="rating-summary-title"><?php echo htmlspecialchars(t('profile_pilot_rating')); ?></div>
                            <img class="rating-summary-img" src="<?php echo h($pilotRating['image']); ?>" alt="<?php echo h($pilotRating['code']); ?>">
                            <div class="rating-summary-name"><?php echo h($pilotRating['name']); ?></div>
                        </div>

                        <?php if ($specialRating): ?>
                            <div class="rating-summary-item">
                                <div class="rating-summary-title"><?php echo htmlspecialchars(t('profile_special_rank')); ?></div>
                                    <img class="rating-summary-img" src="<?php echo h($specialRating['image']); ?>" alt="<?php echo h($specialRating['code']); ?>">
                                    <div class="rating-summary-name"><?php echo h($specialRating['name']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>








        <!-- ## START ## -->







            <div class="content-grid">
                <div class="card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_statistics')); ?></div>
                    <div class="card-body">
                        <div class="stats-columns">
                            <div>
                                <div class="stats-section-title"><?php echo htmlspecialchars(t('profile_pilot')); ?></div>
                                <div class="stat-row"><span>✈ <?php echo htmlspecialchars(t('profile_flight_hours')); ?></span><strong><?php echo h(formatFlightTime($totalFlightSeconds)); ?></strong></div>
                                <div class="stat-row"><span>↗ <?php echo htmlspecialchars(t('profile_distance_flown')); ?></span><strong><?php echo h(number_format($totalFlightMiles, 1, ',', '.')); ?> NM</strong></div>
                                <div class="stat-row">
                                    <span>🛬 <?php echo htmlspecialchars(t('profile_landings')); ?></span>
                                    <strong><?php echo h(number_format($totalLandings, 0, ',', '.')); ?></strong>
                                </div>
                                <div class="stat-row">
                                    <span>🛧 <?php echo htmlspecialchars(t('profile_favourite_aircraft')); ?></span>
                                    <strong><a class="profile-stat-link" href="statistics.php?<?php echo $profileStatisticsQuery; ?>#aircraftStatistics"><?php echo h($favouriteAircraft); ?></a></strong>
                                </div>
                                <div class="stat-row"><span><?php echo htmlspecialchars(t('profile_top_airport')); ?></span><strong><a class="profile-stat-link" href="statistics.php?<?php echo $profileStatisticsQuery; ?>#airportStatistics"><?php echo h($profileTopAirport); ?></a></strong></div>
                                <div class="stat-row"><span><?php echo htmlspecialchars(t('profile_top_country')); ?></span><strong><a class="profile-stat-link" href="statistics.php?<?php echo $profileStatisticsQuery; ?>#countryStatistics"><?php echo h($profileTopCountry); ?></a></strong></div>
                                <div class="stat-row"><span><?php echo htmlspecialchars(t('profile_top_route')); ?></span><strong><?php echo h($profileTopRoute); ?></strong></div>
                                <div class="stat-row"><span><?php echo htmlspecialchars(t('profile_longest_flight')); ?></span><strong><?php echo h($profileLongestFlight); ?></strong></div>
                            </div>

                            <div>
                                <div class="stats-section-title atc"><?php echo htmlspecialchars(t('profile_atc')); ?></div>
                                <div class="stat-row"><span>🗼 <?php echo htmlspecialchars(t('profile_controller_hours')); ?></span><strong><?php echo h(formatFlightTime($atcControllerSeconds)); ?></strong></div>
                                <div class="stat-row"><span>📋 <?php echo htmlspecialchars(t('profile_atc_sessions')); ?></span><strong><?php echo h(number_format($atcSessionCount, 0, ',', '.')); ?></strong></div>
                                <div class="stat-row"><span>📍 <?php echo htmlspecialchars(t('profile_favorite_position')); ?></span><strong><?php echo h($profileFavoriteAtcPosition); ?></strong></div>
                                <div class="stat-row"><span><?php echo htmlspecialchars(t('profile_last_atc_position')); ?></span><strong><?php echo h($profileLastAtcPosition); ?></strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_latest_activities')); ?></div>
                    <div class="card-body">
                        <div class="activity-list">
                            <div class="activity-row">
                                <div class="activity-icon">✈</div>
                                <div class="activity-main">

                                    <strong>
                                        <?php echo htmlspecialchars(t('profile_last_flight')); ?>
                                    </strong>

                                    <?php if ($lastFlight): ?>

                                        <?php echo h($lastFlight['aircraft_icao']); ?>
                                        ·
                                        <?php echo h($lastFlight['landing_rate_fpm']); ?> fpm

                                    <?php else: ?>

                                        <?php echo htmlspecialchars(t('profile_no_data')); ?>

                                    <?php endif; ?>

                                </div>

                                <div class="activity-time">

                                    <?php if ($lastFlight): ?>

                                        <?php echo date(
                                            'd.m.Y H:i',
                                            strtotime($lastFlight['created_at'])
                                        ); ?>

                                    <?php else: ?>

                                        ----

                                    <?php endif; ?>

                                </div>

                            </div>
                            <div class="activity-row">
                                <div class="activity-icon">🏆</div>
                                <div class="activity-main"><strong><?php echo htmlspecialchars(t('profile_rating_update')); ?></strong><?php echo h($pilotRating['code'] . ' / ' . $atcRating['code']); ?></div>
                                <div class="activity-time">----</div>
                            </div>
                        </div>
                    </div>
                </div>



                <div class="card overview-awards-card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_awards')); ?></div>
                    <div class="card-body">
                        <div class="awards">

                            <div class="awards">

                                <?php if (empty($userAwards)): ?>

                                    <div><?php echo htmlspecialchars(t('profile_no_data')); ?></div>

                                <?php else: ?>

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
                                            </div>
                                        </div>

                                    <?php endforeach; ?>

                                <?php endif; ?>
                                <div class="awards-footer">
                                    <a href="profile.php?id=<?php echo $profileUserId; ?>&lang=<?php echo urlencode($currentLanguage); ?>&a=awards">
                                        <?php echo htmlspecialchars(t('profile_view_all_awards')); ?>
                                    </a>
                                </div>
                            </div>



                        </div>
                    </div>
                </div>

            </div>



            <div class="full-width-row">


                <div class="card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_pilot_rating')); ?> <?php echo htmlspecialchars(t('profile_progress')); ?></div>
                    <div class="card-body">
                        <div class="rating-track">
                            <?php for ($i = 0; $i <= 9; $i++): ?>
                                <?php $rating = getPilotRating($i); ?>
                                <div class="track-rating <?php echo $i > $pilotRatingValue ? 'locked' : ''; ?>">
                                    <img src="<?php echo h($rating['image']); ?>" title="<?php echo h($rating['code'] . ' - ' . $rating['name']); ?>">
                                    <?php echo h($rating['code']); ?>
                                </div>
                                <?php if ($i < 9): ?><div class="track-arrow">→</div><?php endif; ?>
                            <?php endfor; ?>
                        </div>

                        <div class="current-rating-box">
                            <img src="<?php echo h($pilotRating['image']); ?>">
                            <div>
                                <div class="current-rating-title"><?php echo htmlspecialchars(t('profile_current_rating')); ?>: <?php echo h($pilotRating['name']); ?></div>
                                <div class="current-rating-meta"><?php echo htmlspecialchars(t('profile_checked_by')); ?>: VFN Staff ✅</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>





            <div class="full-width-row">

                <div class="card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_atc_rating')); ?> <?php echo htmlspecialchars(t('profile_progress')); ?></div>
                    <div class="card-body">
                        <div class="rating-track">
                            <?php for ($i = 0; $i <= 9; $i++): ?>
                                <?php $rating = getAtcRating($i); ?>
                                <div class="track-rating <?php echo $i > $atcRatingValue ? 'locked' : ''; ?>">
                                    <img src="<?php echo h($rating['image']); ?>" title="<?php echo h($rating['code'] . ' - ' . $rating['name']); ?>">
                                    <?php echo h($rating['code']); ?>
                                </div>
                                <?php if ($i < 9): ?><div class="track-arrow">→</div><?php endif; ?>
                            <?php endfor; ?>
                        </div>

                        <div class="current-rating-box">
                            <img src="<?php echo h($atcRating['image']); ?>">
                            <div>
                                <div class="current-rating-title"><?php echo htmlspecialchars(t('profile_current_rating')); ?>: <?php echo h($atcRating['name']); ?></div>
                                <div class="current-rating-meta"><?php echo htmlspecialchars(t('profile_checked_by')); ?>:
                                    <?php echo htmlspecialchars(t('profile_vfn_staff')); ?> ✅
                            </div>
                        </div>
                    </div>
                </div>
            </div>










            <!-- ## ENDE ## -->

            <div class="full-width-row">


                <div class="card training-card">
                    <div class="training-empty">
                        <div class="training-icon">☑</div>
                        <div>
                            <strong><?php echo htmlspecialchars(t('profile_no_active_training')); ?></strong><br>
                            <span><?php echo htmlspecialchars(t('profile_no_training_text')); ?></span>
                        </div>
                    </div>

                    <div class="role-grid">
                        <div class="role-item"><strong><?php echo htmlspecialchars(t('profile_mentor')); ?></strong>----</div>
                        <div class="role-item"><strong><?php echo htmlspecialchars(t('profile_examiner')); ?></strong>----</div>
                        <div class="role-item"><strong><?php echo htmlspecialchars(t('profile_division')); ?></strong><a href="division.php?code=<?php echo urlencode($divisionCode); ?>"><img src="images/flags/<?php echo strtolower($divisionCode); ?>.png" class="profile-country-flag" alt=""> <?php echo h($divisionName); ?></a></div>
                        <div class="role-item"><strong><?php echo htmlspecialchars(t('profile_staff_role')); ?></strong><?php echo $specialRating ? h($specialRating['name']) : '----'; ?></div>
                    </div>
                </div>
            </div>

