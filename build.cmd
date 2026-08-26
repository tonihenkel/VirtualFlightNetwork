@echo off
setlocal

set "MSBUILD=C:\BuildTools\MSBuild\Current\Bin\MSBuild.exe"
set "SOLUTION=%~dp0Flight Radar Sim Projekt.slnx"
set "TARGET=Build"
set "VERSION_FILE=%~dp0VERSION"
set "VERSION_HEADER=%~dp0Flight Radar Sim Projekt\version_generated.h"
set "DOWNLOAD_DIR=%~dp0htdocs\_downloads_"
set "XPL_FILE=%DOWNLOAD_DIR%\Flight Radar Sim Projekt.xpl"
set "LINUX_XPL_FILE=%DOWNLOAD_DIR%\lin.xpl"
set "RESOURCE_DIR=%~dp0Flight Radar Sim Projekt\resources"
set "CSL_DOWNLOADER_DIR=%~dp0release_assets\CSL Downloader"
set "ZIP_FILE=%DOWNLOAD_DIR%\_FlightRadarPlugin_latest.zip"
set "HASH_FILE=%ZIP_FILE%.sha256"
set "PACKAGE_SCRIPT=%~dp0package_release.ps1"

if /I "%~1"=="rebuild" set "TARGET=Rebuild"

if not exist "%MSBUILD%" (
    echo Fehler: MSBuild wurde unter "%MSBUILD%" nicht gefunden.
    exit /b 1
)

if not exist "%VERSION_FILE%" (
    echo Fehler: VERSION-Datei fehlt.
    exit /b 1
)

set /p PLUGIN_VERSION=<"%VERSION_FILE%"
if "%PLUGIN_VERSION%"=="" (
    echo Fehler: VERSION-Datei ist leer.
    exit /b 1
)

> "%VERSION_HEADER%" echo #pragma once
>> "%VERSION_HEADER%" echo #define VFN_PLUGIN_VERSION "%PLUGIN_VERSION%"

"%MSBUILD%" "%SOLUTION%" /m /t:%TARGET% /p:Configuration=Release /p:Platform=x64 /v:minimal
if errorlevel 1 exit /b %ERRORLEVEL%

if not exist "%LINUX_XPL_FILE%" (
    echo Fehler: Linux-Binaerdatei fehlt: "%LINUX_XPL_FILE%"
    echo Ein Release wird nur mit win.xpl und lin.xpl erzeugt.
    echo Fuehre zuerst build-linux.cmd aus.
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%PACKAGE_SCRIPT%" -XplFile "%XPL_FILE%" -LinuxXplFile "%LINUX_XPL_FILE%" -ResourceDir "%RESOURCE_DIR%" -CslDownloaderDir "%CSL_DOWNLOADER_DIR%" -ZipFile "%ZIP_FILE%" -HashFile "%HASH_FILE%"
if errorlevel 1 exit /b %ERRORLEVEL%

echo Release v%PLUGIN_VERSION% erstellt:
echo   %XPL_FILE%
echo   %ZIP_FILE%
echo   %HASH_FILE%
exit /b 0
