<?php
declare(strict_types=1);

$sourcePath = dirname(__DIR__) . '/data/atc/VATSpy.dat';
header('Content-Type: application/json; charset=utf-8');

if (!is_file($sourcePath) || !is_readable($sourcePath)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'names' => []]);
    exit;
}

$modifiedAt = (int)filemtime($sourcePath);
$etag = '"' . hash('sha256', hash_file('sha256', $sourcePath) . '|schema-2') . '"';
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT');

$clientEtag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$clientModified = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
if (
    ($clientEtag !== '' && hash_equals($etag, $clientEtag))
    || ($clientModified !== false && $clientModified >= $modifiedAt)
) {
    http_response_code(304);
    exit;
}

$names = [];
$types = [];
$section = '';
$handle = fopen($sourcePath, 'rb');
if ($handle !== false) {
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if (preg_match('/^\[([A-Za-z]+)\]$/', $line, $match)) {
            $section = strtoupper($match[1]);
            continue;
        }
        if (
            ($section !== 'FIRS' && $section !== 'UIRS')
            || $line === ''
            || substr($line, 0, 1) === ';'
        ) {
            continue;
        }
        $columns = explode('|', $line);
        $identifier = strtoupper(trim((string)($columns[0] ?? '')));
        $name = trim((string)($columns[1] ?? ''));
        if ($identifier !== '' && $name !== '') {
            $names[$identifier] = $name;
            $types[$identifier] = $section === 'UIRS' ? 'UIR' : 'FIR';
        }
    }
    fclose($handle);
}

echo json_encode(
    ['success' => true, 'names' => $names, 'types' => $types],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
