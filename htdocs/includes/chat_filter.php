<?php

function filterChatMessage(string $message, ?PDO $pdo = null): array
{
    $original = mb_substr(trim($message), 0, 255);
    $filtered = $original;
    $words = [];
    $loadedFromDatabase = false;
    if ($pdo instanceof PDO) {
        try {
            $words = $pdo
                ->query("SELECT word FROM chat_filter_words ORDER BY CHAR_LENGTH(word) DESC")
                ->fetchAll(PDO::FETCH_COLUMN);
            $loadedFromDatabase = true;
        } catch (Throwable $e) {
            $words = [];
        }
    }
    if (!$loadedFromDatabase) {
        $words = require __DIR__ . '/chat_filter_words.php';
    }

    usort($words, static function (string $a, string $b): int {
        return mb_strlen($b) <=> mb_strlen($a);
    });

    foreach ($words as $word) {
        $word = trim((string)$word);
        if ($word === '') {
            continue;
        }
        $pattern =
            '/(?<![\p{L}\p{N}_])'
            . preg_quote($word, '/')
            . '(?![\p{L}\p{N}_])/iu';
        $filtered = preg_replace_callback(
            $pattern,
            static function (array $match): string {
                return str_repeat('*', mb_strlen($match[0]));
            },
            $filtered
        );
    }

    return [
        'original' => $original,
        'filtered' => $filtered,
        'was_filtered' => $filtered !== $original
    ];
}
