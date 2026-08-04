<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/admin_auth.php';

try {
    $pdo = createAdminPdo();
    requireAdminUser($pdo, 5);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'method_not_allowed']);
        exit;
    }
    if (empty($_POST['csrf']) || empty($_SESSION['admin_csrf']) ||
        !hash_equals((string)$_SESSION['admin_csrf'], (string)$_POST['csrf'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'invalid_csrf']);
        exit;
    }
    if (empty($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
        throw new RuntimeException('invalid_upload');
    }
    $file = $_FILES['audio'];
    if ((int)$file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] < 1 || (int)$file['size'] > 50 * 1024 * 1024) {
        throw new RuntimeException('invalid_upload');
    }
    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['aac', 'mp3', 'flac'], true)) {
        throw new RuntimeException('invalid_audio_type');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowedMimes = ['audio/aac', 'audio/x-aac', 'audio/mpeg', 'audio/flac', 'audio/x-flac', 'application/octet-stream'];
    if (!in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('invalid_audio_type');
    }
    $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'voice-service' . DIRECTORY_SEPARATOR . 'test-audio';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('upload_failed');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $name)) {
        throw new RuntimeException('upload_failed');
    }
    echo json_encode(['success' => true, 'fileName' => $name, 'originalName' => basename((string)$file['name'])]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
