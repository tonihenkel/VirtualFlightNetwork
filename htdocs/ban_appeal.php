<?php

require_once __DIR__ . '/includes/session_bootstrap.php';
startVfnWebSession();
require_once 'execute/config.php';
require_once 'includes/auth_security.php';
require_once 'includes/ban_status.php';
require_once 'includes/language_preferences.php';

$language = strtolower((string)(
    $_GET['lang']
    ?? $_POST['lang']
    ?? $_COOKIE[VFN_LANGUAGE_COOKIE]
    ?? $_SESSION['language']
    ?? 'de'
)) === 'en' ? 'en' : 'de';
$_SESSION['language'] = $language;
vfnStoreLanguageCookie($language);
$de = $language === 'de';
$message = '';
$isError = false;

if (empty($_SESSION['ban_appeal_csrf'])) {
    $_SESSION['ban_appeal_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    $csrf = (string)($_POST['csrf'] ?? '');

    if (
        !hash_equals((string)$_SESSION['ban_appeal_csrf'], $csrf)
        || $identifier === ''
        || $password === ''
        || mb_strlen($reason) < 10
        || mb_strlen($reason) > 2000
    ) {
        $isError = true;
        $message = $de
            ? 'Bitte alle Felder vollständig ausfüllen. Der Grund muss mindestens 10 Zeichen enthalten.'
            : 'Please complete all fields. The reason must contain at least 10 characters.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            if (
                authRateIsBlocked($pdo, 'ban_appeal_ip', $requestIp)
                || authRateIsBlocked($pdo, 'ban_appeal_identifier', $identifier)
            ) {
                throw new RuntimeException('rate_limited');
            }

            $stmt = $pdo->prepare(
                "SELECT id, password_hash
                 FROM users
                 WHERE username = :identifier OR email = :identifier
                 LIMIT 1"
            );
            $stmt->execute(['identifier' => $identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, (string)$user['password_hash'])) {
                authRateFail($pdo, 'ban_appeal_ip', $requestIp, 8);
                authRateFail($pdo, 'ban_appeal_identifier', $identifier, 5);
                throw new RuntimeException('invalid_credentials');
            }

            $banStatus = getActiveBanStatus($pdo, (int)$user['id']);
            if (!$banStatus['active']) {
                throw new RuntimeException('not_banned');
            }

            $pending = $pdo->prepare(
                "SELECT id FROM ban_appeal_requests
                 WHERE user_id = :user_id AND status = 'pending'
                 LIMIT 1"
            );
            $pending->execute(['user_id' => $user['id']]);
            if ($pending->fetchColumn()) {
                throw new RuntimeException('already_pending');
            }

            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO ban_appeal_requests (user_id, reason)
                 VALUES (:user_id, :reason)"
            )->execute(['user_id' => $user['id'], 'reason' => $reason]);
            $pdo->prepare(
                "INSERT INTO user_activity_log
                    (user_id, actor_user_id, activity_type, activity_key, activity_value)
                 VALUES (:user_id, :user_id, 'ban_appeal', 'activity_ban_appeal_requested', :reason)"
            )->execute([
                'user_id' => $user['id'],
                'reason' => mb_substr($reason, 0, 255)
            ]);
            $pdo->commit();
            authRateClear($pdo, 'ban_appeal_identifier', $identifier);
            $message = $de
                ? 'Dein Entbannungsantrag wurde an das Moderationsteam gesendet.'
                : 'Your ban appeal was sent to the moderation team.';
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $isError = true;
            $messages = [
                'rate_limited' => $de ? 'Zu viele Anfragen. Bitte versuche es später erneut.' : 'Too many requests. Please try again later.',
                'invalid_credentials' => $de ? 'Benutzername/E-Mail oder Passwort ist falsch.' : 'Username/email or password is incorrect.',
                'not_banned' => $de ? 'Dieses Konto ist derzeit nicht gebannt.' : 'This account is not currently banned.',
                'already_pending' => $de ? 'Für dieses Konto liegt bereits ein offener Antrag vor.' : 'This account already has a pending appeal.'
            ];
            $message = $messages[$e->getMessage()]
                ?? ($de ? 'Der Antrag konnte nicht gesendet werden.' : 'The appeal could not be sent.');
        }
    }
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($language); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo $de ? 'Entbannungsantrag' : 'Ban appeal'; ?> – VFN</title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Arial,sans-serif;color:#d7e8ff;background:linear-gradient(135deg,#07101d,#071822 50%,#041016)}
        .appeal-card{box-sizing:border-box;width:min(560px,calc(100% - 32px));padding:28px;border:1px solid #285475;border-radius:10px;background:#0b1824;box-shadow:0 20px 70px #0008}
        h1{margin-top:0;color:#fff}.hint{color:#9eb9d7;line-height:1.5}
        label{display:block;margin:14px 0 6px;color:#9ec8e8}
        input,textarea{box-sizing:border-box;width:100%;padding:12px;color:#fff;background:#071521;border:1px solid #285475;border-radius:5px}
        textarea{min-height:150px;resize:vertical}button{width:100%;margin-top:18px;padding:13px;border:0;border-radius:5px;background:#176dcc;color:#fff;font-weight:bold;cursor:pointer}
        .message{padding:12px;margin-bottom:15px;border-radius:5px;background:#164c37;color:#82efb7}.message.error{background:#552129;color:#ff9da5}
        a{color:#49adff}
    </style>
</head>
<body>
<main class="appeal-card">
    <h1><?php echo $de ? 'Entbannungsantrag' : 'Ban appeal'; ?></h1>
    <p class="hint"><?php echo $de
        ? 'Melde dich zur Bestätigung mit deinen Kontodaten an und erläutere deinen Antrag.'
        : 'Confirm your identity with your account credentials and explain your appeal.'; ?></p>
    <?php if ($message !== ''): ?>
        <div class="message <?php echo $isError ? 'error' : ''; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="post" action="ban_appeal.php" id="banAppealForm" novalidate>
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['ban_appeal_csrf']); ?>">
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars($language); ?>">
        <label><?php echo $de ? 'Benutzername oder E-Mail' : 'Username or email'; ?></label>
        <input name="identifier" value="<?php echo htmlspecialchars((string)($_POST['identifier'] ?? '')); ?>"
               autocomplete="username">
        <label><?php echo $de ? 'Passwort' : 'Password'; ?></label>
        <input type="password" name="password" autocomplete="current-password">
        <label><?php echo $de ? 'Begründung des Antrags' : 'Reason for appeal'; ?></label>
        <textarea name="reason" maxlength="2000"><?php
            echo htmlspecialchars((string)($_POST['reason'] ?? ''));
        ?></textarea>
        <button type="submit" id="banAppealSubmit"><?php echo $de ? 'Antrag absenden' : 'Submit appeal'; ?></button>
        <p id="banAppealProgress" class="hint" aria-live="polite"></p>
    </form>
    <p><a href="index.php?lang=<?php echo urlencode($language); ?>"><?php echo $de ? 'Zurück zur Startseite' : 'Back to home'; ?></a></p>
</main>
<script>
document.getElementById('banAppealForm').addEventListener('submit', function() {
    const button = document.getElementById('banAppealSubmit');
    button.disabled = true;
    document.getElementById('banAppealProgress').textContent =
        <?php echo json_encode($de ? 'Antrag wird gesendet …' : 'Submitting appeal …'); ?>;
});
</script>
</body>
</html>
