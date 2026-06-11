<?php

/*
    Flight Radar Sim Project
    Language System
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
    Default-Sprache
*/

$defaultLanguage =
    'en';

/*
    Unterstützte Sprachen
*/

$allowedLanguages = [
    'en',
    'de'
];

/*
    Sprache aus URL übernehmen
    Beispiel:
    ?lang=de
*/

if (isset($_GET['lang'])) {

    $requestedLanguage =
        strtolower(
            trim($_GET['lang'])
        );

    if (
        in_array(
            $requestedLanguage,
            $allowedLanguages,
            true
        )
    ) {
        $_SESSION['language'] =
            $requestedLanguage;
    }
}

/*
    Sprache aus Session laden
*/

$currentLanguage =
    $_SESSION['language']
    ?? $defaultLanguage;

/*
    Sicherheitsprüfung
*/

if (
    !in_array(
        $currentLanguage,
        $allowedLanguages,
        true
    )
) {
    $currentLanguage =
        $defaultLanguage;
}

/*
    Sprachdatei zusammensetzen
*/

$languageFile =
    __DIR__
    . '/../lang/'
    . $currentLanguage
    . '.php';

/*
    Falls Datei fehlt:
    auf Englisch zurückfallen
*/

if (!file_exists($languageFile)) {

    $languageFile =
        __DIR__
        . '/../lang/'
        . $defaultLanguage
        . '.php';
}

/*
    Spracharray laden
*/

$lang =
    require $languageFile;

/*
    Kleine Hilfsfunktion
*/

function t(string $key): string
{
    global $lang;

    return $lang[$key]
        ?? $key;
}