@echo off
setlocal

if "%~1"=="" (
    echo Usage: setkey YOUR_NEW_GEMINI_API_KEY [PROJECT_ID]
    exit /b 1
)

set "SCRIPT_DIR=%~dp0"
if "%~2"=="" (
    powershell -ExecutionPolicy Bypass -File "%SCRIPT_DIR%set_gemini_api_key.ps1" -ApiKey "%~1"
) else (
    powershell -ExecutionPolicy Bypass -File "%SCRIPT_DIR%set_gemini_api_key.ps1" -ApiKey "%~1" -ProjectId "%~2"
)

endlocal
