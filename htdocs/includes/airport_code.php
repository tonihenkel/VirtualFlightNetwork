<?php
declare(strict_types=1);

/**
 * Normalizes a flight-plan airport identifier.
 *
 * Four-character ICAO identifiers remain valid even if the local airport
 * dataset is temporarily incomplete. Other identifiers (for example
 * DE-0336 or US-xxxx) are accepted only when they exist in the global
 * airports table. Unknown values fall back to ZZZZ.
 */
function vfnNormalizeFlightplanAirport(PDO $pdo, string $value): string
{
    $code = strtoupper(trim($value));
    if ($code === '' || $code === 'ZZZZ') {
        return 'ZZZZ';
    }

    if (preg_match('/^[A-Z0-9]{4}$/', $code)) {
        return $code;
    }

    if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,13}$/', $code)) {
        return 'ZZZZ';
    }

    static $cache = [];
    if (array_key_exists($code, $cache)) {
        return $cache[$code] ? $code : 'ZZZZ';
    }

    $statement = $pdo->prepare(
        'SELECT 1 FROM airports
         WHERE UPPER(ident)=:ident OR UPPER(icao_code)=:icao OR UPPER(gps_code)=:gps
         LIMIT 1'
    );
    $statement->execute(['ident' => $code, 'icao' => $code, 'gps' => $code]);
    $cache[$code] = (bool)$statement->fetchColumn();

    return $cache[$code] ? $code : 'ZZZZ';
}
