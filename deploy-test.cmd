@echo off
setlocal EnableExtensions
set "BASH=%ProgramFiles%\Git\bin\bash.exe"
if not exist "%BASH%" set "BASH=%ProgramFiles(x86)%\Git\bin\bash.exe"
if not exist "%BASH%" set "BASH=C:\Program Files\Git\bin\bash.exe"
if not exist "%BASH%" (
  echo [ERROR] Git Bash not found.
  pause
  exit /b 1
)

set "SCRIPT=%~dp0deploy-test.sh"

REM Non-interactive, no MSYS profiles (prevents "stdout is not a tty")
"%BASH%" --noprofile --norc "%SCRIPT%"
set "RC=%ERRORLEVEL%"

echo.
echo Exit code: %RC%
echo If something failed, check the latest deploy-*.log next to the script.
echo.
pause
