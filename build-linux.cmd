@echo off
setlocal
set "PROJECT_DIR=%~dp0"
set "OUTPUT_FILE=%PROJECT_DIR%htdocs\_downloads_\lin.xpl"

wsl -e bash -lc "set -e; cd '/mnt/c/Users/Administrator/Desktop/VFN-Projekt/Flight Radar Sim Projekt'; cmake -S . -B build-linux -DCMAKE_BUILD_TYPE=Release -DXPlaneSDK_DIR='/mnt/c/Users/Administrator/Desktop/VFN-Projekt/SDK'; cmake --build build-linux --parallel $(nproc); cp -f build-linux/lin.xpl '/mnt/c/Users/Administrator/Desktop/VFN-Projekt/Flight Radar Sim Projekt/htdocs/_downloads_/lin.xpl'"
if errorlevel 1 exit /b %ERRORLEVEL%

echo Linux plugin created: %OUTPUT_FILE%
exit /b 0
