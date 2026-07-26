<?php

session_start();

require_once 'execute/config.php';
require_once 'execute/send_mail.php';
require_once 'includes/auth_security.php';

$language = strtolower(trim($_GET['lang'] ?? $_POST['lang'] ?? 'de'));
$language = $language === 'en' ? 'en' : 'de';
$isGerman = $language === 'de';
$message = '';
$isError = false;

if (empty($_SESSION['password_reset_csrf'])) {
    $_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
}

function passwordResetBaseUrl(): string
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'virtualflightnetwork.com';
    return $scheme . '://' . $host;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    $identifier = trim((string)($_POST['identifier'] ?? ''));

    if (
        !hash_equals((string)$_SESSION['password_reset_csrf'], $csrf)
        || $identifier === ''
    ) {
        $isError = true;
        $message = $isGerman
            ? 'Bitte fülle das Feld vollständig aus.'
            : 'Please complete the field.';
    } else {
        // Always show the same result, whether an account exists or not.
        $message = $isGerman
            ? 'Falls ein passendes Konto existiert, wurde eine E-Mail versendet.'
            : 'If a matching account exists, an email has been sent.';

        try {
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $requestIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            if (
                authRateIsBlocked($pdo, 'password_reset_ip', $requestIp)
                || authRateIsBlocked($pdo, 'password_reset_identifier', $identifier)
            ) {
                throw new RuntimeException('Password reset rate limit reached.');
            }
            authRateFail($pdo, 'password_reset_ip', $requestIp, 5);
            authRateFail($pdo, 'password_reset_identifier', $identifier, 3);

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    request_ip VARCHAR(45) NOT NULL DEFAULT '',
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_password_reset_token_hash (token_hash),
                    KEY idx_password_reset_user_created (user_id, created_at),
                    KEY idx_password_reset_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $stmt = $pdo->prepare(
                "SELECT id, username, email, real_name
                 FROM users
                 WHERE (username = :identifier OR email = :identifier)
                   AND is_active = 1
                   AND email_verified = 1
                 LIMIT 1"
            );
            $stmt->execute(['identifier' => $identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $rateStmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM password_reset_tokens
                     WHERE user_id = :user_id
                       AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE"
                );
                $rateStmt->execute(['user_id' => $user['id']]);

                if ((int)$rateStmt->fetchColumn() === 0) {
                    $plainToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $plainToken);

                    $pdo->prepare(
                        "UPDATE password_reset_tokens
                         SET used_at = UTC_TIMESTAMP()
                         WHERE user_id = :user_id
                           AND used_at IS NULL"
                    )->execute(['user_id' => $user['id']]);

                    $insert = $pdo->prepare(
                        "INSERT INTO password_reset_tokens
                            (user_id, token_hash, expires_at, request_ip)
                         VALUES
                            (:user_id, :token_hash,
                             UTC_TIMESTAMP() + INTERVAL 30 MINUTE, :request_ip)"
                    );
                    $insert->execute([
                        'user_id' => $user['id'],
                        'token_hash' => $tokenHash,
                        'request_ip' => substr(
                            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                            0,
                            45
                        )
                    ]);

                    $resetUrl =
                        passwordResetBaseUrl()
                        . '/reset_password.php?token='
                        . urlencode($plainToken)
                        . '&lang='
                        . urlencode($language);
                    $subject = $isGerman
                        ? 'Passwort zurücksetzen – Virtual Flight Network'
                        : 'Reset your password – Virtual Flight Network';
                    $name = trim((string)($user['real_name'] ?? ''));
                    $greeting = $isGerman ? 'Hallo' : 'Hello';
                    $bodyText = $isGerman
                        ? 'Über diesen Link kannst du innerhalb von 30 Minuten ein neues Passwort festlegen:'
                        : 'Use this link within 30 minutes to choose a new password:';
                    $ignoreText = $isGerman
                        ? 'Falls du das nicht angefordert hast, kannst du diese E-Mail ignorieren.'
                        : 'If you did not request this, you can ignore this email.';

                    $html =
                        '<!doctype html><html><body style="font-family:Arial,sans-serif;background:#07111f;padding:24px;">'
                        . '<div style="max-width:600px;margin:auto;background:#fff;color:#172033;padding:28px;border-radius:10px;">'
                        . '<h2 style="color:#176dcc;">Virtual Flight Network</h2>'
                        . '<p>' . htmlspecialchars($greeting . ' ' . ($name !== '' ? $name : $user['username'])) . ',</p>'
                        . '<p>' . htmlspecialchars($bodyText) . '</p>'
                        . '<p style="margin:28px 0;"><a href="' . htmlspecialchars($resetUrl)
                        . '" style="background:#176dcc;color:#fff;padding:12px 18px;border-radius:6px;text-decoration:none;">'
                        . ($isGerman ? 'Neues Passwort festlegen' : 'Choose new password')
                        . '</a></p><p style="word-break:break-all;">' . htmlspecialchars($resetUrl) . '</p>'
                        . '<p style="color:#667;font-size:13px;">' . htmlspecialchars($ignoreText) . '</p>'
                        . '</div></body></html>';

                    sendMail(
                        (string)$user['email'],
                        $name !== '' ? $name : (string)$user['username'],
                        $subject,
                        $html
                    );
                }
            }
        } catch (Throwable $error) {
            error_log('Password reset request failed: ' . $error->getMessage());
        }
    }
}

?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($language); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo $isGerman ? 'Passwort vergessen' : 'Forgot password'; ?> – VFN</title>
    <style>
        *{box-sizing:border-box} body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;
        font-family:Arial,sans-serif;color:#eaf4ff;background:radial-gradient(circle at top,#12335a,#07111f 50%,#02050a)}
        .card{width:min(460px,100%);padding:30px;border:1px solid #245275;border-radius:12px;background:#0b1926;
        box-shadow:0 20px 70px #0008}.brand{color:#38a5ff;font-weight:bold;letter-spacing:.08em}
        h1{margin:12px 0 10px}p{color:#b9cde0;line-height:1.55}.message{padding:12px;border-radius:7px;background:#123650;color:#dff3ff}
        .error{background:#51202a;color:#ffdce2}label{display:block;margin:20px 0 7px;color:#b9cde0}
        input{width:100%;padding:13px;border:1px solid #285778;border-radius:6px;background:#06111c;color:#fff}
        button{width:100%;margin-top:16px;padding:13px;border:0;border-radius:6px;background:#1678d4;color:#fff;font-weight:bold;cursor:pointer}
        a{color:#49adff}
    </style>
</head>
<body>
<main class="card">
    <div class="brand">VFN NETWORK</div>
    <h1><?php echo $isGerman ? 'Passwort vergessen?' : 'Forgot your password?'; ?></h1>
    <p><?php echo $isGerman
        ? 'Gib deinen Benutzernamen oder deine bestätigte E-Mail-Adresse ein.'
        : 'Enter your username or verified email address.'; ?></p>
    <?php if ($message !== ''): ?>
        <div class="message<?php echo $isError ? ' error' : ''; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['password_reset_csrf']); ?>">
        <input type="hidden" name="lang" value="<?php echo htmlspecialchars($language); ?>">
        <label for="identifier"><?php echo $isGerman ? 'Benutzername oder E-Mail' : 'Username or email'; ?></label>
        <input id="identifier" name="identifier" autocomplete="username" required>
        <button type="submit"><?php echo $isGerman ? 'Reset-Link anfordern' : 'Request reset link'; ?></button>
    </form>
    <p><a href="/"><?php echo $isGerman ? 'Zurück zur Startseite' : 'Back to home'; ?></a></p>
</main>
</body>
</html>
