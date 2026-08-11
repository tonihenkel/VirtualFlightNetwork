<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/session_bootstrap.php';
startVfnWebSession();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/web_session.php';
require_once __DIR__ . '/../includes/atc_schema.php';
require_once __DIR__ . '/../includes/atc_atis_scope.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    if (empty($_SESSION['web_user_id']) || !validateVfnWebSession($pdo)) {
        http_response_code(401); throw new RuntimeException('login_required');
    }
    ensureAtcSchema($pdo);
    $stmt = $pdo->prepare(
        "SELECT station_code,position_code,radar_boundary_code,is_spectator,can_control,user_id
         FROM atc_sessions WHERE user_id=:user AND session_token=:token AND is_active=1
           AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND) LIMIT 1"
    );
    $stmt->execute([
        'user'=>(int)$_SESSION['web_user_id'],
        'token'=>(string)($_SESSION['atc_session_token'] ?? ''),
    ]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) { http_response_code(409); throw new RuntimeException('atc_session_inactive'); }

    $south = max(-90.0, min(90.0, (float)($_GET['south'] ?? -90)));
    $north = max(-90.0, min(90.0, (float)($_GET['north'] ?? 90)));
    $west = max(-180.0, min(180.0, (float)($_GET['west'] ?? -180)));
    $east = max(-180.0, min(180.0, (float)($_GET['east'] ?? 180)));
    if ($south > $north) [$south, $north] = [$north, $south];
    if ($west > $east) [$west, $east] = [$east, $west];

    $position = strtoupper((string)$session['position_code']);
    $features = in_array($position, ['APP', 'DEP', 'CTR'], true)
        ? readAtisScopeFeatures($session) : [];
    if (!$features) {
        echo json_encode(['success'=>true, 'airports'=>[]]); exit;
    }
    $query = $pdo->prepare(
        "SELECT ident,icao_code,gps_code,name,latitude_deg,longitude_deg
         FROM airports
         WHERE latitude_deg BETWEEN :south AND :north
           AND longitude_deg BETWEEN :west AND :east
         ORDER BY type IN ('large_airport','medium_airport') DESC, ident
         LIMIT 500"
    );
    $query->execute(['south'=>$south, 'north'=>$north, 'west'=>$west, 'east'=>$east]);
    $layoutDir = dirname(__DIR__) . '/data/airport_layouts';
    $airports = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $inside = false;
        foreach ($features as $feature) {
            if (pointInAtisGeometry(
                (float)$row['longitude_deg'], (float)$row['latitude_deg'],
                $feature['geometry'] ?? []
            )) { $inside = true; break; }
        }
        if (!$inside) continue;
        $candidates = array_unique(array_filter(array_map(static function ($value): string {
            return strtoupper(trim((string)$value));
        }, [$row['ident'] ?? '', $row['icao_code'] ?? '', $row['gps_code'] ?? ''])));
        $identifier = '';
        foreach ($candidates as $candidate) {
            if (preg_match('/^[A-Z0-9-]{2,16}$/', $candidate)
                && is_file($layoutDir . '/' . $candidate . '.json')) {
                $identifier = $candidate; break;
            }
        }
        if ($identifier === '') continue;
        $airports[$identifier] = [
            'icao'=>$identifier,
            'name'=>(string)$row['name'],
            'latitude'=>(float)$row['latitude_deg'],
            'longitude'=>(float)$row['longitude_deg'],
        ];
    }
    echo json_encode(['success'=>true, 'airports'=>array_values($airports)],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>$error->getMessage()], JSON_UNESCAPED_UNICODE);
}
