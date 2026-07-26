<?php

function twoFactorBase32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $result = '';
    foreach (str_split($bits, 5) as $chunk) {
        $result .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $result;
}

function twoFactorBase32Decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret))) as $char) {
        $position = strpos($alphabet, $char);
        if ($position === false) {
            return '';
        }
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }
    $result = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $result .= chr(bindec($chunk));
        }
    }
    return $result;
}

function twoFactorGenerateSecret(): string
{
    return twoFactorBase32Encode(random_bytes(20));
}

function twoFactorTotp(string $secret, ?int $timestamp = null): string
{
    $counter = intdiv($timestamp ?? time(), 30);
    $binaryCounter = pack('N2', 0, $counter);
    $hash = hash_hmac('sha1', $binaryCounter, twoFactorBase32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value =
        ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function twoFactorVerifyTotp(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    foreach ([-30, 0, 30] as $offset) {
        if (hash_equals(twoFactorTotp($secret, time() + $offset), $code)) {
            return true;
        }
    }
    return false;
}
