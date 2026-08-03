<?php

function csrfToken(string $scope = 'web'): string
{
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/session_bootstrap.php';
        startVfnWebSession();
    }

    $key = 'csrf_' . preg_replace('/[^a-z0-9_-]/i', '', $scope);
    if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }

    return $_SESSION[$key];
}

function csrfIsValid(?string $value, string $scope = 'web'): bool
{
    return is_string($value)
        && $value !== ''
        && hash_equals(csrfToken($scope), $value);
}

function csrfRequire(string $scope = 'web', bool $json = false): void
{
    if (csrfIsValid($_POST['csrf'] ?? null, $scope)) {
        return;
    }

    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'csrf_invalid']);
    } else {
        echo 'Invalid security token.';
    }
    exit;
}
