@echo off
setlocal

if "%~1"=="" (
    echo Usage: setkey YOUR_NEW_GEMINI_API_KEY
    exit /b 1
)

set "SCRIPT_DIR=%~dp0"
powershell -ExecutionPolicy Bypass -File "%SCRIPT_DIR%set_gemini_api_key.ps1" -ApiKey "%~1"

endlocal
