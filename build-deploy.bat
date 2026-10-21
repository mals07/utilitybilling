@echo off
title Utility Billing - Build hosting package
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build-deploy.ps1"
echo.
pause
