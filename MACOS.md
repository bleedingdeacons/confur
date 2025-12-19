# macOS Setup Guide for Confur Build System

This guide helps macOS users set up and use the Confur WordPress plugin build system.

## Prerequisites

### 1. Install PHP

macOS comes with PHP pre-installed, but it's often outdated. Install a modern version:

**Option A: Using Homebrew (Recommended)**
```bash
# Install Homebrew if not already installed
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install PHP
brew install php

# Verify installation
php --version
```

**Option B: Using MacPorts**
```bash
sudo port install php83
sudo port select --set php php83
```

### 2. Install Composer (Optional but Recommended)

```bash
# Download and install globally
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Verify installation
composer --version
```

## Quick Start

### Method 1: Using Composer (Easiest)

Open Terminal in your plugin directory:

```bash
# Build production archive
composer build

# Build development archive
composer build:dev

# Check version
composer version

# Clean build directory
composer clean
```

### Method 2: Using PHP Directly

```bash
# Make script executable
chmod +x build.php

# Build production archive
./build.php

# Or use PHP directly
php build.php

# Build development archive
php build.php --type=dev

# Build with custom version
php build.php --version=2.2

# Clean and build
php build.php --clean

# Get help
php build.php --help
```

## Common Issues and Solutions

### Issue 1: "php: command not found"

**Solution:**
PHP is not in your PATH or not installed:

```bash
# Check if PHP is installed
which php

# If not found, install via Homebrew
brew install php

# Add to PATH (add to ~/.zshrc or ~/.bash_profile)
export PATH="/usr/local/bin:$PATH"

# Reload shell
source ~/.zshrc  # or source ~/.bash_profile
```

### Issue 2: "Permission denied"

**Solution:**
Make the script executable:

```bash
chmod +x build.php

# Then run directly
./build.php
```

### Issue 3: ZIP Extension Not Found

**Solution:**
The ZIP extension should be included with PHP, but if not:

```bash
# For Homebrew PHP
brew reinstall php

# Verify ZIP is available
php -m | grep zip
```

### Issue 4: "Operation not permitted" (macOS Catalina+)

**Solution:**
Grant Terminal full disk access:

1. System Preferences → Security & Privacy → Privacy
2. Select "Full Disk Access"
3. Click the lock to make changes
4. Add Terminal (or your terminal app)
5. Restart Terminal

### Issue 5: Using Apple Silicon (M1/M2/M3)

If you have an Apple Silicon Mac:

```bash
# Ensure you're using ARM64 PHP
php -i | grep Architecture

# If it shows x86_64, reinstall PHP for ARM64
arch -arm64 brew reinstall php
```

## Building Your Plugin

### Standard Workflow

```bash
# 1. Open Terminal and navigate to plugin directory
cd ~/Sites/confur

# 2. Check current version
composer version

# 3. Build production archive
composer build

# Output will be in: ./build/confur-production-2.1.zip
```

### Using Different Shells

The build system works with all macOS shells:

**Zsh (default in macOS Catalina+):**
```zsh
cd ~/Projects/confur
composer build
```

**Bash:**
```bash
cd ~/Projects/confur
composer build
```

**Fish:**
```fish
cd ~/Projects/confur
composer build
```

## File Locations

After building, your files will be at:

```
~/Projects/confur/
├── build/
│   ├── confur-production-2.1.zip   ← Production archive
│   └── confur-dev-2.1.zip          ← Development archive
├── build.php                        ← Build script (executable)
└── composer.json                    ← Composer config
```

## Testing the Build

```bash
# 1. Build the archive
composer build

# 2. Check the file was created
ls -lh build/

# 3. Extract to test location
unzip build/confur-production-2.1.zip -d test-extract

# 4. Verify contents
tree test-extract/confur
# or
ls -R test-extract/confur
```

## Automating Builds

### Create a Shell Script

Create `build-plugin.sh`:

```bash
#!/bin/bash
set -e

echo "🚀 Building Confur Plugin..."
cd "$(dirname "$0")"

php build.php --clean

if [ $? -eq 0 ]; then
    echo "✅ Build successful!"
    echo "📦 Archive location: $(pwd)/build/"
    open build
else
    echo "❌ Build failed!"
    exit 1
fi
```

Make it executable and run:

```bash
chmod +x build-plugin.sh
./build-plugin.sh
```

### Create a Makefile

Create `Makefile`:

```makefile
.PHONY: build clean help

build:
	@echo "Building production archive..."
	@php build.php --clean
	@echo "✅ Build complete!"

build-dev:
	@echo "Building development archive..."
	@php build.php --type=dev --clean
	@echo "✅ Dev build complete!"

clean:
	@echo "Cleaning build directory..."
	@php build.php --clean-only

version:
	@php build.php --version

help:
	@php build.php --help
```

Use it:

```bash
make build      # Build production
make build-dev  # Build development
make clean      # Clean only
make version    # Show version
```

## Integration with IDEs

### Visual Studio Code

Add to `.vscode/tasks.json`:

