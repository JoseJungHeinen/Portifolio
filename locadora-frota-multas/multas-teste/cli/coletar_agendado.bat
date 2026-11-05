@echo off
rem Rodado pelo Agendador de Tarefas (seg a sex, 7h).
rem Garante o MySQL do XAMPP ligado e roda o coletor automático do DETRAN.
cd /d "%~dp0.."

tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >nul
if errorlevel 1 (
  start "" /B "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
  timeout /t 10 /nobreak >nul
)

if not exist "data\coletas" mkdir "data\coletas"
"C:\Program Files\nodejs\node.exe" "cli\coletar_detran.mjs" >> "data\coletas\agendado.log" 2>&1
