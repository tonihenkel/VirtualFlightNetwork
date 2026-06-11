<?php

session_start();

require_once 'config.php';

function redirectWithError(string $message): void
{
    header(
        'Location: ../index.php?'
        . http_build_query([
            'type' => 'error',
            'message' => $message
        ])
    );

    exit;
}

$pluginDownloadEnabled =
    $pluginDownloadEnabled ?? false;

$pluginDownloadPath =
    $pluginDownloadPath ?? '';

$pluginDownloadName =
    $pluginDownloadName ?? 'FlightRadarPlugin_latest.zip';

if ($pluginDownloadEnabled !== true) {
    redirectWithError(
        'Der Plugin-Download ist aktuell gesperrt.'
    );
}

if (
    $pluginDownloadPath === '' ||
    !file_exists($pluginDownloadPath) ||
    !is_file($pluginDownloadPath)
) {
    redirectWithError(
        'Die Plugin-Datei wurde nicht gefunden.'
    );
}

if (!is_readable($pluginDownloadPath)) {
    redirectWithError(
        'Die Plugin-Datei kann nicht gelesen werden.'
    );
}

$fileSize =
    filesize($pluginDownloadPath);

if ($fileSize === false || $fileSize <= 0) {
    redirectWithError(
        'Die Plugin-Datei ist leer oder fehlerhaft.'
    );
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/octet-stream');
header(
    'Content-Disposition: attachment; filename="'
    . basename($pluginDownloadName)
    . '"'
);
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$handle =
    fopen($pluginDownloadPath, 'rb');

if ($handle === false) {
    redirectWithError(
        'Die Plugin-Datei konnte nicht geöffnet werden.'
    );
}

while (!feof($handle)) {
    echo fread($handle, 1024 * 1024);
    flush();
}

fclose($handle);
exit;
