@echo off
setlocal

set "MSBUILD=C:\BuildTools\MSBuild\Current\Bin\MSBuild.exe"
set "SOLUTION=%~dp0Flight Radar Sim Projekt.slnx"
set "TARGET=Build"
set "VERSION_FILE=%~dp0VERSION"
set "VERSION_HEADER=%~dp0Flight Radar Sim Projekt\version_generated.h"
set "DOWNLOAD_DIR=%~dp0htdocs\_downloads_"
set "XPL_FILE=%DOWNLOAD_DIR%\Flight Radar Sim Projekt.xpl"
set "RESOURCE_DIR=%~dp0Flight Radar Sim Projekt\resources"
set "ZIP_FILE=%DOWNLOAD_DIR%\_FlightRadarPlugin_latest.zip"
set "HASH_FILE=%ZIP_FILE%.sha256"

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

powershell -NoProfile -Command "Compress-Archive -LiteralPath @('%XPL_FILE%','%RESOURCE_DIR%') -DestinationPath '%ZIP_FILE%' -Force; $hash=(Get-FileHash -Algorithm SHA256 -LiteralPath '%ZIP_FILE%').Hash.ToLowerInvariant(); Set-Content -LiteralPath '%HASH_FILE%' -Value ($hash + '  ' + [IO.Path]::GetFileName('%ZIP_FILE%')) -Encoding ASCII"
if errorlevel 1 exit /b %ERRORLEVEL%

echo Release v%PLUGIN_VERSION% erstellt:
echo   %XPL_FILE%
echo   %ZIP_FILE%
echo   %HASH_FILE%
exit /b 0
