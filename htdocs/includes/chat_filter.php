<?php

function filterChatMessage(string $message, ?PDO $pdo = null): array
{
    static $databaseWordCache = [];

    $original = mb_substr(trim($message), 0, 255);
    $filtered = $original;
    $words = [];
    $loadedFromDatabase = false;
    if ($pdo instanceof PDO) {
        try {
            $connectionId = spl_object_id($pdo);
            if (!array_key_exists($connectionId, $databaseWordCache)) {
                $databaseWordCache[$connectionId] = $pdo
                    ->query("SELECT word FROM chat_filter_words ORDER BY CHAR_LENGTH(word) DESC")
                    ->fetchAll(PDO::FETCH_COLUMN);
            }
            $words = $databaseWordCache[$connectionId];
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
        // Also match prohibited terms inside compounds or deliberate
        // extensions (for example a severe insult with an added prefix).
        $pattern = '/' . preg_quote($word, '/') . '/iu';
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
