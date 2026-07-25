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
        PHP hands messages to the local MailEnable SMTP service.
    */

    $fromEmail = "noreply@virtualflightnetwork.com";
    $fromName = "Virtual Flight Network";

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
