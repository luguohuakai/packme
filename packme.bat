@echo off

rem -------------------------------------------------------------
rem command line packme script for Windows.
rem -------------------------------------------------------------

@setlocal enabledelayedexpansion

set YII_PATH=%~dp0

if "%PHP_COMMAND%" == "" set PHP_COMMAND=php.exe

"%PHP_COMMAND%" "%YII_PATH%packme" %*

@endlocal
