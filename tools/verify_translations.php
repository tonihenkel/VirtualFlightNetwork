<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$catalog = require $root . '/htdocs/includes/languages.php';
$languages = vfnLanguages();
$english = require $root . '/htdocs/lang/en.php';
$failed = false;

function translationTokens(string $text): array
{
    preg_match_all('/%(?:[a-zA-Z_][a-zA-Z0-9_]*|\d+\$[a-z])%?|\{[a-zA-Z_][a-zA-Z0-9_]*\}|<[^>]+>|&[a-zA-Z0-9#]+;/u', $text, $matches);
    $tokens = $matches[0] ?? [];
    sort($tokens);
    return $tokens;
}

foreach ($languages as $code => $meta) {
    $path = $root . '/htdocs/lang/' . $code . '.php';
    if (!is_file($path)) {
        fwrite(STDERR, "$code: file missing\n");
        $failed = true;
        continue;
    }
    $current = require $path;
    $missing = array_diff_key($english, $current);
    $extra = array_diff_key($current, $english);
    $placeholderErrors = [];
    foreach ($english as $key => $value) {
        if (isset($current[$key]) && translationTokens((string)$value) !== translationTokens((string)$current[$key])) {
            $placeholderErrors[] = $key;
        }
    }
    printf("%-3s keys=%d missing=%d extra=%d placeholders=%d\n", $code, count($current), count($missing), count($extra), count($placeholderErrors));
    if ($missing || $extra || $placeholderErrors) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
