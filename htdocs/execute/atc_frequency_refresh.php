<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_frequency_catalog.php';
require_once __DIR__ . '/../includes/job_status.php';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (PHP_SAPI !== 'cli') {
        if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
            http_response_code(401);
            throw new RuntimeException('login_required');
        }
        $stmt = $pdo->prepare('SELECT op_permission FROM users WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => (int)$_SESSION['web_user_id']]);
        if ((int)$stmt->fetchColumn() < 5) {
            http_response_code(403);
            throw new RuntimeException('permission_denied');
        }
    }
    $result = refreshAtcFrequencyCatalog($pdo);
    vfnRecordJobStatus($pdo, 'atc_frequency_refresh', true, json_encode($result));
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO) {
        vfnRecordJobStatus($pdo, 'atc_frequency_refresh', false, $error->getMessage());
    }
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
