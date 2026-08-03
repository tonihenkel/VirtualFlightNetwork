<?php

/*
    Flight Radar Sim Project
    Language System
*/

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/language_preferences.php';
startVfnWebSession();

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
        vfnStoreLanguageCookie($requestedLanguage);

        if (!empty($_SESSION['web_user_id'])) {
            try {
                if (!isset($dbHost, $dbName, $dbUser, $dbPass)) {
                    require __DIR__ . '/../execute/config.php';
                }
                $languagePdo = new PDO(
                    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                vfnSaveUserLanguage(
                    $languagePdo,
                    (int)$_SESSION['web_user_id'],
                    $requestedLanguage
                );
            } catch (Throwable $languageError) {
                error_log(
                    'Could not save VFN language preference: '
                    . $languageError->getMessage()
                );
            }
        }
    }
}

if (
    !isset($_GET['lang'])
    && empty($_SESSION['language'])
    && !empty($_SESSION['web_user_id'])
) {
    try {
        if (!isset($dbHost, $dbName, $dbUser, $dbPass)) {
            require __DIR__ . '/../execute/config.php';
        }
        $languagePdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $accountLanguage = vfnLoadUserLanguage(
            $languagePdo,
            (int)$_SESSION['web_user_id']
        );
        if ($accountLanguage !== '') {
            $_SESSION['language'] = $accountLanguage;
            vfnStoreLanguageCookie($accountLanguage);
        }
    } catch (Throwable $languageError) {
        error_log(
            'Could not load VFN language preference: '
            . $languageError->getMessage()
        );
    }
}

/*
    Sprache aus Session laden
*/

$currentLanguage =
    $_SESSION['language']
    ?? vfnNormalizeLanguage($_COOKIE[VFN_LANGUAGE_COOKIE] ?? '')
    ?? $defaultLanguage;

if ($currentLanguage === '') {
    $currentLanguage = $defaultLanguage;
}

$_SESSION['language'] = $currentLanguage;
vfnStoreLanguageCookie($currentLanguage);

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
