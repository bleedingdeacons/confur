@echo off
REM PHPUnit Quick Start Script for Windows
REM This script helps you quickly run tests with common options

echo =========================================
echo PHPUnit Test Runner
echo =========================================
echo.

REM Check if vendor directory exists
if not exist "vendor\" (
    echo [X] Vendor directory not found!
    echo [*] Installing dependencies...
    call composer install
    echo.
)

:menu
echo Select an option:
echo 1) Run all tests
echo 2) Run tests with coverage (HTML)
echo 3) Run tests with coverage (terminal)
echo 4) Run specific test
echo 5) Check code coverage percentage
echo 6) Run code style check (PHPCS)
echo 7) Run static analysis (PHPStan)
echo 8) Exit
echo.

set /p choice="Enter your choice [1-8]: "
echo.

if "%choice%"=="1" goto run_all
if "%choice%"=="2" goto run_coverage_html
if "%choice%"=="3" goto run_coverage_text
if "%choice%"=="4" goto run_filtered
if "%choice%"=="5" goto check_coverage
if "%choice%"=="6" goto run_phpcs
if "%choice%"=="7" goto run_phpstan
if "%choice%"=="8" goto exit_script

echo [X] Invalid option. Please try again.
echo.
goto menu

:run_all
echo [*] Running all tests...
vendor\bin\phpunit
echo.
goto menu

:run_coverage_html
echo [*] Running tests with HTML coverage...
vendor\bin\phpunit --coverage-html coverage
echo.
echo [✓] Coverage report generated!
echo [*] Open coverage\html\index.html to view
echo.
goto menu

:run_coverage_text
echo [*] Running tests with text coverage...
vendor\bin\phpunit --coverage-text
echo.
goto menu

:run_filtered
set /p test_name="Enter test name to filter: "
echo [*] Running filtered tests...
vendor\bin\phpunit --filter "%test_name%"
echo.
goto menu

:check_coverage
echo [*] Checking coverage percentage...
vendor\bin\phpunit --coverage-text | findstr /C:"Code Coverage"
echo.
goto menu

:run_phpcs
echo [*] Running code style check...
if exist "vendor\bin\phpcs.bat" (
    vendor\bin\phpcs --standard=PSR12 src
) else (
    echo [X] PHPCS not installed
)
echo.
goto menu

:run_phpstan
echo [*] Running static analysis...
if exist "vendor\bin\phpstan.bat" (
    vendor\bin\phpstan analyse src --level=5
) else (
    echo [X] PHPStan not installed
)
echo.
goto menu

:exit_script
echo [*] Goodbye!
exit /b 0