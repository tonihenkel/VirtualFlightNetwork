<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sourceFile = $root . '/htdocs/lang/en.php';
$source = require $sourceFile;

if (!is_array($source)) {
    fwrite(STDERR, "English language file does not return an array.\n");
    exit(1);
}

$targets = [
    'ar' => 'ar', 'bn' => 'bn', 'zh' => 'zh-CN', 'nl' => 'nl',
    'fr' => 'fr', 'hi' => 'hi', 'id' => 'id', 'it' => 'it',
    'ja' => 'ja', 'ko' => 'ko', 'pl' => 'pl', 'pt' => 'pt',
    'ru' => 'ru', 'es' => 'es', 'tr' => 'tr',
];

$only = array_slice($argv, 1);
if ($only !== []) {
    $targets = array_intersect_key($targets, array_flip($only));
}

function placeholders(string $text): array
{
    preg_match_all('/%(?:[a-zA-Z_][a-zA-Z0-9_]*|\d+\$[a-z])%?|\{[a-zA-Z_][a-zA-Z0-9_]*\}|<[^>]+>|&[a-zA-Z0-9#]+;|https?:\/\/\S+/u', $text, $matches);
    $values = $matches[0] ?? [];
    sort($values);
    return $values;
}

function googleTranslate(string $text, string $target): string
{
    if ($text === '' || !preg_match('/[A-Za-z]/', $text)) {
        return $text;
    }

    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=' .
        rawurlencode($target) . '&dt=t&q=' . rawurlencode($text);

    $lastError = '';
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $json = shell_exec('curl.exe -sS --connect-timeout 8 --max-time 25 ' . escapeshellarg($url));
        if (is_string($json) && $json !== '') {
            $data = json_decode($json, true);
            if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                $translated = '';
                foreach ($data[0] as $part) {
                    if (is_array($part) && isset($part[0])) {
                        $translated .= (string)$part[0];
                    }
                }
                if ($translated !== '') {
                    return $translated;
                }
            }
            $lastError = 'invalid translation response';
        } else {
            $lastError = 'request failed';
        }
        usleep((250 + ($attempt * 500)) * 1000);
    }

    throw new RuntimeException($lastError);
}

function googleTranslateBatch(array $texts, string $target): array
{
    $payload=''; foreach(array_values($texts) as $index=>$text) $payload.=sprintf('[[VFN%04d]]',$index).(string)$text."\n";
    $temporary=tempnam(sys_get_temp_dir(),'vfn_web_translate_'); file_put_contents($temporary,$payload);
    $base='https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl='.rawurlencode($target).'&dt=t';
    $json=shell_exec('curl.exe -sS -G '.escapeshellarg($base).' --data-urlencode '.escapeshellarg('q@'.$temporary)); @unlink($temporary);
    if (!is_string($json) || $json === '') throw new RuntimeException('batch request failed');
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data[0])) throw new RuntimeException('invalid batch response');
    $joined=''; foreach($data[0] as $part) if(is_array($part)&&isset($part[0])) $joined.=(string)$part[0];
    preg_match_all('/\[\[VFN\s*(\d{4})\]\](.*?)(?=\[\[VFN\s*\d{4}\]\]|$)/su',$joined,$parts,PREG_SET_ORDER);
    $result=[]; foreach($parts as $part) $result[(int)$part[1]]=trim($part[2]); ksort($result); $result=array_values($result);
    if (count($result) !== count($texts)) throw new RuntimeException('batch result count mismatch');
    return $result;
}

function exportLanguageFile(string $path, array $translations): void
{
    $output = "<?php\n\nreturn [\n";
    foreach ($translations as $key => $value) {
        $output .= '    ' . var_export((string)$key, true) . ' => ' . var_export((string)$value, true) . ",\n";
    }
    $output .= "];\n";
    file_put_contents($path, $output);
}

foreach ($targets as $code => $googleCode) {
    $targetFile = $root . '/htdocs/lang/' . $code . '.php';
    $existing = is_file($targetFile) ? require $targetFile : [];
    $failureFile = $root . '/tools/translation_failures_' . $code . '.log';
    // Existing non-empty translations are intentionally preserved. A previous
    // diagnostics log must not cause a complete language to be translated again.
    $retryKeys = [];
    $translations = [];
    $failures = [];

    $pending = [];
    foreach ($source as $key => $english) {
        if (isset($existing[$key]) && (string)$existing[$key] !== '' && !isset($retryKeys[$key])) {
            $translations[$key] = (string)$existing[$key];
        } else {
            $pending[$key] = (string)$english;
        }
    }

    $chunks = [];
    $chunk = [];
    $chunkLength = 0;
    foreach ($pending as $key => $english) {
        $lineLength = strlen($english) + 32;
        if ($chunk !== [] && ($chunkLength + $lineLength > 2800 || count($chunk) >= 20)) {
            $chunks[] = $chunk;
            $chunk = [];
            $chunkLength = 0;
        }
        $chunk[$key] = $english;
        $chunkLength += $lineLength;
    }
    if ($chunk !== []) $chunks[] = $chunk;

    foreach ($chunks as $chunkIndex => $entries) {
        try {
            $batchResults = googleTranslateBatch(array_values($entries), $googleCode);
            foreach (array_keys($entries) as $entryIndex => $key) {
                $english = $entries[$key];
                $translated = trim($batchResults[$entryIndex] ?? '');
                if ($translated !== '' && placeholders($translated) === placeholders($english)) {
                    $translations[$key] = $translated;
                    continue;
                }
                try {
                    $translated = googleTranslate($english, $googleCode);
                    if (placeholders($translated) !== placeholders($english)) throw new RuntimeException('placeholder mismatch');
                    $translations[$key] = $translated;
                } catch (Throwable $singleError) {
                    $translations[$key] = $english;
                    $failures[] = $key . ': ' . $singleError->getMessage();
                }
            }
        } catch (Throwable $error) {
            foreach ($entries as $key => $english) {
                try {
                    $translated = googleTranslate($english, $googleCode);
                    if (placeholders($translated) !== placeholders($english)) throw new RuntimeException('placeholder mismatch');
                    $translations[$key] = $translated;
                } catch (Throwable $singleError) {
                    $translations[$key] = $english;
                    $failures[] = $key . ': ' . $singleError->getMessage();
                }
            }
        }
        echo '.';
        usleep(100 * 1000);
    }

    $translations = array_replace($source, $translations);

    exportLanguageFile($targetFile, $translations);
    echo $code . ': ' . count($translations) . ' entries, ' . count($failures) . " fallback(s)\n";
    if ($failures !== []) {
        file_put_contents($failureFile, implode("\n", $failures) . "\n");
    } elseif (is_file($failureFile)) {
        unlink($failureFile);
    }
}
