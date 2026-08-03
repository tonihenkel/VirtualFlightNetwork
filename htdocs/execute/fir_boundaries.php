<?php
declare(strict_types=1);

$sourcePath = dirname(__DIR__) . '/data/atc/fir-boundaries.geojson';

if (!is_file($sourcePath) || !is_readable($sourcePath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'fir_boundaries_unavailable',
    ]);
    exit;
}

$modifiedAt = (int)filemtime($sourcePath);
$etag = '"' . hash_file('sha256', $sourcePath) . '"';

header('Content-Type: application/geo+json; charset=utf-8');
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

header('Content-Length: ' . (string)filesize($sourcePath));
readfile($sourcePath);
