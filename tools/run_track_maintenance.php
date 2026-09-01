<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../htdocs/execute/config.php';
require __DIR__ . '/../htdocs/includes/track_maintenance.php';

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$result = vfnRunTrackMaintenance($pdo, in_array('--force', $argv, true));
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
