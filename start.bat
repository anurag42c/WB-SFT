@echo off
REM Quick start (Windows). Edit PHPEXE if php.exe is not on PATH. For a permanent installation use deploy\windows-install.bat.
setlocal
set PHPEXE=php
if not "%PHP%"=="" set PHPEXE=%PHP%
if "%PORT%"=="" set PORT=8080
cd /d "%~dp0"
%PHPEXE% bin\check.php
if errorlevel 1 (echo Fix the FAIL items above first. & pause & exit /b 1)
start "Weighbridge scale readers" /min %PHPEXE% bin\scale_supervisor.php
echo Open  http://localhost:%PORT%/   (close this window to stop the web server; close the readers window too)
%PHPEXE% -S 0.0.0.0:%PORT% -t public
