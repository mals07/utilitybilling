@echo off
title Utility Billing - Export database
cd /d "%~dp0"

rem Saves the utility_billing MySQL database to database\backup.sql.
rem setup.bat imports that file automatically on the new computer.

set "DUMP="
for /d %%v in ("%LOCALAPPDATA%\com.tinyapp.DBngin\Binaries\mysql\*") do (
    if exist "%%v\bin\mysqldump.exe" set "DUMP=%%v\bin\mysqldump.exe"
)
if not defined DUMP (
    for /f "delims=" %%p in ('where mysqldump 2^>nul') do set "DUMP=%%p"
)
if not defined DUMP (
    echo Could not find mysqldump.exe. Make sure DBngin with MySQL is installed.
    pause
    exit /b 1
)

echo Exporting database utility_billing...
"%DUMP%" --host=127.0.0.1 --port=3306 --user=root --single-transaction --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --result-file="database\backup.sql" utility_billing
if errorlevel 1 (
    echo.
    echo Export FAILED. Is the MySQL service running in DBngin?
    pause
    exit /b 1
)

echo.
echo Saved to database\backup.sql
echo Copy the whole project folder to the other computer and run setup.bat there.
pause
