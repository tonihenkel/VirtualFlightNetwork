<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/chat_system.php';
require_once __DIR__ . '/../includes/plugin_messages.php';

function contactReply(bool $success, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeAtcHandoffFrequency($value): ?string
{
    $value = strtoupper(trim((string)$value));
    $value = trim(str_replace(['MHZ', ','], ['', '.'], $value));
    if ($value === '' || !is_numeric($value)) return null;
    $frequency = (float)$value;
    if ($frequency < 118.000 || $frequency > 136.975) return null;
    return number_format($frequency, 3, '.', '');
}

function cleanupClearancesAfterHandoff(PDO $pdo, string $pilotToken, string $sourcePosition, string $targetPosition, bool $onGround): void
{
    $source = strtoupper(trim($sourcePosition));
    $target = strtoupper(trim($targetPosition));
    if ($onGround && in_array($target, ['INFO', 'DEL', 'GND'], true)
        && in_array($source, ['TWR', 'APP', 'DEP', 'CTR'], true)) {
        $pdo->prepare("UPDATE atc_aircraft_clearances
                       SET cleared_departure_runway='',cleared_landing_runway='',cleared_sid='',
                           cleared_direct='',cleared_star='',cleared_altitude='',
                           clearance_type='DIRECT',clearance_value='',updated_at=NOW()
                       WHERE pilot_session_token=:token")
            ->execute(['token'=>$pilotToken]);
        return;
    }
    if (!$onGround && $source === 'TWR' && in_array($target, ['APP', 'DEP', 'CTR'], true)) {
        $pdo->prepare("UPDATE atc_aircraft_clearances
                       SET cleared_departure_runway='',cleared_sid='',
                           clearance_type='DIRECT',clearance_value='',updated_at=NOW()
                       WHERE pilot_session_token=:token")
            ->execute(['token'=>$pilotToken]);
        return;
    }
    if (in_array($source, ['APP', 'DEP', 'CTR'], true) && $target === 'TWR') {
        $pdo->prepare("UPDATE atc_aircraft_clearances
                       SET cleared_star='',cleared_direct='',cleared_altitude='',
                           clearance_type='DIRECT',clearance_value='',updated_at=NOW()
                       WHERE pilot_session_token=:token")
            ->execute(['token'=>$pilotToken]);
    }
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        contactReply(false, 'login_required', 401);
    }
    ensureAtcSchema($pdo);
    $languageColumn = $pdo->query(
        "SHOW COLUMNS FROM user_sessions LIKE 'plugin_language'"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$languageColumn) {
        $pdo->exec(
            "ALTER TABLE user_sessions
             ADD COLUMN plugin_language VARCHAR(10) NOT NULL DEFAULT 'en'"
        );
    }
    $stmt = $pdo->prepare(
        "SELECT * FROM atc_sessions WHERE user_id=:user_id AND session_token=:token
         AND is_active=1 AND (is_spectator=0 OR is_trainer=1) AND can_control=1
         AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $stmt->execute(['user_id'=>(int)$_SESSION['web_user_id'], 'token'=>(string)($_SESSION['atc_session_token'] ?? '')]);
    $atc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$atc) contactReply(false, 'atc_control_session_required', 403);
    $isTrainer = (int)($atc['is_trainer'] ?? 0) === 1;
    $pdo->exec("DELETE aa FROM atc_assumed_aircraft aa LEFT JOIN atc_sessions a ON a.id=aa.atc_session_id WHERE a.id IS NULL OR a.is_active=0");

    $callsign = strtoupper(trim((string)($_POST['callsign'] ?? '')));
    $frequency = normalizeChatFrequency((string)($_POST['frequency'] ?? $atc['frequency'] ?? ''));
    $action = strtolower(trim((string)($_POST['action'] ?? 'initial-contact')));
    if ($callsign === '' || ($frequency === null && !in_array($action, ['assume', 'assumed', 'unassume', 'un-assume', 'handoff', 'handoff-accept', 'handoff-reject'], true))) {
        contactReply(false, 'invalid_data', 422);
    }
    $pilotStmt = $pdo->prepare(
        "SELECT p.user_id,p.session_token,p.on_ground,
                COALESCE(NULLIF(s.plugin_language,''),NULLIF(u.preferred_language,''),'en') AS plugin_language
         FROM pilot_positions p INNER JOIN user_sessions s ON s.token=p.session_token
         INNER JOIN users u ON u.id=p.user_id
         WHERE UPPER(p.callsign)=:callsign AND s.is_active=1 AND s.is_spectator=0
           AND p.last_update>=DATE_SUB(NOW(),INTERVAL 20 SECOND) LIMIT 1"
    );
    $pilotStmt->execute(['callsign'=>$callsign]);
    $pilot = $pilotStmt->fetch(PDO::FETCH_ASSOC);
    $isTrainingTarget = false;
    if (!$pilot) {
        $trainingStmt = $pdo->prepare(
            "SELECT ta.id,ta.placement_type,creator.user_id,
                    COALESCE(NULLIF(u.preferred_language,''),'en') AS plugin_language
             FROM atc_training_aircraft ta
             INNER JOIN atc_sessions creator ON creator.id=ta.trainer_session_id
             INNER JOIN users u ON u.id=creator.user_id
             WHERE UPPER(ta.callsign)=:callsign AND creator.is_active=1 AND creator.is_trainer=1
               AND creator.last_seen_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) LIMIT 1"
        );
        $trainingStmt->execute(['callsign'=>$callsign]);
        $training = $trainingStmt->fetch(PDO::FETCH_ASSOC);
        if ($training) {
            $pilot = [
                'user_id'=>(int)$training['user_id'],
                'session_token'=>'training:'.(int)$training['id'],
                'on_ground'=>(string)$training['placement_type'] === 'air' ? 0 : 1,
                'plugin_language'=>(string)$training['plugin_language'],
            ];
            $isTrainingTarget = true;
        }
    }
    if (!$pilot) contactReply(false, 'pilot_not_online', 404);

    $atcCallsign = strtoupper((string)$atc['callsign']);
    $language = strtolower((string)($pilot['plugin_language'] ?? 'en'));
    if ($action === 'force-act' && !$isTrainer) {
        $forceActOwner = $pdo->prepare(
            "SELECT atc_session_id FROM atc_assumed_aircraft
             WHERE pilot_session_token=:pilot_token LIMIT 1"
        );
        $forceActOwner->execute(['pilot_token'=>(string)$pilot['session_token']]);
        if ((int)$forceActOwner->fetchColumn() !== (int)$atc['id']) {
            contactReply(false, 'aircraft_not_assumed_by_you', 409);
        }
    }
    if (in_array($action, ['assume', 'assumed'], true)) {
        $ownerStatement=$pdo->prepare("SELECT atc_session_id,atc_callsign FROM atc_assumed_aircraft WHERE pilot_session_token=:pilot_token LIMIT 1");
        $ownerStatement->execute(['pilot_token'=>(string)$pilot['session_token']]);
        $existingOwner=$ownerStatement->fetch(PDO::FETCH_ASSOC);
        if($existingOwner&&(int)$existingOwner['atc_session_id']!==(int)$atc['id']) {
            contactReply(false, 'aircraft_already_assumed_by_' . (string)$existingOwner['atc_callsign'], 409);
        }
        $assume = $pdo->prepare(
            "INSERT INTO atc_assumed_aircraft
                (pilot_session_token,pilot_callsign,atc_session_id,atc_user_id,atc_callsign)
             VALUES (:pilot_token,:pilot_callsign,:atc_session_id,:atc_user_id,:atc_callsign)
             ON DUPLICATE KEY UPDATE atc_session_id=VALUES(atc_session_id),
                atc_user_id=VALUES(atc_user_id),atc_callsign=VALUES(atc_callsign),
                pilot_callsign=VALUES(pilot_callsign),assumed_at=NOW(),updated_at=NOW()"
        );
        $assume->execute(['pilot_token'=>(string)$pilot['session_token'],'pilot_callsign'=>$callsign,
            'atc_session_id'=>(int)$atc['id'],'atc_user_id'=>(int)$atc['user_id'],'atc_callsign'=>$atcCallsign]);
        contactReply(true, 'aircraft_assumed');
    }
    if (in_array($action, ['unassume', 'un-assume'], true)) {
        $unassume=$pdo->prepare("DELETE FROM atc_assumed_aircraft WHERE pilot_session_token=:pilot_token" . ($isTrainer ? "" : " AND atc_session_id=:atc_session_id"));
        $unassumeParameters=['pilot_token'=>(string)$pilot['session_token']];
        if (!$isTrainer) $unassumeParameters['atc_session_id']=(int)$atc['id'];
        $unassume->execute($unassumeParameters);
        contactReply(true, 'aircraft_unassumed');
    }
    if ($action === 'handoff') {
        $targetCallsign = strtoupper(trim((string)($_POST['target_callsign'] ?? '')));
        if ($targetCallsign === '' || $targetCallsign === $atcCallsign) {
            contactReply(false, 'invalid_handoff_target', 422);
        }
        $ownerStatement = $pdo->prepare(
            "SELECT atc_session_id FROM atc_assumed_aircraft
             WHERE pilot_session_token=:pilot_token LIMIT 1"
        );
        $ownerStatement->execute(['pilot_token'=>(string)$pilot['session_token']]);
        if (!$isTrainer && (int)$ownerStatement->fetchColumn() !== (int)$atc['id']) {
            contactReply(false, 'aircraft_not_assumed_by_you', 409);
        }
        $targetStatement = $pdo->prepare(
            "SELECT id,user_id,callsign FROM atc_sessions
             WHERE UPPER(callsign)=:callsign AND is_active=1 AND is_spectator=0
               AND can_control=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)
             LIMIT 1"
        );
        $targetStatement->execute(['callsign'=>$targetCallsign]);
        $target = $targetStatement->fetch(PDO::FETCH_ASSOC);
        if (!$target) contactReply(false, 'handoff_target_not_available', 404);
        if ($isTrainer) {
            $trainerOwnership = $pdo->prepare(
                "INSERT INTO atc_assumed_aircraft
                    (pilot_session_token,pilot_callsign,atc_session_id,atc_user_id,atc_callsign)
                 VALUES (:pilot_token,:pilot_callsign,:atc_session_id,:atc_user_id,:atc_callsign)
                 ON DUPLICATE KEY UPDATE atc_session_id=VALUES(atc_session_id),
                    atc_user_id=VALUES(atc_user_id),atc_callsign=VALUES(atc_callsign),
                    pilot_callsign=VALUES(pilot_callsign),updated_at=NOW()"
            );
            $trainerOwnership->execute([
                'pilot_token'=>(string)$pilot['session_token'],'pilot_callsign'=>$callsign,
                'atc_session_id'=>(int)$atc['id'],'atc_user_id'=>(int)$atc['user_id'],
                'atc_callsign'=>$atcCallsign,
            ]);
        }
        $pdo->prepare(
            "UPDATE atc_handoff_requests SET status='cancelled',responded_at=NOW()
             WHERE pilot_session_token=:pilot_token AND status='pending'"
        )->execute(['pilot_token'=>(string)$pilot['session_token']]);
        $handoff = $pdo->prepare(
            "INSERT INTO atc_handoff_requests
                (pilot_session_token,pilot_callsign,source_session_id,source_callsign,
                 target_session_id,target_callsign,status)
             VALUES (:pilot_token,:pilot_callsign,:source_id,:source_callsign,
                     :target_id,:target_callsign,'pending')"
        );
        $handoff->execute([
            'pilot_token'=>(string)$pilot['session_token'], 'pilot_callsign'=>$callsign,
            'source_id'=>(int)$atc['id'], 'source_callsign'=>$atcCallsign,
            'target_id'=>(int)$target['id'], 'target_callsign'=>(string)$target['callsign'],
        ]);
        contactReply(true, 'handoff_requested_to_' . (string)$target['callsign']);
    }
    if (in_array($action, ['handoff-accept', 'handoff-reject'], true)) {
        $requestId = max(0, (int)($_POST['request_id'] ?? 0));
        if ($requestId < 1) contactReply(false, 'invalid_handoff_request', 422);
        $pdo->beginTransaction();
        $requestStmt = $pdo->prepare(
            "SELECT * FROM atc_handoff_requests
             WHERE id=:id AND target_session_id=:target_id AND status='pending'
             FOR UPDATE"
        );
        $requestStmt->execute(['id'=>$requestId,'target_id'=>(int)$atc['id']]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $pdo->rollBack();
            contactReply(false, 'handoff_request_not_available', 409);
        }
        $accepted = $action === 'handoff-accept';
        if ($accepted) {
            $handoff = $pdo->prepare(
                "UPDATE atc_assumed_aircraft
                 SET atc_session_id=:target_id,atc_user_id=:target_user_id,
                     atc_callsign=:target_callsign,updated_at=NOW()
                 WHERE pilot_session_token=:pilot_token AND atc_session_id=:source_id"
            );
            $handoff->execute([
                'target_id'=>(int)$atc['id'],'target_user_id'=>(int)$atc['user_id'],
                'target_callsign'=>$atcCallsign,'pilot_token'=>(string)$request['pilot_session_token'],
                'source_id'=>(int)$request['source_session_id'],
            ]);
            if ($handoff->rowCount() !== 1) {
                $pdo->rollBack();
                contactReply(false, 'handoff_conflict', 409);
            }
            $sourcePositionStatement = $pdo->prepare("SELECT position_code FROM atc_sessions WHERE id=:id LIMIT 1");
            $sourcePositionStatement->execute(['id'=>(int)$request['source_session_id']]);
            cleanupClearancesAfterHandoff(
                $pdo,
                (string)$request['pilot_session_token'],
                (string)($sourcePositionStatement->fetchColumn() ?: ''),
                (string)($atc['position_code'] ?? ''),
                (bool)($pilot['on_ground'] ?? false)
            );
        }
        $update = $pdo->prepare(
            "UPDATE atc_handoff_requests SET status=:status,responded_at=NOW() WHERE id=:id"
        );
        $update->execute(['status'=>$accepted?'accepted':'rejected','id'=>$requestId]);
        $pdo->commit();
        if ($accepted) {
            $handoffFrequency = normalizeAtcHandoffFrequency($atc['frequency'] ?? '');
            if ($handoffFrequency === null) contactReply(false, 'handoff_target_frequency_invalid', 422);
            $text = vfnPluginContactMessage($language, 'initial-contact', $handoffFrequency, $atcCallsign);
            insertChatMessage(
                $pdo,
                null,
                (int)$pilot['user_id'],
                (int)$atc['user_id'],
                $atcCallsign,
                'atc_contact',
                $text
            );
        }
        contactReply(true, $accepted ? 'handoff_accepted' : 'handoff_rejected');
    }
    if (in_array($action, ['release', 'leave-airspace'], true)) {
        $release=$pdo->prepare("DELETE FROM atc_assumed_aircraft WHERE pilot_session_token=:pilot_token" . ($isTrainer ? "" : " AND atc_session_id=:atc_session_id"));
        $releaseParameters=['pilot_token'=>(string)$pilot['session_token']];
        if (!$isTrainer) $releaseParameters['atc_session_id']=(int)$atc['id'];
        $release->execute($releaseParameters);
        $clearances=$pdo->prepare("DELETE FROM atc_aircraft_clearances WHERE pilot_session_token=:pilot_token");
        $clearances->execute(['pilot_token'=>(string)$pilot['session_token']]);
        $text=vfnPluginContactMessage($language, 'release', '122.800', $atcCallsign);
    } else {
        $text=vfnPluginContactMessage($language, 'initial-contact', (string)$frequency, $atcCallsign);
    }
    insertChatMessage($pdo, null, (int)$pilot['user_id'], (int)$atc['user_id'],
        $atcCallsign, 'atc_contact', $text);
    contactReply(true, $text);
} catch (Throwable $error) {
    contactReply(false, 'server_error', 500);
}
