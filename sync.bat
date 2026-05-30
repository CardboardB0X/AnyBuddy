@echo off
echo ============================================================
echo  AnyBuddy — Workspace to WAMP Synchronizer
echo ============================================================
echo Source : %~dp0
echo Target : C:\wamp64\www\AnyBuddy
echo.
robocopy "%~dp0." "C:\wamp64\www\AnyBuddy" /E /PURGE /XD .git .vscode /XF *.log sync.bat
echo.
echo Sync complete!
