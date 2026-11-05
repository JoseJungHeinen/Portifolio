@echo off
rem Liga o sistema de multas (teste): MySQL do XAMPP + site em http://localhost:8090
cd /d "%~dp0"

tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >nul
if errorlevel 1 (
  echo Ligando o MySQL do XAMPP...
  start "MySQL XAMPP" /MIN "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
  timeout /t 5 /nobreak >nul
)

netstat -ano | find ":8090 " | find "LISTENING" >nul
if errorlevel 1 (
  echo Ligando o site em http://localhost:8090 ...
  start "Sistema de Multas" /MIN "C:\xampp\php\php.exe" -S localhost:8090 -t public
  timeout /t 2 /nobreak >nul
)

start "" "http://localhost:8090/"
