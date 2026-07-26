<?php
header("Content-Type: text/plain; charset=utf-8");

require_once 'config.php';
require_once '../includes/chat_system.php';

function calculateChatDistanceNm(
    float $lat1,
    float $lon1,
    float $lat2,
    float $lon2
): float {

    $earthRadiusKm = 6371.0;

    $dLat =
        deg2rad($lat2 - $lat1);

    $dLon =
        deg2rad($lon2 - $lon1);

    $a =
        sin($dLat / 2) * sin($dLat / 2)
        +
        cos(deg2rad($lat1))
        *
        cos(deg2rad($lat2))
        *
        sin($dLon / 2)
        *
        sin($dLon / 2);

    $c =
        2 *
        atan2(
            sqrt($a),
            sqrt(1 - $a)
        );

    return ($earthRadiusKm * $c) * 0.539957;
}

$token =
    trim($_POST['token'] ?? '');

$sinceId =
    max(0, (int)($_POST['since_id'] ?? 0));

$frequencyInput =
    (string)($_POST['frequencies'] ?? '');

$frequencies = [];

foreach (explode(',', $frequencyInput) as $frequency) {
    $normalized =
        normalizeChatFrequency($frequency);

    if ($normalized === null) {
        continue;
    }

    if (!in_array($normalized, $frequencies, true)) {
        $frequencies[] =
            $normalized;
    }
}

if ($token === '') {
    echo "ERR\tKein Token uebergeben.\n";
    exit;
}

