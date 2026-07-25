<?php

session_start();

require_once 'execute/config.php';

$language = strtolower(trim($_GET['lang'] ?? $_POST['lang'] ?? 'de'));
$language = $language === 'en' ? 'en' : 'de';
$isGerman = $language === 'de';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$message = '';
$success = false;

if (empty($_SESSION['password_change_csrf'])) {
    $_SESSION['password_change_csrf'] = bin2hex(random_bytes(32));
}

function findPasswordReset(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT id, user_id
         FROM password_reset_tokens
         WHERE token_hash = :token_hash
           AND used_at IS NULL
           AND expires_at > UTC_TIMESTAMP()
         LIMIT 1"
    );
    $stmt->execute(['token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $reset = findPasswordReset($pdo, $token);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string)($_POST['csrf'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $passwordRepeat = (string)($_POST['password_repeat'] ?? '');

        if (!hash_equals((string)$_SESSION['password_change_csrf'], $csrf)) {
            $message = $isGerman ? 'Ungültige Anfrage.' : 'Invalid request.';
        } elseif (!$reset) {
            $message = $isGerman
                ? 'Der Link ist ungültig oder abgelaufen.'
                : 'This link is invalid or has expired.';
        } elseif (strlen($password) < 8) {
            $message = $isGerman
                ? 'Das Passwort muss mindestens 8 Zeichen lang sein.'
                : 'The password must be at least 8 characters long.';
        } elseif ($password !== $passwordRepeat) {
            $message = $isGerman
                ? 'Die Passwörter stimmen nicht überein.'
                : 'The passwords do not match.';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE users SET password_hash = :password_hash WHERE id = :user_id"
            )->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'user_id' => $reset['user_id']
            ]);
            $pdo->prepare(
                "UPDATE password_reset_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id AND used_at IS NULL"
            )->execute(['user_id' => $reset['user_id']]);
            $pdo->prepare(
                "UPDATE user_sessions
                 SET is_active = 0
                 WHERE user_id = :user_id"
            )->execute(['user_id' => $reset['user_id']]);
            $pdo->commit();
            $success = true;
            $message = $isGerman
                ? 'Dein Passwort wurde geändert. Du kannst dich jetzt wieder anmelden.'
                : 'Your password has been changed. You can now sign in again.';
            unset($_SESSION['password_change_csrf']);
        }
    } elseif (!$reset) {
        $message = $isGerman
            ? 'Der Link ist ungültig oder abgelaufen.'
            : 'This link is invalid or has expired.';
    }
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Password reset failed: ' . $error->getMessage());
    $reset = null;
    $message = $isGerman ? 'Serverfehler.' : 'Server error.';
}

?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($language); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo $isGerman ? 'Neues Passwort' : 'New password'; ?> – VFN</title>
    <style>
        *{box-sizing:border-box} body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;
        font-family:Arial,sans-serif;color:#eaf4ff;background:radial-gradient(circle at top,#12335a,#07111f 50%,#02050a)}
        .card{width:min(460px,100%);padding:30px;border:1px solid #245275;border-radius:12px;background:#0b1926;
        box-shadow:0 20px 70px #0008}.brand{color:#38a5ff;font-weight:bold;letter-spacing:.08em}
        p{color:#b9cde0;line-height:1.55}.message{padding:12px;border-radius:7px;background:#123650;color:#dff3ff}
        label{display:block;margin:16px 0 7px;color:#b9cde0}input{width:100%;padding:13px;border:1px solid #285778;
        border-radius:6px;background:#06111c;color:#fff}button{width:100%;margin-top:18px;padding:13px;border:0;border-radius:6px;
        background:#1678d4;color:#fff;font-weight:bold;cursor:pointer}a{color:#49adff}
    </style>
</head>
<body>
<main class="card">
    <div class="brand">VFN NETWORK</div>
    <h1><?php echo $isGerman ? 'Neues Passwort' : 'Choose a new password'; ?></h1>
    <?php if ($message !== ''): ?><div class="message"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!$success && !empty($reset)): ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['password_change_csrf']); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="lang" value="<?php echo htmlspecialchars($language); ?>">
            <label for="password"><?php echo $isGerman ? 'Neues Passwort' : 'New password'; ?></label>
            <input id="password" type="password" name="password" minlength="8" autocomplete="new-password" required>
            <label for="password_repeat"><?php echo $isGerman ? 'Passwort wiederholen' : 'Repeat password'; ?></label>
            <input id="password_repeat" type="password" name="password_repeat" minlength="8" autocomplete="new-password" required>
            <button type="submit"><?php echo $isGerman ? 'Passwort speichern' : 'Save password'; ?></button>
        </form>
    <?php endif; ?>
    <p><a href="/"><?php echo $isGerman ? 'Zurück zur Startseite' : 'Back to home'; ?></a></p>
</main>
</body>
</html>
