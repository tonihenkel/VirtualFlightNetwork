

            <div class="card hero-card">
                <div class="user-hero">
                    <div class="avatar-wrap">
                        <div class="avatar"></div>
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

                            <img
                                src="images/flags/<?php echo strtolower($divisionCode); ?>.png"
                                class="profile-country-flag"
                                alt="">

                            <?php echo h($divisionName); ?>
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
                                    <strong><?php echo h($favouriteAircraft); ?></strong>
                                </div>
                            </div>

                            <div>
                                <div class="stats-section-title atc"><?php echo htmlspecialchars(t('profile_atc')); ?></div>
                                <div class="stat-row"><span>🗼 <?php echo htmlspecialchars(t('profile_controller_hours')); ?></span><strong>----</strong></div>
                                <div class="stat-row"><span>📋 <?php echo htmlspecialchars(t('profile_atc_sessions')); ?></span><strong>----</strong></div>
                                <div class="stat-row"><span>📍 <?php echo htmlspecialchars(t('profile_favorite_position')); ?></span><strong>----</strong></div>
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



                <div class="card">
                    <div class="card-title"><?php echo htmlspecialchars(t('profile_awards')); ?></div>
                    <div class="card-body">
                        <div class="awards">
                            <div><?php echo htmlspecialchars(t('profile_no_data')); ?></div>
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
                        <div class="role-item"><strong><?php echo htmlspecialchars(t('profile_division')); ?></strong><?php echo h($divisionName); ?></div>
                        <div class="role-item"><strong><?php echo htmlspecialchars(t('profile_staff_role')); ?></strong><?php echo $specialRating ? h($specialRating['name']) : '----'; ?></div>
                    </div>
                </div>
            </div>

