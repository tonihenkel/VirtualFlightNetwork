<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/htdocs/execute/config.php';

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$column = $pdo->query(
    "SHOW COLUMNS FROM user_sessions LIKE 'is_spectator'"
)->fetch(PDO::FETCH_ASSOC);

if (!$column) {
    $pdo->exec(
        "ALTER TABLE user_sessions
         ADD COLUMN is_spectator TINYINT(1) NOT NULL DEFAULT 0
         AFTER is_invisible"
    );
    echo "column_added\n";
} else {
    echo "column_exists\n";
}
