<?php
session_start();

require_once 'execute/config.php';
require_once 'execute/send_mail.php';

$registerLanguage =
    $_POST['language']
    ?? $_GET['lang']
    ?? $_SESSION['language']
    ?? 'en';

$registerLanguage =
    strtolower(
        trim($registerLanguage)
    );

if (
    $registerLanguage !== 'de' &&
    $registerLanguage !== 'en'
) {
    $registerLanguage = 'en';
}

$_GET['lang'] =
    $registerLanguage;

require_once 'includes/language.php';

function redirectBack(string $type, string $message): void
{
    $returnTo =
        $_POST['return_to']
        ?? $_GET['return_to']
        ?? 'index.php';

    if (
        !is_string($returnTo) ||
        $returnTo === '' ||
        strpos($returnTo, 'http://') === 0 ||
        strpos($returnTo, 'https://') === 0 ||
        strpos($returnTo, '//') === 0
    ) {
        $returnTo = 'index.php';
    }

    $separator =
        strpos($returnTo, '?') !== false
        ? '&'
        : '?';

    header(
        'Location: '
        . $returnTo
        . $separator
        . http_build_query([
            'type' => $type,
            'message' => $message
        ])
    );

    exit;
}

function createVerifyToken(): string
{
    return bin2hex(
        random_bytes(32)
    );
}

function getBaseUrl(): string
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    $scheme =
        $https ? 'https' : 'http';

    $host =
        $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectBack('error', 'request_invalid');
}

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$realName = trim($_POST['real_name'] ?? '');
$countryCode = strtoupper(trim($_POST['country_code'] ?? ''));
$divisionCode = strtoupper(trim($_POST['division_code'] ?? ''));
$password = $_POST['password'] ?? '';
$passwordRepeat = $_POST['password_repeat'] ?? '';

if (
    $username === '' ||
    $email === '' ||
    $realName === '' ||
    $password === '' ||
    $passwordRepeat === ''
) {
    redirectBack('error', 'register_fields_required');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirectBack('error', 'register_email_invalid');
}

if ($password !== $passwordRepeat) {
    redirectBack('error', 'register_passwords_not_equal');
}

if (strlen($password) < 6) {
    redirectBack('error', 'register_password_too_short');
}

if (strlen($username) < 3 || strlen($username) > 50) {
    redirectBack('error', 'register_username_length');
}

if (strlen($realName) < 2 || strlen($realName) > 100) {
    redirectBack('error', 'register_realname_length');
}

$countries = require 'includes/countries.php';

if (!isset($countries[$countryCode])) {
    redirectBack('error', 'register_country_invalid');
}

if ($divisionCode === '') {
    redirectBack('error', 'register_division_invalid');
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $stmt = $pdo->prepare(
        "SELECT id
         FROM users
         WHERE username = :username
            OR email = :email
         LIMIT 1"
    );

    $stmt->execute([
        'username' => $username,
        'email' => $email
    ]);

    if ($stmt->fetch()) {
        redirectBack('error', 'register_user_exists');
    }

    $passwordHash =
        password_hash(
            $password,
            PASSWORD_DEFAULT
        );

    $verifyToken =
        createVerifyToken();

    $stmt = $pdo->prepare(
        "INSERT INTO users
            (
                username,
                email,
                real_name,
                country_code,
                division_code,
                password_hash,
                email_verified,
                is_active,
                email_verify_token,
                email_verify_created_at,
                op_permission,
                rating_pilot,
                rating_atc,
                rating_special,
                total_flight_seconds,
                total_flight_miles,
                created_at
            )
         VALUES
            (
                :username,
                :email,
                :real_name,
                :country_code,
                :division_code,
                :password_hash,
                0,
                1,
                :email_verify_token,
                NOW(),
                0,
                0,
                0,
                0,
                0,
                0.00,
                NOW()
            )"
    );

    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'real_name' => $realName,
        'country_code' => $countryCode,
        'division_code' => $divisionCode,
        'password_hash' => $passwordHash,
        'email_verify_token' => $verifyToken
    ]);

    $verifyUrl =
        getBaseUrl()
        . '/verify_email.php?token='
        . urlencode($verifyToken)
        . '&lang='
        . urlencode($registerLanguage);

    $subject =
        t('register_mail_subject');

    $htmlBody =
        '<!DOCTYPE html>
        <html lang="' . htmlspecialchars($registerLanguage) . '">
        <head>
            <meta charset="UTF-8">
            <title>' . htmlspecialchars(t('register_mail_subject')) . '</title>
        </head>
        <body style="font-family:Arial,sans-serif;background:#f2f4f8;padding:20px;">
            <div style="max-width:600px;margin:auto;background:white;border-radius:10px;padding:25px;">
                <h2 style="color:#1737a6;">Flight Radar Sim Project</h2>

                <p>' . htmlspecialchars(t('register_mail_greeting')) . ' ' . htmlspecialchars($realName) . ',</p>

                <p>
                    ' . htmlspecialchars(t('register_mail_text1')) . '
                </p>

                <p style="margin:30px 0;">
                    <a href="' . htmlspecialchars($verifyUrl) . '"
                       style="background:#1d6cff;color:white;padding:12px 18px;border-radius:6px;text-decoration:none;font-weight:bold;">
                        ' . htmlspecialchars(t('register_mail_button')) . '
                    </a>
                </p>

                <p>
                    ' . htmlspecialchars(t('register_mail_text2')) . '
                </p>

                <p style="word-break:break-all;color:#1737a6;">
                    ' . htmlspecialchars($verifyUrl) . '
                </p>

                <p style="color:#777;font-size:12px;">
                    ' . htmlspecialchars(t('register_mail_text3')) . '
                </p>
            </div>
        </body>
        </html>';

    $mailSent =
        sendMail(
            $email,
            $realName,
            $subject,
            $htmlBody
        );

    if (!$mailSent) {
        redirectBack(
            'success',
            'register_success_mail_failed'
        );
    }

    redirectBack(
        'success',
        'register_success_verify_email'
    );
} catch (Exception $e) {
    redirectBack('error', 'register_server_error');
}