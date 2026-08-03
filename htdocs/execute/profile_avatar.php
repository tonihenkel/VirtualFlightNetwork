<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';

function avatarRedirect(string $type, string $message): void
{
    header(
        'Location: ../profile.php?'
        . http_build_query([
            'a' => 'settings',
            'type' => $type,
            'message' => $message,
        ])
    );
    exit;
}

function avatarJsonError(string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    avatarJsonError('method_not_allowed', 405);
}

$action = (string)($_POST['action'] ?? '');
$jsonResponse = $action === 'upload';

if (
    empty($_SESSION['web_user_id'])
    || empty($_SESSION['profile_settings_csrf'])
    || empty($_POST['csrf'])
    || !hash_equals(
        (string)$_SESSION['profile_settings_csrf'],
        (string)$_POST['csrf']
    )
) {
    if ($jsonResponse) {
        avatarJsonError('settings_invalid_request', 403);
    }
    avatarRedirect('error', 'settings_invalid_request');
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    if (!validateVfnWebSession($pdo)) {
        if ($jsonResponse) {
            avatarJsonError('login_required', 401);
        }
        avatarRedirect('error', 'login_required');
    }

    $userId = (int)$_SESSION['web_user_id'];
    $avatarDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR
        . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
    $avatarPath = $avatarDirectory . DIRECTORY_SEPARATOR . $userId . '.jpg';

    if ($action === 'delete') {
        if (is_file($avatarPath) && !unlink($avatarPath)) {
            throw new RuntimeException('avatar_delete_failed');
        }
        avatarRedirect('success', 'settings_avatar_deleted');
    }

    if (
        $action !== 'upload'
        || !isset($_FILES['avatar'])
        || !is_array($_FILES['avatar'])
        || (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_OK
    ) {
        avatarJsonError('settings_avatar_failed', 422);
    }

    $upload = $_FILES['avatar'];
    if ((int)$upload['size'] <= 0 || (int)$upload['size'] > 5 * 1024 * 1024) {
        avatarJsonError('settings_avatar_too_large', 413);
    }

    $temporaryPath = (string)$upload['tmp_name'];
    $imageInfo = @getimagesize($temporaryPath);
    if (
        !$imageInfo
        || !in_array((int)$imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
        || (int)$imageInfo[0] < 64
        || (int)$imageInfo[1] < 64
        || (int)$imageInfo[0] > 8000
        || (int)$imageInfo[1] > 8000
        || (int)$imageInfo[0] * (int)$imageInfo[1] > 40000000
    ) {
        avatarJsonError('settings_avatar_invalid', 422);
    }

    $sourceBytes = file_get_contents($temporaryPath);
    $source = $sourceBytes !== false
        ? @imagecreatefromstring($sourceBytes)
        : false;
    if ($source === false) {
        avatarJsonError('settings_avatar_invalid', 422);
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $side = min($sourceWidth, $sourceHeight);
    $sourceX = (int)floor(($sourceWidth - $side) / 2);
    $sourceY = (int)floor(($sourceHeight - $side) / 2);
    $destination = imagecreatetruecolor(512, 512);
    imagefill($destination, 0, 0, imagecolorallocate($destination, 8, 19, 30));
    imagecopyresampled(
        $destination,
        $source,
        0,
        0,
        $sourceX,
        $sourceY,
        512,
        512,
        $side,
        $side
    );
    imagedestroy($source);

    if (!is_dir($avatarDirectory) && !mkdir($avatarDirectory, 0755, true)) {
        imagedestroy($destination);
        throw new RuntimeException('avatar_directory_failed');
    }

    $temporaryAvatarPath =
        $avatarDirectory . DIRECTORY_SEPARATOR . $userId . '.tmp.jpg';
    if (!imagejpeg($destination, $temporaryAvatarPath, 90)) {
        imagedestroy($destination);
        throw new RuntimeException('avatar_write_failed');
    }
    imagedestroy($destination);

    if (is_file($avatarPath) && !unlink($avatarPath)) {
        @unlink($temporaryAvatarPath);
        throw new RuntimeException('avatar_replace_failed');
    }
    if (!rename($temporaryAvatarPath, $avatarPath)) {
        @unlink($temporaryAvatarPath);
        throw new RuntimeException('avatar_replace_failed');
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'settings_avatar_saved',
        'url' => '../uploads/avatars/' . $userId . '.jpg?v=' . time(),
    ]);
} catch (Throwable $error) {
    error_log('Profile avatar operation failed: ' . $error->getMessage());
    if ($jsonResponse) {
        avatarJsonError('settings_avatar_failed', 500);
    }
    avatarRedirect('error', 'settings_avatar_failed');
}
