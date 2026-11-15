@echo off
REM Run as Administrator. Edit PHPEXE and APPDIR first. Requires NSSM (https://nssm.cc) in PATH.
set PHPEXE=C:\php\php.exe
set APPDIR=C:\weighbridge
nssm install WeighbridgeScale "%PHPEXE%" "%APPDIR%\bin\scale_supervisor.php"
nssm set WeighbridgeScale AppDirectory "%APPDIR%"
nssm set WeighbridgeScale Start SERVICE_AUTO_START
nssm set WeighbridgeScale AppStdout "%APPDIR%\data\scale.log"
nssm set WeighbridgeScale AppStderr "%APPDIR%\data\scale.log"
nssm install WeighbridgeOracleSync "%PHPEXE%" "%APPDIR%\bin\sync_oracle.php --loop=30"
nssm set WeighbridgeOracleSync AppDirectory "%APPDIR%"
nssm set WeighbridgeOracleSync Start SERVICE_AUTO_START
nssm start WeighbridgeScale
nssm start WeighbridgeOracleSync
REM Web UI (no IIS/Apache needed): built-in PHP server on port 8080
nssm install WeighbridgeWeb "%PHPEXE%" "-S 0.0.0.0:8080 -t %APPDIR%\public"
nssm set WeighbridgeWeb AppDirectory "%APPDIR%"
nssm set WeighbridgeWeb Start SERVICE_AUTO_START
nssm start WeighbridgeWeb
rem Optional: Odoo sync service (only if you use Odoo - see Setup > Odoo & API)
rem nssm install WeighbridgeOdooSync "%PHPEXE%" "%APPDIR%\bin\sync_odoo.php --loop=30"
rem nssm set WeighbridgeOdooSync AppDirectory "%APPDIR%"
rem nssm set WeighbridgeOdooSync Start SERVICE_AUTO_START
rem nssm start WeighbridgeOdooSync
