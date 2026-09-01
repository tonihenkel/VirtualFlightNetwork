<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

$query = trim((string)($_GET['q'] ?? ''));
if (
    mb_strlen($query) < 2
    || mb_strlen($query) > 60
    || !preg_match('/^[\p{L}\p{N} .\-\'’]+$/u', $query)
) {
    echo json_encode(['success' => true, 'airports' => []]);
    exit;
}
$queryUpper = mb_strtoupper($query, 'UTF-8');

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->prepare(
        "SELECT ident, name, municipality, latitude_deg, longitude_deg,
                icao_code, gps_code
         FROM airports
         WHERE ident = :exact
            OR icao_code = :exact
            OR gps_code = :exact
            OR ident LIKE :prefix
            OR icao_code LIKE :prefix
            OR gps_code LIKE :prefix
            OR name LIKE :contains
            OR municipality LIKE :contains
         ORDER BY
            CASE
                WHEN ident = :exact_order
                  OR icao_code = :exact_order
                  OR gps_code = :exact_order THEN 0
                WHEN ident LIKE :prefix_order
                  OR icao_code LIKE :prefix_order
                  OR gps_code LIKE :prefix_order THEN 1
                ELSE 2
            END,
            ident
         LIMIT 8"
    );
    $stmt->execute([
        'exact' => $queryUpper,
        'exact_order' => $queryUpper,
        'prefix' => $queryUpper . '%',
        'prefix_order' => $queryUpper . '%',
        'contains' => '%' . $query . '%',
    ]);

    $airports = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = strtoupper(trim((string)(
            $row['icao_code']
            ?: $row['gps_code']
            ?: $row['ident']
        )));
        $airports[] = [
            'code' => $code,
            'ident' => (string)$row['ident'],
            'name' => (string)$row['name'],
            'municipality' => (string)($row['municipality'] ?? ''),
            'latitude' => (float)$row['latitude_deg'],
            'longitude' => (float)$row['longitude_deg'],
        ];
    }

    echo json_encode(
        ['success' => true, 'airports' => $airports],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $error) {
    error_log('Airport lookup failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'airports' => []]);
}
