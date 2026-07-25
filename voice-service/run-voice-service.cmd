@echo off
setlocal
cd /d "%~dp0"
"C:\Program Files\nodejs\node.exe" "%~dp0server.js" >> "%~dp0voice-service.out.log" 2>> "%~dp0voice-service.err.log"
exit /b %ERRORLEVEL%
