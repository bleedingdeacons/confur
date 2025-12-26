#!/bin/bash

# PHPUnit Quick Start Script
# This script helps you quickly run tests with common options

echo "========================================="
echo "PHPUnit Test Runner"
echo "========================================="
echo ""

# Check if vendor directory exists
if [ ! -d "vendor" ]; then
    echo "❌ Vendor directory not found!"
    echo "📦 Installing dependencies..."
    composer install
    echo ""
fi

# Function to display menu
show_menu() {
    echo "Select an option:"
    echo "1) Run all tests"
    echo "2) Run tests with coverage (HTML)"
    echo "3) Run tests with coverage (terminal)"
    echo "4) Run specific test"
    echo "5) Run tests in watch mode"
    echo "6) Check code coverage percentage"
    echo "7) Run code style check (PHPCS)"
    echo "8) Run static analysis (PHPStan)"
    echo "9) Exit"
    echo ""
}

# Main loop
while true; do
    show_menu
    read -p "Enter your choice [1-9]: " choice
    echo ""

    case $choice in
        1)
            echo "▶️  Running all tests..."
            ./vendor/bin/phpunit
            ;;
        2)
            echo "▶️  Running tests with HTML coverage..."
            ./vendor/bin/phpunit --coverage-html coverage
            echo ""
            echo "✅ Coverage report generated!"
            echo "📊 Open coverage/html/index.html to view"
            ;;
        3)
            echo "▶️  Running tests with text coverage..."
            ./vendor/bin/phpunit --coverage-text
            ;;
        4)
            read -p "Enter test name to filter: " test_name
            echo "▶️  Running filtered tests..."
            ./vendor/bin/phpunit --filter "$test_name"
            ;;
        5)
            echo "▶️  Starting watch mode (requires fswatch)..."
            echo "Press Ctrl+C to stop"
            if command -v fswatch &> /dev/null; then
                fswatch -o tests/ src/ | xargs -n1 -I{} ./vendor/bin/phpunit
            else
                echo "❌ fswatch not installed. Install with:"
                echo "   macOS: brew install fswatch"
                echo "   Linux: apt-get install fswatch"
            fi
            ;;
        6)
            echo "▶️  Checking coverage percentage..."
            ./vendor/bin/phpunit --coverage-text | grep -A 3 "Code Coverage"
            ;;
        7)
            echo "▶️  Running code style check..."
            if [ -f "./vendor/bin/phpcs" ]; then
                ./vendor/bin/phpcs --standard=PSR12 src
            else
                echo "❌ PHPCS not installed"
            fi
            ;;
        8)
            echo "▶️  Running static analysis..."
            if [ -f "./vendor/bin/phpstan" ]; then
                ./vendor/bin/phpstan analyse src --level=5
            else
                echo "❌ PHPStan not installed"
            fi
            ;;
        9)
            echo "👋 Goodbye!"
            exit 0
            ;;
        *)
            echo "❌ Invalid option. Please try again."
            ;;
    esac

    echo ""
    echo "========================================="
    echo ""
done