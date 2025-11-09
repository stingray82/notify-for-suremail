@echo off
setlocal
REM ---- Adjust if Git is installed elsewhere ----
set "BASH=C:\Program Files\Git\bin\bash.exe"

REM Keep logs so you can see failures after the window closes
set "LOG=%~dp0deploy.log"

REM Run bash with a login shell so PATH/ENV are sane, capture output to log
"%BASH%" --login -i "%~dp0deploy-test.sh" 2>&1 | "%BASH%" -lc "tee '%LOG%'"
echo.
echo Log written to: %LOG%
pause
