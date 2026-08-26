<?php

$flightsPerPage = 20;
$flightPage = max(1, (int)($_GET['page'] ?? 1));
$flightCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM pilot_flights WHERE user_id = :user_id"
);
$flightCountStmt->execute(['user_id' => $profileUserId]);
$flightCount = (int)$flightCountStmt->fetchColumn();
$flightPages = max(1, (int)ceil($flightCount / $flightsPerPage));
$flightPage = min($flightPage, $flightPages);
$flightOffset = ($flightPage - 1) * $flightsPerPage;

$flightStmt = $pdo->prepare(
    "SELECT id, callsign, aircraft_icao, departure_airport, arrival_airport,
            landed_airport, destination_distance_nm,
            started_at, completed_at, duration_seconds, distance_nm,
            landing_rate_fpm, status
     FROM pilot_flights
     WHERE user_id = :user_id
     ORDER BY started_at DESC
     LIMIT :limit OFFSET :offset"
);
$flightStmt->bindValue(':user_id', $profileUserId, PDO::PARAM_INT);
$flightStmt->bindValue(':limit', $flightsPerPage, PDO::PARAM_INT);
$flightStmt->bindValue(':offset', $flightOffset, PDO::PARAM_INT);
$flightStmt->execute();
$flights = $flightStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card">
    <div class="card-title"><?php echo h(t('profile_flight_log')); ?></div>
    <div class="card-body">
        <?php if (!$flights): ?>
            <div class="flight-empty"><?php echo h(t('profile_no_flights')); ?></div>
        <?php else: ?>
            <div class="flight-table-scroll">
                <table class="flight-table">
                    <thead><tr>
                        <th><?php echo h(t('profile_flight_date')); ?></th>
                        <th><?php echo h(t('profile_flight_callsign')); ?></th>
                        <th><?php echo h(t('profile_flight_route')); ?></th>
                        <th><?php echo h(t('profile_flight_aircraft')); ?></th>
                        <th><?php echo h(t('profile_flight_duration')); ?></th>
                        <th><?php echo h(t('profile_flight_distance')); ?></th>
                        <th><?php echo h(t('profile_flight_landing_rate')); ?></th>
                        <th><?php echo h(t('profile_flight_status')); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($flights as $flight): ?>
                        <tr>
                            <td><a href="flight.php?id=<?php echo (int)$flight['id']; ?>"><?php echo h(date('d.m.Y H:i', strtotime($flight['started_at']))); ?></a></td>
                            <td><?php echo h($flight['callsign']); ?></td>
                            <td>
                                <?php $departureCode = $flight['departure_airport'] ?: 'ZZZZ'; ?>
                                <?php $arrivalCode = $flight['arrival_airport'] ?: 'ZZZZ'; ?>
                                <?php if ($departureCode !== 'ZZZZ'): ?><a href="airport.php?icao=<?php echo rawurlencode($departureCode); ?>"><?php echo h($departureCode); ?></a><?php else: ?>ZZZZ<?php endif; ?>
                                →
                                <?php if ($arrivalCode !== 'ZZZZ'): ?><a href="airport.php?icao=<?php echo rawurlencode($arrivalCode); ?>"><?php echo h($arrivalCode); ?></a><?php else: ?>ZZZZ<?php endif; ?>
                            </td>
                            <td><?php echo h($flight['aircraft_icao']); ?></td>
                            <td><?php echo h(formatFlightTime((int)$flight['duration_seconds'])); ?></td>
                            <td><?php echo h(number_format((float)$flight['distance_nm'], 1, ',', '.')); ?> NM</td>
                            <td><?php echo $flight['landing_rate_fpm'] !== null ? h($flight['landing_rate_fpm']) . ' fpm' : '—'; ?></td>
                            <td><span class="flight-status <?php echo h($flight['status']); ?>"><?php echo h(t('profile_flight_status_' . $flight['status'])); ?></span><?php if ($flight['status'] === 'wrong_destination' && !empty($flight['landed_airport'])): ?><small class="wrong-destination-airport"><?php echo h(t('profile_flight_landed_at')); ?> <?php echo h($flight['landed_airport']); ?></small><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($flightPages > 1): ?>
                <nav class="flight-pagination">
                    <?php for ($page = 1; $page <= $flightPages; $page++): ?>
                        <a class="<?php echo $page === $flightPage ? 'active' : ''; ?>"
                           href="<?php echo h($profileBaseUrl . '&a=pilot&page=' . $page); ?>"><?php echo $page; ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.flight-table-scroll{overflow-x:auto}.flight-table{width:100%;border-collapse:collapse}
.flight-table th,.flight-table td{padding:11px 9px;border-bottom:1px solid #24445c;text-align:left;white-space:nowrap}
.flight-table th{color:#75bfff}.flight-status{padding:4px 7px;border-radius:4px;background:#344657}
.flight-status.completed{color:#66e5a5}.flight-status.wrong_destination{color:#ffad55}.flight-status.aborted{color:#ff9a9a}.flight-status.active{color:#ffd66b}.wrong-destination-airport{display:block;color:#d4a574;margin-top:3px}
.flight-pagination{display:flex;gap:6px;flex-wrap:wrap;margin-top:18px}.flight-pagination a{padding:7px 10px;border:1px solid #285475;border-radius:4px;color:#9ed4ff;text-decoration:none}
.flight-pagination a.active{background:#176dcc;color:#fff}
</style>

<?php
$atcLogStmt = $pdo->prepare(
    "SELECT callsign,station_code,position_code,is_trainer,connected_at,disconnected_at,duration_seconds
     FROM atc_session_history WHERE user_id=:user_id
     ORDER BY connected_at DESC LIMIT 100"
);
$atcLogStmt->execute(['user_id'=>$profileUserId]);
$atcLog = $atcLogStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card" id="atc-logbook" style="margin-top:18px">
    <div class="card-title"><?php echo h(t('profile_atc_logbook')); ?></div>
    <div class="card-body">
        <?php if (!$atcLog): ?>
            <div class="flight-empty"><?php echo h(t('profile_no_atc_sessions')); ?></div>
        <?php else: ?>
            <div class="flight-table-scroll"><table class="flight-table">
                <thead><tr><th><?php echo h(t('profile_flight_date')); ?></th><th><?php echo h(t('profile_flight_callsign')); ?></th><th><?php echo h(t('profile_atc_position')); ?></th><th><?php echo h(t('profile_flight_duration')); ?></th></tr></thead>
                <tbody><?php foreach ($atcLog as $session): ?><tr>
                    <td><?php echo h(date('d.m.Y H:i', strtotime($session['connected_at']))); ?></td>
                    <td><?php echo h($session['callsign']); ?></td>
                    <td><?php echo h($session['station_code'] . ' / ' . $session['position_code']); ?></td>
                    <td><?php echo h(formatFlightTime((int)$session['duration_seconds'])); ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>
