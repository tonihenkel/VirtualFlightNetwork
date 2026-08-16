<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$source = file_get_contents($root . '/Flight Radar Sim Projekt/main.cpp');
$outputDirectory = $root . '/Flight Radar Sim Projekt/resources/languages';
if (!is_dir($outputDirectory)) mkdir($outputDirectory, 0775, true);

foreach (['en' => 'enFile', 'de' => 'deFile'] as $code => $variable) {
    preg_match_all('~' . $variable . '\s*<<\s*"((?:[^"\\\\]|\\\\.)*)"\s*;~s', $source, $matches);
    $lines = [];
    foreach ($matches[1] ?? [] as $encoded) {
        $decoded = stripcslashes($encoded);
        foreach (preg_split('/\r?\n/', $decoded) as $line) {
            if ($line !== '' && strpos($line, '=') !== false) $lines[] = $line;
        }
    }
    file_put_contents($outputDirectory . '/' . $code . '.txt', implode("\n", $lines) . "\n");
    echo "$code: " . count($lines) . " entries\n";
}
