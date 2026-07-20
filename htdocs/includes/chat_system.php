<?php

function normalizeChatFrequency(?string $frequency): ?string
{
    $frequency =
        trim((string)$frequency);

    if ($frequency === '') {
        return null;
    }

    $frequency =
        str_replace(',', '.', $frequency);

    if (!preg_match('/^\d{3}\.\d{3}$/', $frequency)) {
        return null;
    }

    $value =
        (float)$frequency;

    if ($value < 118.000 || $value > 136.975) {
        return null;
    }

    return number_format($value, 3, '.', '');
}

function insertChatMessage(
    PDO $pdo,
    ?string $frequency,
    ?int $recipientUserId,
    ?int $senderUserId,
    string $senderCallsign,
    string $messageType,
    string $messageText,
    ?float $senderLatitude = null,
    ?float $senderLongitude = null
): void {

    $messageText =
        trim($messageText);

    if ($messageText === '') {
        return;
    }

    $messageText =
        mb_substr($messageText, 0, 255);

    $senderCallsign =
        strtoupper(trim($senderCallsign));

    if ($senderCallsign === '') {
        $senderCallsign = 'SYSTEM';
    }

    if (!in_array($messageType, ['pilot', 'system', 'award', 'landing'], true)) {
        $messageType = 'system';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO chat_messages
        (
            frequency,
            recipient_user_id,
            sender_user_id,
            sender_callsign,
            sender_latitude,
            sender_longitude,
            message_type,
            message_text
        )
        VALUES
        (
            :frequency,
            :recipient_user_id,
            :sender_user_id,
            :sender_callsign,
            :sender_latitude,
            :sender_longitude,
            :message_type,
            :message_text
        )"
    );

    $stmt->execute([
        'frequency' =>
            $frequency,

        'recipient_user_id' =>
            $recipientUserId,

        'sender_user_id' =>
            $senderUserId,

        'sender_callsign' =>
            $senderCallsign,

        'sender_latitude' =>
            $senderLatitude,

        'sender_longitude' =>
            $senderLongitude,

        'message_type' =>
            $messageType,

        'message_text' =>
            $messageText
    ]);
}

function insertUserChatSystemMessage(
    PDO $pdo,
    int $userId,
    string $messageType,
    string $messageText
): void {

    insertChatMessage(
        $pdo,
        null,
        $userId,
        null,
        'VFN',
        $messageType,
        $messageText
    );
}
