@echo off
setlocal

set "MSBUILD=C:\BuildTools\MSBuild\Current\Bin\MSBuild.exe"
set "SOLUTION=%~dp0Flight Radar Sim Projekt.slnx"
set "TARGET=Build"

if /I "%~1"=="rebuild" set "TARGET=Rebuild"

if not exist "%MSBUILD%" (
    echo Fehler: MSBuild wurde unter "%MSBUILD%" nicht gefunden.
    exit /b 1
)

"%MSBUILD%" "%SOLUTION%" /m /t:%TARGET% /p:Configuration=Release /p:Platform=x64 /v:minimal
exit /b %ERRORLEVEL%
