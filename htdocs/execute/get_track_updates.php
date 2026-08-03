<?php
header("Content-Type: application/json; charset=utf-8");

require_once 'config.php';

$callsign = strtoupper(
    trim($_GET["callsign"] ?? "")
);

$lastId = (int)(
    $_GET["last_id"] ?? 0
);

if ($callsign === "") {
    echo json_encode([
        "success" => false,
        "message" => "Kein Callsign uebergeben."
    ]);

    exit;
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

    /*
        Resolve the currently active simulator session first. A callsign can
        be reused over many flights and pilot_tracks intentionally keeps the
        session token. Filtering by callsign alone mixes historic flights and
        can also leave the map cursor pointing into an older route.
    */
    $sessionStmt = $pdo->prepare(
        "SELECT p.session_token
         FROM pilot_positions p
         INNER JOIN user_sessions s
            ON s.token = p.session_token
         WHERE p.callsign = :callsign
           AND s.is_active = 1
         ORDER BY p.last_update DESC
         LIMIT 1"
    );
    $sessionStmt->execute([
        "callsign" => $callsign
    ]);
    $activeSessionToken =
        (string)($sessionStmt->fetchColumn() ?: "");

    if ($activeSessionToken === "") {
        echo json_encode([
            "success" => true,
            "callsign" => $callsign,
            "last_id" => $lastId,
            "points" => []
        ]);
        exit;
    }

    /*
        Only load points from this flight/session that the browser does not
        know yet.
    */
    $stmt = $pdo->prepare(
        "SELECT
                id,
                latitude,
                longitude,
                altitude,
                heading,
                created_at
         FROM pilot_tracks
         WHERE session_token = :session_token
           AND callsign = :callsign
           AND id > :last_id
         ORDER BY id ASC"
    );

    $stmt->bindValue(
        ":session_token",
        $activeSessionToken,
        PDO::PARAM_STR
    );

    $stmt->bindValue(
        ":callsign",
        $callsign,
        PDO::PARAM_STR
    );

    $stmt->bindValue(
        ":last_id",
        $lastId,
        PDO::PARAM_INT
    );

    $stmt->execute();

    $trackPoints =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

    $newLastId = $lastId;

    if (!empty($trackPoints)) {

        $lastPoint =
            end($trackPoints);

        $newLastId =
            (int)$lastPoint["id"];
    }

    echo json_encode([
        "success" => true,
        "callsign" => $callsign,
        "last_id" => $newLastId,
        "points" => $trackPoints
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Serverfehler.",
        "error" => $e->getMessage()
    ]);
}
