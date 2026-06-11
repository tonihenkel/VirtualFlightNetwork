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
        Nur neue Punkte laden,
        die der Browser noch nicht kennt.
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
         WHERE callsign = :callsign
           AND id > :last_id
         ORDER BY id ASC"
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