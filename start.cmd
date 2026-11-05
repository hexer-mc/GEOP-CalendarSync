@echo off
setlocal
where php >nul 2>nul
if errorlevel 1 (
    echo PHP non trovato. Installa PHP 8.3 o successivo e aggiungilo al PATH.
    pause
    exit /b 1
)
php "%~dp0bin\serve.php"
if errorlevel 1 pause
