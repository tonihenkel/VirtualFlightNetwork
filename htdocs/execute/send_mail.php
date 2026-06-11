<?php

/*
    Flight Radar Sim Project
    Mail System
*/

function sendMail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody
): bool
{
    /*
        SMTP Einstellungen
    */

    $smtpHost = "smtp.example.com";
    $smtpPort = 587;

    $smtpUsername = "noreply@example.com";
    $smtpPassword = "YOUR_SMTP_PASSWORD";

    $fromEmail = "noreply@example.com";
    $fromName = "Flight Radar Sim Project";

    /*
        Header
    */

    $headers = [];

    $headers[] =
        "MIME-Version: 1.0";

    $headers[] =
        "Content-type: text/html; charset=UTF-8";

    $headers[] =
        "From: "
        . $fromName
        . " <"
        . $fromEmail
        . ">";

    $headers[] =
        "Reply-To: "
        . $fromEmail;

    $headers[] =
        "X-Mailer: PHP/" . phpversion();

    /*
        Aktuell:
        PHP mail()

        Später:
        PHPMailer + SMTP
    */

    return mail(
        $toEmail,
        $subject,
        $htmlBody,
        implode("\r\n", $headers)
    );
}