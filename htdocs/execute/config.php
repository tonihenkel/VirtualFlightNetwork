<?php

// MySQL Datenbank Zugriff
$dbHost = "127.0.0.1";
$dbName = "flight_network";
$dbUser = "root";
//$dbUser = "Administrator";
$dbPass = "";
//$dbPass = "Yakus@nTheGhostRid3r";

// Zeitzonen-Einstellungen
$defaultTimezone = "UTC";
date_default_timezone_set($defaultTimezone);

// Minimale OP Permission, um unsichtbar sein zu können
$minimumInvisibleOpPermission = 1;

// Anzeige der Ratings überall
$showRatings = true;

// Reichweite fuer frequenzgebundene Chat-Nachrichten ausser UNICOM 122.800.
$chatFrequencyRangeNm = 200.0;

// Offizielle METAR-Daten fuer automatische D-ATIS Wetterdaten.
$aviationWeatherMetarCacheUrl =
    "https://aviationweather.gov/data/cache/metars.cache.xml.gz";
$noaaMetarStationBaseUrl =
    "https://tgftp.nws.noaa.gov/data/observations/metar/stations/";
$metarCacheSeconds = 1800;

// Browser-Titelname
$projectName = "Flight Radar Sim Project";

// Download Bereich
$pluginDownloadEnabled = false;

// Echter Dateipfad auf dem Server
$pluginDownloadPath =
    "C:/xampp/htdocs/_downloads_/FlightRadarPlugin_latest.zip";

// URL für den Download Button
$pluginDownloadUrl =
    "execute/download_plugin.php";

// Name der Download-Datei
$pluginDownloadName =
    "FlightRadarPlugin_latest.zip";

// Rechtliche Angaben

$companyName =
    "Flight Radar Sim Project";

$companyOwner =
    "Toni Henkel";

$companyAddress =
    "Solinger Straße 5";

$companyZipCity =
    "08280 Aue";

$companyCountry =
    "Deutschland";

$companyEmail =
    "tonihenkel@mein-postbote.de";
