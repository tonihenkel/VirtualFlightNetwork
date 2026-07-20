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
            longitude
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

    if ($sinceId <= 0) {
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

        $initialMessageStmt = $pdo->prepare(
            "SELECT
                id,
                frequency,
                sender_callsign,
                message_type,
                message_text
             FROM chat_messages
             WHERE recipient_user_id = :user_id
             ORDER BY id ASC
             LIMIT 10"
        );

        $initialMessageStmt->execute([
            'user_id' =>
                $userId
        ]);

        echo "OK\n";

        foreach ($initialMessageStmt->fetchAll(PDO::FETCH_ASSOC) as $message) {
            $messageId =
                (int)$message['id'];

            if ($messageId > $initialMaxSeenId) {
                $initialMaxSeenId =
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
                str_replace('|', ' ', (string)$message['sender_callsign']) . '|' .
                str_replace('|', ' ', (string)$message['message_type']) . '|' .
                $messageText . "\n";
        }

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
            sender_callsign,
            message_type,
            message_text
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
                $distanceNm <= $chatFrequencyRangeNm;
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
            message_text
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
