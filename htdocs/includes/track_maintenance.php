<?php

require_once __DIR__ . '/job_status.php';

function vfnThinStoredFlightTrack(PDO $pdo, string $token, string $callsign, int $target): int
{
    $stmt = $pdo->prepare(
        "SELECT id FROM pilot_tracks
         WHERE session_token = :token AND callsign = :callsign
         ORDER BY id ASC"
    );
    $stmt->execute(['token' => $token, 'callsign' => $callsign]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $count = count($ids);
    if ($count <= $target || $target < 2) {
        return 0;
    }
    $step = max(2, (int)ceil(($count - 1) / ($target - 1)));
    $delete = [];
    foreach ($ids as $index => $id) {
        if ($index !== 0 && $index !== $count - 1 && $index % $step !== 0) {
            $delete[] = $id;
        }
    }
    foreach (array_chunk($delete, 750) as $chunk) {
        $pdo->exec('DELETE FROM pilot_tracks WHERE id IN ('
            . implode(',', array_map('intval', $chunk)) . ')');
    }
    return count($delete);
}

function vfnRunTrackMaintenance(PDO $pdo, bool $force = false): array
{
    if (!$force) {
        $lastRun = $pdo->query(
            "SELECT last_success_at FROM system_job_status
             WHERE job_key = 'track_cleanup' LIMIT 1"
        )->fetchColumn();
        if ($lastRun && strtotime((string)$lastRun) > time() - 86400) {
            return ['ran' => false];
        }
    }
    $lock = (int)$pdo->query("SELECT GET_LOCK('vfn_track_cleanup', 0)")->fetchColumn();
    if ($lock !== 1) {
        return ['ran' => false];
    }
    $summary = [
        'ran' => true,
        'positions_deleted' => 0,
        'flights_aborted' => 0,
        'orphan_tracks_deleted' => 0,
        'aborted_tracks_deleted' => 0,
        'tracks_thinned' => 0,
        'flights_thinned' => 0,
    ];
    try {
        $summary['positions_deleted'] = $pdo->exec(
            "DELETE p FROM pilot_positions p
             LEFT JOIN user_sessions s ON s.token = p.session_token
             WHERE p.last_update < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
               AND (s.token IS NULL OR s.is_active = 0
                    OR s.last_seen < DATE_SUB(NOW(), INTERVAL 10 MINUTE))"
        );
        $summary['flights_aborted'] = $pdo->exec(
            "UPDATE pilot_flights f
             LEFT JOIN user_sessions s ON s.token = f.session_token
             SET f.status = 'aborted', f.completed_at = COALESCE(f.completed_at, NOW())
             WHERE f.status = 'active'
               AND f.updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
               AND (s.token IS NULL OR s.is_active = 0
                    OR s.last_seen < DATE_SUB(NOW(), INTERVAL 10 MINUTE))"
        );
        $summary['orphan_tracks_deleted'] = $pdo->exec(
            "DELETE t FROM pilot_tracks t
             LEFT JOIN pilot_flights f
               ON f.session_token = t.session_token AND f.callsign = t.callsign
             WHERE f.id IS NULL
               AND t.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $summary['aborted_tracks_deleted'] = $pdo->exec(
            "DELETE t FROM pilot_tracks t
             INNER JOIN pilot_flights f
               ON f.session_token = t.session_token AND f.callsign = t.callsign
             WHERE f.status = 'aborted'
               AND f.completed_at < DATE_SUB(NOW(), INTERVAL 180 DAY)"
        );

        $flights = $pdo->query(
            "SELECT f.session_token, f.callsign,
                    CASE WHEN f.completed_at < DATE_SUB(NOW(), INTERVAL 180 DAY)
                         THEN 350 ELSE 1200 END AS target_points
             FROM pilot_flights f
             INNER JOIN pilot_tracks t
               ON t.session_token = f.session_token AND t.callsign = f.callsign
             WHERE f.status IN ('completed','wrong_destination')
               AND f.completed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY f.id, f.session_token, f.callsign, f.completed_at
             HAVING COUNT(t.id) > target_points
             ORDER BY f.completed_at ASC
             LIMIT 12"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($flights as $flight) {
            $deleted = vfnThinStoredFlightTrack(
                $pdo,
                (string)$flight['session_token'],
                (string)$flight['callsign'],
                (int)$flight['target_points']
            );
            if ($deleted > 0) {
                $summary['tracks_thinned'] += $deleted;
                $summary['flights_thinned']++;
            }
        }
        vfnRecordJobStatus(
            $pdo,
            'track_cleanup',
            true,
            json_encode($summary, JSON_UNESCAPED_SLASHES)
        );
        return $summary;
    } catch (Throwable $error) {
        vfnRecordJobStatus($pdo, 'track_cleanup', false, $error->getMessage());
        throw $error;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('vfn_track_cleanup')");
    }
}