```json
{
    "version": "2.0.0",
    "tasks": [
        {
            "label": "Build Plugin",
            "type": "shell",
            "command": "php",
            "args": ["build.php", "--clean"],
            "problemMatcher": [],
            "group": {
                "kind": "build",
                "isDefault": true
            },
            "presentation": {
                "reveal": "always",
                "panel": "new"
            }
        },
        {
            "label": "Build Plugin (Dev)",
            "type": "shell",
            "command": "php",
            "args": ["build.php", "--type=dev", "--clean"],
            "problemMatcher": []
        }
    ]
}
```

Press `Cmd+Shift+B` to build!

### PHPStorm

1. Run → Edit Configurations
2. Add → PHP Script
3. Set:
   - File: `build.php`
   - Arguments: `--clean`
4. Click OK and Run

### Sublime Text

Add to project settings:

```json
{
    "build_systems": [
        {
            "name": "Build Confur Plugin",
            "cmd": ["php", "build.php", "--clean"],
            "working_dir": "$project_path"
        }
    ]
}
```

## Terminal Tips

### Aliases

Add to `~/.zshrc` or `~/.bash_profile`:

```bash
# Quick build aliases
alias confur-build='php build.php --clean'
alias confur-dev='php build.php --type=dev --clean'
alias confur-clean='php build.php --clean-only'
alias confur-version='php build.php --version'
```

Reload shell:
```bash
source ~/.zshrc
```

Use:
```bash
confur-build      # Build production
confur-dev        # Build development
confur-clean      # Clean only
confur-version    # Show version
```

### Using iTerm2

iTerm2 profiles for quick builds:

1. Open iTerm2 Preferences
2. Profiles → Add new profile
3. Command: `cd ~/Projects/confur && php build.php --clean`
4. Name it "Confur Build"
5. Click the profile to build instantly

## Finder Integration

### Quick Action (macOS Catalina+)

Create a Quick Action in Automator:

1. Open Automator
2. New → Quick Action
3. Add "Run Shell Script"
4. Set:
   - Workflow receives: folders
   - Pass input: as arguments
   ```bash
   cd "$1"
   php build.php --clean
   open build
   ```
5. Save as "Build Confur Plugin"

Right-click plugin folder → Quick Actions → Build Confur Plugin

## Command Line Cheat Sheet

```bash
# Navigate to plugin directory
cd ~/Projects/confur

# Build production
composer build

# Build development  
composer build:dev

# Clean build directory
composer clean

# Check version
composer version

# List build output
ls -lh build/

# Open build folder in Finder
open build

# Delete old builds
rm build/*.zip

# View file contents
cat composer.json

# Edit configuration
nano composer.json
```

## Homebrew PHP Management

```bash
# Install specific PHP version
brew install php@8.2

# Switch PHP versions
brew unlink php && brew link php@8.2

# Check installed versions
brew list | grep php

# Update PHP
brew upgrade php

# See PHP info
php -i | less
```

## Troubleshooting Checklist

- [ ] PHP is installed (`php --version`)
- [ ] PHP version is 7.4 or higher
- [ ] ZIP extension is loaded (`php -m | grep zip`)
- [ ] Composer is installed (optional) (`composer --version`)
- [ ] Script is executable (`chmod +x build.php`)
- [ ] You're in the correct directory (`ls` shows build.php)
- [ ] You have write permissions (`ls -la`)
- [ ] Xcode Command Line Tools installed (`xcode-select --install`)

## Getting Help

If you're stuck:

1. Run `./build.php --help` or `php build.php --help`
2. Check `BUILD.md` for comprehensive documentation
3. Check `QUICKSTART.md` for quick reference
4. Check `WINDOWS.md` if you need cross-platform info

## Example Session

Here's what a successful build looks like:

```bash
~ $ cd Projects/confur

~/Projects/confur $ composer build
[BUILD] Cleaning build directory...
[BUILD] Build directory cleaned
[BUILD] Building production archive for version 2.1...
[BUILD] Platform: Darwin (Unix-like)
[BUILD] Added 26 files to archive
[BUILD] Archive created successfully: confur-production-2.1.zip
[BUILD] File size: 280.39 KB
[BUILD] Location: /Users/yourname/Projects/confur/build/confur-production-2.1.zip

~/Projects/confur $ ls -lh build/
total 560
-rw-r--r--  1 yourname  staff   280K Dec 19 10:30 confur-production-2.1.zip

~/Projects/confur $ open build
# Finder opens showing your build
```

## Advanced Usage

### Building from Anywhere

Create a global script in `/usr/local/bin/build-confur`:

```bash
#!/bin/bash
CONFUR_DIR="$HOME/Projects/confur"
cd "$CONFUR_DIR" && php build.php "$@"
```

Make it executable:
```bash
chmod +x /usr/local/bin/build-confur
```

Now run from anywhere:
```bash
build-confur --clean
```

### Watch for Changes

Auto-rebuild on file changes:

```bash
# Install fswatch via Homebrew
brew install fswatch

# Watch and auto-build
fswatch -o src/ js/ config/ Confur.php | xargs -n1 -I{} php build.php
```

That's it! Your plugin is ready to distribute. 🚀
