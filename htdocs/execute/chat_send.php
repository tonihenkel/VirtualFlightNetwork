<?php
header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';
require_once '../includes/chat_system.php';
require_once '../includes/activity_log.php';

$token =
    trim($_POST['token'] ?? '');

$callsign =
    strtoupper(trim($_POST['callsign'] ?? ''));

$frequency =
    normalizeChatFrequency($_POST['frequency'] ?? null);

$message =
    trim((string)($_POST['message'] ?? ''));

if ($token === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Kein Token uebergeben.'
    ]);
    exit;
}

if ($callsign === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Callsign fehlt.'
    ]);
    exit;
}

if ($frequency === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Ungueltige Frequenz.'
    ]);
    exit;
}

if ($message === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Nachricht fehlt.'
    ]);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $stmt = $pdo->prepare(
        "SELECT
            s.user_id,
            s.callsign,
            u.op_permission
         FROM user_sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.token = :token
           AND s.is_active = 1
         LIMIT 1"
    );

    $stmt->execute([
        'token' => $token
    ]);

    $session =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode([
            'success' => false,
            'message' => 'Ungueltige oder abgelaufene Session.'
        ]);
        exit;
    }

    $senderUserId =
        (int)$session['user_id'];

    $senderCallsign =
        strtoupper(trim((string)($session['callsign'] ?? $callsign)));

    if (preg_match('/^\/list\s*$/i', $message)) {
        if ((int)$session['op_permission'] < 1) {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Keine Berechtigung fuer /list.'
            );

            echo json_encode([
                'success' => false,
                'message' => 'Keine Berechtigung.'
            ]);
            exit;
        }

        $onlineStmt = $pdo->prepare(
            "SELECT DISTINCT
                UPPER(s.callsign) AS callsign
             FROM user_sessions s
             WHERE s.is_active = 1
               AND s.callsign <> ''
             ORDER BY s.callsign ASC"
        );

        $onlineStmt->execute();

        $onlineCallsigns =
            $onlineStmt->fetchAll(PDO::FETCH_COLUMN);

        $onlineText =
            empty($onlineCallsigns)
                ? 'Keine Spieler online.'
                : 'Online Spieler (' . count($onlineCallsigns) . '): ' . implode(', ', $onlineCallsigns);

        insertUserChatSystemMessage(
            $pdo,
            $senderUserId,
            'system',
            $onlineText
        );

        echo json_encode([
            'success' => true,
            'message' => $onlineText
        ]);
        exit;
    }

    if (preg_match('/^\/staff\s+(.+)$/i', $message, $matches)) {
        $staffText =
            trim($matches[1]);

        if ($staffText === '') {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Syntax: /staff Nachricht'
            );

            echo json_encode([
                'success' => false,
                'message' => 'Ungueltiger /staff Befehl.'
            ]);
            exit;
        }

        $staffStmt = $pdo->prepare(
            "SELECT DISTINCT
                s.user_id
             FROM user_sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.is_active = 1
               AND u.op_permission > 1"
        );

        $staffStmt->execute();

        $staffUserIds =
            array_map('intval', $staffStmt->fetchAll(PDO::FETCH_COLUMN));

        if (empty($staffUserIds)) {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Keine Staff-Mitglieder online.'
            );

            echo json_encode([
                'success' => false,
                'message' => 'Kein Staff online.'
            ]);
            exit;
        }

        foreach ($staffUserIds as $staffUserId) {
            insertChatMessage(
                $pdo,
                null,
                $staffUserId,
                $senderUserId,
                $senderCallsign,
                'system',
                '[STAFF] ' . $staffText
            );
        }

        if (!in_array($senderUserId, $staffUserIds, true)) {
            insertChatMessage(
                $pdo,
                null,
                $senderUserId,
                $senderUserId,
                'STAFF',
                'system',
                'An Staff: ' . $staffText
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Staff-Nachricht gesendet.'
        ]);
        exit;
    }

    if (preg_match('/^\/msg\s+([A-Z0-9_\-]+)\s*:?\s+(.+)$/i', $message, $matches)) {
        $targetCallsign =
            strtoupper(trim($matches[1]));

        $privateText =
            trim($matches[2]);

        if ($targetCallsign === '' || $privateText === '') {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Syntax: /msg CALLSIGN : Nachricht'
            );

            echo json_encode([
                'success' => false,
                'message' => 'Ungueltiger /msg Befehl.'
            ]);
            exit;
        }

        $targetStmt = $pdo->prepare(
            "SELECT
                s.user_id,
                s.callsign
             FROM user_sessions s
             WHERE UPPER(s.callsign) = :callsign
               AND s.is_active = 1
             ORDER BY s.last_seen DESC
             LIMIT 1"
        );

        $targetStmt->execute([
            'callsign' => $targetCallsign
        ]);

        $targetSession =
            $targetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetSession) {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Private Nachricht nicht gesendet: ' . $targetCallsign . ' ist nicht online.'
            );

            echo json_encode([
                'success' => false,
                'message' => 'Ziel nicht online.'
            ]);
            exit;
        }

        insertChatMessage(
            $pdo,
            null,
            (int)$targetSession['user_id'],
            $senderUserId,
            $senderCallsign,
            'system',
            '[PM] ' . $privateText
        );

        insertChatMessage(
            $pdo,
            null,
            $senderUserId,
            $senderUserId,
            'MSG',
            'system',
            'An ' . strtoupper((string)$targetSession['callsign']) . ': ' . $privateText
        );

        echo json_encode([
            'success' => true,
            'message' => 'Private Nachricht gesendet.'
        ]);
        exit;
    }

    if (preg_match('/^\/playerinfo\s+([A-Z0-9_\-]+)\s*$/i', $message, $matches)) {
        $targetIdentifier =
            strtoupper(trim($matches[1]));

        $targetStmt = $pdo->prepare(
            "SELECT
                u.id,
                u.username,
                s.callsign
             FROM users u
             LEFT JOIN user_sessions s
                ON s.user_id = u.id
               AND s.is_active = 1
             WHERE UPPER(s.callsign) = :identifier
                OR UPPER(u.username) = :identifier
             ORDER BY s.is_active DESC, s.last_seen DESC
             LIMIT 1"
        );

        $targetStmt->execute([
            'identifier' => $targetIdentifier
        ]);

        $targetUser =
            $targetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Spieler nicht gefunden: ' . $targetIdentifier
            );

            echo json_encode([
                'success' => false,
                'message' => 'Spieler nicht gefunden.'
            ]);
            exit;
        }

        $scheme =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                ? 'https'
                : 'http';

        $host =
            $_SERVER['HTTP_HOST'] ?? '127.0.0.1';

        $profileUrl =
            $scheme . '://' . $host . '/profile.php?id=' . (int)$targetUser['id'];

        $displayName =
            strtoupper(trim((string)($targetUser['callsign'] ?? '')));

        if ($displayName === '') {
            $displayName =
                (string)$targetUser['username'];
        }

        insertUserChatSystemMessage(
            $pdo,
            $senderUserId,
            'system',
            'Profil wird geoeffnet: ' . $displayName
        );

        echo json_encode([
            'success' => true,
            'message' => 'Profil wird geoeffnet: ' . $displayName,
            'open_url' => $profileUrl
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (preg_match('/^\/kick\s+([A-Z0-9_\-]+)(?:\s+(.+))?\s*$/i', $message, $matches)) {
        if ((int)$session['op_permission'] < 1) {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Keine Berechtigung fuer /kick.'
            );

            echo json_encode([
                'success' => false,
                'message' => 'Keine Berechtigung.'
            ]);
            exit;
        }

        $targetCallsign =
            strtoupper(trim($matches[1]));

        $kickReason =
            trim((string)($matches[2] ?? ''));

        if ($kickReason === '') {
            $kickReason =
                'Kein Grund angegeben.';
        }

        $kickReason =
            mb_substr($kickReason, 0, 160);

        $targetStmt = $pdo->prepare(
            "SELECT
                s.user_id,
                s.token,
                s.callsign
             FROM user_sessions s
             WHERE UPPER(s.callsign) = :callsign
               AND s.is_active = 1
             ORDER BY s.last_seen DESC
             LIMIT 1"
        );

        $targetStmt->execute([
            'callsign' => $targetCallsign
        ]);

        $targetSession =
            $targetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetSession) {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Kick fehlgeschlagen: ' . $targetCallsign . ' ist nicht online.'
            );

            echo json_encode([
                'success' => false,
                'message' => 'Ziel nicht online.'
            ]);
            exit;
        }

        // Temporarily disabled for local kick testing.
        /*
        if ((int)$targetSession['user_id'] === $senderUserId) {
            insertUserChatSystemMessage(
                $pdo,
                $senderUserId,
                'system',
                'Du kannst dich nicht selbst kicken.'
            );

            echo json_encode([
                'success' => false,
                'message' => 'Self-kick nicht erlaubt.'
            ]);
            exit;
        }
        */

        $kickToken =
            (string)$targetSession['token'];

        $targetUserId =
            (int)$targetSession['user_id'];

        $targetCallsignResolved =
            strtoupper((string)$targetSession['callsign']);

        $kickMessage =
            'Du wurdest aus dem Netzwerk gekickt. Grund: ' . $kickReason;

        insertChatMessage(
            $pdo,
            null,
            $targetUserId,
            $senderUserId,
            'ADMIN',
            'system',
            $kickMessage
        );

        logActivity(
            $pdo,
            $targetUserId,
            'system',
            'activity_kicked',
            'Grund: ' . $kickReason,
            $senderUserId
        );

        $pdo->prepare(
            "UPDATE user_sessions
             SET is_active = 0, last_seen = NOW()
             WHERE token = :token
             LIMIT 1"
        )->execute([
            'token' => $kickToken
        ]);

        $pdo->prepare(
            "DELETE FROM pilot_positions
             WHERE session_token = :token"
        )->execute([
            'token' => $kickToken
        ]);

        $pdo->prepare(
            "DELETE FROM pilot_tracks
             WHERE session_token = :token"
        )->execute([
            'token' => $kickToken
        ]);

        insertChatMessage(
            $pdo,
            null,
            $senderUserId,
            $senderUserId,
            'ADMIN',
            'system',
            $targetCallsignResolved . ' wurde aus dem Netzwerk gekickt. Grund: ' . $kickReason
        );

        $isSelfKick =
            $targetUserId === $senderUserId;

        echo json_encode([
            'success' => true,
            'message' => $targetCallsignResolved . ' wurde gekickt. Grund: ' . $kickReason,
            'kicked' => $isSelfKick
        ]);
        exit;
    }

    if (preg_match('/^\/msg\b/i', $message)) {
        insertUserChatSystemMessage(
            $pdo,
            $senderUserId,
            'system',
            'Syntax: /msg CALLSIGN : Nachricht'
        );

        echo json_encode([
            'success' => false,
            'message' => 'Ungueltiger /msg Befehl.'
        ]);
        exit;
    }

    if (preg_match('/^\/playerinfo\b/i', $message)) {
        insertUserChatSystemMessage(
            $pdo,
            $senderUserId,
            'system',
            'Syntax: /playerinfo CALLSIGN'
        );

        echo json_encode([
            'success' => false,
            'message' => 'Ungueltiger /playerinfo Befehl.'
        ]);
        exit;
    }

    if (preg_match('/^\/kick\b/i', $message)) {
        insertUserChatSystemMessage(
            $pdo,
            $senderUserId,
            'system',
            'Syntax: /kick CALLSIGN [Grund]'
        );

        echo json_encode([
            'success' => false,
            'message' => 'Ungueltiger /kick Befehl.'
        ]);
        exit;
    }

    if (preg_match('/^\/list\b/i', $message)) {
        insertUserChatSystemMessage(
            $pdo,
            $senderUserId,
            'system',
            'Syntax: /list'
        );

        echo json_encode([
            'success' => false,
            'message' => 'Ungueltiger /list Befehl.'
        ]);
        exit;
    }

    if (preg_match('/^\/staff\b/i', $message)) {
        insertUserChatSystemMessage(
            $pdo,
            $senderUserId,
            'system',
            'Syntax: /staff Nachricht'
        );

        echo json_encode([
            'success' => false,
            'message' => 'Ungueltiger /staff Befehl.'
        ]);
        exit;
    }

    if (strpos($message, '/') === 0) {
        $commandName =
            strtok($message, " \t") ?: $message;

        insertUserChatSystemMessage(
            $pdo,
            $senderUserId,
            'system',
            'Unbekanntes Kommando: ' . $commandName
        );

        echo json_encode([
            'success' => false,
            'message' => 'Unbekanntes Kommando: ' . $commandName
        ]);
        exit;
    }

    $positionStmt = $pdo->prepare(
        "SELECT
            latitude,
            longitude
         FROM pilot_positions
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $positionStmt->execute([
        'user_id' =>
            $senderUserId
    ]);

    $position =
        $positionStmt->fetch(PDO::FETCH_ASSOC);

    $senderLatitude =
        $position ? (float)$position['latitude'] : null;

    $senderLongitude =
        $position ? (float)$position['longitude'] : null;

    insertChatMessage(
        $pdo,
        $frequency,
        null,
        $senderUserId,
        $senderCallsign,
        'pilot',
        $message,
        $senderLatitude,
        $senderLongitude
    );

    echo json_encode([
        'success' => true,
        'message' => 'Nachricht gesendet.'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Serverfehler.',
        'error' => $e->getMessage()
    ]);
}
