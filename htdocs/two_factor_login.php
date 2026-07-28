<?php
session_start();
require_once 'execute/config.php';
require_once 'includes/two_factor.php';
require_once 'includes/language.php';
require_once 'includes/csrf.php';
$twoFactorCsrf = csrfToken('two_factor');

$token = (string)($_SESSION['two_factor_challenge_token'] ?? '');
if ($token === '') {
    header('Location: index.php?type=error&message=login_required');
    exit;
}

$message = '';
$challenge = null;
try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->prepare(
        "SELECT c.id challenge_id, c.user_id, c.code_hash, c.method, c.attempts,
                u.username, u.email, u.real_name, u.password_hash, u.op_permission,
                u.rating_pilot, u.rating_atc, u.rating_special, f.totp_secret
         FROM two_factor_challenges c
         JOIN users u ON u.id = c.user_id
         JOIN user_two_factor f ON f.user_id = c.user_id AND f.method = c.method
         WHERE c.challenge_token_hash = :token_hash
           AND c.used_at IS NULL AND c.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute(['token_hash' => hash('sha256', $token)]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$challenge) {
        unset($_SESSION['two_factor_challenge_token']);
        header('Location: index.php?type=error&message=two_factor_expired');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfIsValid($_POST['csrf'] ?? null, 'two_factor')) {
            $message = t('csrf_invalid');
        } else {
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $valid = $challenge['method'] === 'totp'
            ? twoFactorVerifyTotp((string)$challenge['totp_secret'], $code)
            : hash_equals((string)$challenge['code_hash'], hash('sha256', $code));

        if (!$valid || (int)$challenge['attempts'] >= 5) {
            $pdo->prepare(
                "UPDATE two_factor_challenges SET attempts = attempts + 1 WHERE id = :id"
            )->execute(['id' => $challenge['challenge_id']]);
            $message = t('two_factor_invalid');
        } else {
            $pdo->prepare(
                "UPDATE two_factor_challenges SET used_at = NOW() WHERE id = :id"
            )->execute(['id' => $challenge['challenge_id']]);
            foreach ([
                'web_user_id' => (int)$challenge['user_id'],
                'web_username' => $challenge['username'],
                'web_email' => $challenge['email'],
                'web_real_name' => $challenge['real_name'],
                'web_op_permission' => (int)$challenge['op_permission'],
                'web_rating_pilot' => (int)$challenge['rating_pilot'],
                'web_rating_atc' => (int)$challenge['rating_atc'],
                'web_rating_special' => (int)$challenge['rating_special']
            ] as $key => $value) {
                $_SESSION[$key] = $value;
            }
            $_SESSION['web_auth_fingerprint'] =
                hash('sha256', (string)$challenge['password_hash']);
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")
                ->execute(['id' => $challenge['user_id']]);
            unset($_SESSION['two_factor_challenge_token']);
            $returnTo = (string)($_SESSION['two_factor_return_to'] ?? 'index.php');
            unset($_SESSION['two_factor_return_to']);
            if (preg_match('#^(?:https?:)?//#i', $returnTo)) {
                $returnTo = 'index.php';
            }
            header('Location: ' . $returnTo);
            exit;
        }
        }
    }
} catch (Throwable $e) {
    $message = t('login_server_error');
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($currentLanguage); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>VFN – 2FA</title>
<style>
body{margin:0;background:#071522;color:#e9f3ff;font-family:Arial,sans-serif}
.box{max-width:420px;margin:10vh auto;padding:28px;background:#101e2b;border:1px solid #275071;border-radius:10px}
input,button{box-sizing:border-box;width:100%;padding:13px;margin-top:12px;border-radius:5px}
input{background:#07121d;color:#fff;border:1px solid #3473a5;font-size:20px;letter-spacing:5px;text-align:center}
button{background:#176dcc;color:#fff;border:0;cursor:pointer}.error{color:#ff9898}
</style></head>
<body><main class="box">
<h1><?php echo htmlspecialchars(t('two_factor_title')); ?></h1>
<p><?php echo htmlspecialchars(
    ($challenge['method'] ?? '') === 'email'
        ? t('two_factor_email_hint')
        : t('two_factor_totp_hint')
); ?></p>
<?php if ($message !== ''): ?><p class="error"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($twoFactorCsrf); ?>"><input name="code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required autofocus>
<button><?php echo htmlspecialchars(t('two_factor_verify')); ?></button></form>
</main></body></html>
