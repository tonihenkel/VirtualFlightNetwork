<?php
session_start();

$returnTo =
    $_GET['return_to']
    ?? $_SERVER['HTTP_REFERER']
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

$_SESSION = [];

session_destroy();

$separator =
    strpos($returnTo, '?') !== false
    ? '&'
    : '?';

header(
    'Location: '
    . $returnTo
    . $separator
    . http_build_query([
        'type' => 'success',
        'message' => 'logout_success'
    ])
);

exit;