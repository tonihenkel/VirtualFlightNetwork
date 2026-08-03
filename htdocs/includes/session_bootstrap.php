<?php

const VFN_WEB_SESSION_LIFETIME = 2592000; // 30 days

function startVfnWebSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', (string)VFN_WEB_SESSION_LIFETIME);
        ini_set('session.cookie_lifetime', (string)VFN_WEB_SESSION_LIFETIME);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Lax');

        session_set_cookie_params([
            'lifetime' => VFN_WEB_SESSION_LIFETIME,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // Sliding expiration: every page request extends the cookie by 30 days.
    if (
        session_status() === PHP_SESSION_ACTIVE
        && session_id() !== ''
        && !headers_sent()
    ) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + VFN_WEB_SESSION_LIFETIME,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