try {
    $chatFrequencyRangeNm =
        (float)($chatFrequencyRangeNm ?? 200.0);

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
            user_id
         FROM user_sessions
         WHERE token = :token
           AND is_active = 1
         LIMIT 1"
    );

    $stmt->execute([
        'token' => $token
    ]);

    $session =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo "ERR\tUngueltige oder abgelaufene Session.\n";
        exit;
    }

    $userId =
        (int)$session['user_id'];

    $receiverPositionStmt = $pdo->prepare(
        "SELECT
            latitude,
            longitude,
            com1,
            com2
         FROM pilot_positions
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $receiverPositionStmt->execute([
        'user_id' =>
            $userId
    ]);

    $receiverPosition =
        $receiverPositionStmt->fetch(PDO::FETCH_ASSOC);

    $receiverLatitude =
        $receiverPosition ? (float)$receiverPosition['latitude'] : null;

    $receiverLongitude =
        $receiverPosition ? (float)$receiverPosition['longitude'] : null;

    if ($receiverPosition) {
        foreach (['com1', 'com2'] as $radioColumn) {
            $serverFrequency =
                normalizeChatFrequency(
                    (string)($receiverPosition[$radioColumn] ?? '')
                );

            if (
                $serverFrequency !== null
                && !in_array($serverFrequency, $frequencies, true)
            ) {
                $frequencies[] =
                    $serverFrequency;
            }
        }
    }

    $params = [
        'since_id' => $sinceId,
        'user_id' => $userId
    ];

    $frequencyWhere = '';

    if (!empty($frequencies)) {
        $placeholders = [];

        foreach ($frequencies as $index => $frequency) {
            $key =
                'frequency_' . $index;

            $placeholders[] =
                ':' . $key;

            $params[$key] =
                $frequency;
        }

        $frequencyWhere =
            ' OR frequency IN (' . implode(',', $placeholders) . ')';
    }

    $cursorWasReset =
        false;

    if ($sinceId > 0) {
        $currentMaxStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(id), 0)
             FROM chat_messages
             WHERE recipient_user_id = :user_id
                $frequencyWhere"
        );

        $currentMaxParams =
            $params;

        unset($currentMaxParams['since_id']);

        $currentMaxStmt->execute(
            $currentMaxParams
        );

        $currentMaxId =
            (int)$currentMaxStmt->fetchColumn();

        if ($sinceId > $currentMaxId) {
            // A restored/imported database can restart AUTO_INCREMENT at a
            // lower value while a running plugin still holds the old cursor.
            // Replay a small recent window instead of waiting forever for the
            // old ID to be reached again.
            $sinceId =
                max(0, $currentMaxId - 30);

            $params['since_id'] =
                $sinceId;

            $cursorWasReset =
                true;
        }
    }

    if ($sinceId <= 0 && !$cursorWasReset) {
        $maxStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(id), 0)
             FROM chat_messages
             WHERE recipient_user_id = :user_id
                $frequencyWhere"
        );

        $maxParams =
            $params;

        unset($maxParams['since_id']);

        $maxStmt->execute($maxParams);

        $initialMaxSeenId =
            (int)$maxStmt->fetchColumn();

        echo "OK\n";
        echo "LAST|" . $initialMaxSeenId . "\n";
        exit;
    }

    $messageStmt = $pdo->prepare(
        "SELECT
            id,
            frequency,
            sender_user_id,
            sender_latitude,
            sender_longitude,
            delivery_range_nm,
            sender_callsign,
            message_type,
            message_text,
            DATE_FORMAT(created_at, '%H:%i') AS message_time
         FROM chat_messages
         WHERE id > :since_id
           AND (
                recipient_user_id = :user_id
                $frequencyWhere
           )
         ORDER BY id ASC
         LIMIT 30"
    );

    $messageStmt->execute($params);

    echo "OK\n";

    $maxSeenId =
        $sinceId;

    $printedMessageIds = [];

    foreach ($messageStmt->fetchAll(PDO::FETCH_ASSOC) as $message) {
        $messageId =
            (int)$message['id'];

        if ($messageId > $maxSeenId) {
            $maxSeenId =
                $messageId;
        }

        $messageFrequency =
            (string)($message['frequency'] ?? '');

        $senderUserId =
            $message['sender_user_id'] === null
                ? null
                : (int)$message['sender_user_id'];

        $canReceive =
            false;

        if ($messageFrequency === '') {
            $canReceive = true;
        } elseif ($messageFrequency === '122.800') {
            $canReceive = true;
        } elseif (
            strpos((string)$message['sender_callsign'], 'STAFF:') === 0
            && $message['sender_latitude'] === null
            && $message['sender_longitude'] === null
        ) {
            // Web staff has no simulator position. Treat its selected
            // frequency as a global channel instead of discarding it during
            // the normal radio-distance check.
            $canReceive = true;
        } elseif ($senderUserId === $userId) {
            $canReceive = true;
        } elseif (
            $receiverLatitude !== null
            && $receiverLongitude !== null
            && $message['sender_latitude'] !== null
            && $message['sender_longitude'] !== null
        ) {
            $distanceNm =
                calculateChatDistanceNm(
                    $receiverLatitude,
                    $receiverLongitude,
                    (float)$message['sender_latitude'],
                    (float)$message['sender_longitude']
                );

            $canReceive =
            $distanceNm <= (
                $message['delivery_range_nm'] !== null
                    ? (float)$message['delivery_range_nm']
                    : $chatFrequencyRangeNm
            );
        }

        if (!$canReceive) {
            continue;
        }

        $messageText =
            str_replace(
                ["\r", "\n", "\t", '|'],
                ' ',
                (string)$message['message_text']
            );

        echo
            $messageId . '|' .
            $messageFrequency . '|' .
            (string)($message['message_time'] ?? '') . '|' .
            str_replace('|', ' ', (string)$message['sender_callsign']) . '|' .
            str_replace('|', ' ', (string)$message['message_type']) . '|' .
            $messageText . "\n";

        $printedMessageIds[$messageId] =
            true;
    }

    $personalStmt = $pdo->prepare(
        "SELECT
            id,
            frequency,
            sender_callsign,
            message_type,
            message_text,
            DATE_FORMAT(created_at, '%H:%i') AS message_time
         FROM chat_messages
         WHERE recipient_user_id = :user_id
           AND id > :since_id
         ORDER BY id ASC
         LIMIT 10"
    );

    $personalStmt->execute([
        'user_id' =>
            $userId,

        'since_id' =>
            $sinceId
    ]);

    foreach ($personalStmt->fetchAll(PDO::FETCH_ASSOC) as $message) {
        $messageId =
            (int)$message['id'];

        if (isset($printedMessageIds[$messageId])) {
            continue;
        }

        if ($messageId > $maxSeenId) {
            $maxSeenId =
                $messageId;
        }

        $messageText =
            str_replace(
                ["\r", "\n", "\t", '|'],
                ' ',
                (string)$message['message_text']
            );

        echo
            $messageId . '|' .
            (string)($message['frequency'] ?? '') . '|' .
            (string)($message['message_time'] ?? '') . '|' .
            str_replace('|', ' ', (string)$message['sender_callsign']) . '|' .
            str_replace('|', ' ', (string)$message['message_type']) . '|' .
            $messageText . "\n";
    }

    if ($maxSeenId > $sinceId) {
        echo "LAST|" . $maxSeenId . "\n";
    }

} catch (Exception $e) {
    echo "ERR\tServerfehler.\n";
}
