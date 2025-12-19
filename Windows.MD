# Windows Setup Guide for Confur Build System

This guide helps Windows users set up and use the Confur WordPress plugin build system.

## Prerequisites

### 1. Install PHP

**Option A: Using Chocolatey (Recommended)**
```powershell
# Run PowerShell as Administrator
choco install php

# Verify installation
php --version
```

**Option B: Manual Installation**
1. Download PHP from https://windows.php.net/download/
2. Extract to `C:\php`
3. Add `C:\php` to your PATH:
   - Open System Properties → Environment Variables
   - Edit PATH variable
   - Add `C:\php`
4. Enable ZIP extension:
   - Open `C:\php\php.ini`
   - Find `;extension=zip`
   - Remove the semicolon: `extension=zip`
5. Restart Command Prompt

### 2. Install Composer (Optional but Recommended)

**Download and Install:**
```powershell
# Download installer
https://getcomposer.org/Composer-Setup.exe

# Run the installer and follow prompts
```

**Verify Installation:**
```powershell
composer --version
```

## Quick Start

### Method 1: Using Composer (Easiest)

Open Command Prompt or PowerShell in your plugin directory:

```powershell
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

```powershell
# Build production archive
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

### Issue 1: "php is not recognized"

**Solution:**
PHP is not in your PATH. Either:
- Reinstall PHP using the instructions above
- Use full path: `C:\php\php.exe build.php`

### Issue 2: "ZIP extension not found"

**Solution:**
```powershell
# 1. Locate your php.ini file
php --ini

# 2. Open php.ini in Notepad
notepad C:\php\php.ini

# 3. Find this line:
;extension=zip

# 4. Remove the semicolon:
extension=zip

# 5. Save and restart Command Prompt
```

### Issue 3: Permission Denied

**Solution:**
Run Command Prompt as Administrator:
1. Press Win + X
2. Select "Command Prompt (Admin)" or "PowerShell (Admin)"
3. Navigate to your plugin directory
4. Run the build command

### Issue 4: "Cannot create directory"

**Solution:**
Check folder permissions:
```powershell
# Navigate to plugin directory
cd C:\path\to\confur

# Try creating build directory manually
mkdir build

# If that fails, check Windows permissions on the folder
```

### Issue 5: Long Path Issues (Windows 10)

If you get path too long errors:

**Solution:**
Enable long paths in Windows:
```powershell
# Run as Administrator in PowerShell
New-ItemProperty -Path "HKLM:\SYSTEM\CurrentControlSet\Control\FileSystem" -Name "LongPathsEnabled" -Value 1 -PropertyType DWORD -Force

# Restart your computer
```

## Building Your Plugin

### Standard Workflow

```powershell
# 1. Open Command Prompt in plugin directory
cd C:\path\to\your\confur\plugin

# 2. Check current version
composer version

# 3. Build production archive
composer build

# Output will be in: .\build\confur-production-2.1.zip
```

### Using PowerShell

PowerShell works the same as Command Prompt:

```powershell
# Navigate to plugin
cd C:\Users\YourName\Projects\confur

# Build
composer build

# Or with PHP directly
php build.php
```

### Using Windows Terminal

Modern Windows Terminal works great:

```powershell
# Open Windows Terminal
# Navigate to your plugin
cd C:\path\to\confur

# Run build
composer build
```

## File Locations

After building, your files will be at:

```
C:\path\to\confur\
├── build\
│   ├── confur-production-2.1.zip   ← Production archive
│   └── confur-dev-2.1.zip          ← Development archive
├── build.php                        ← Build script
└── composer.json                    ← Composer config
```

## Path Examples

Windows uses backslashes, but the script handles this automatically:

```powershell
# These all work:
php build.php
php .\build.php
C:\php\php.exe build.php

# Output paths use backslashes:
.\build\confur-production-2.1.zip
```

## Testing the Build

```powershell
# 1. Build the archive
composer build

# 2. Check the file was created
dir build

# 3. Extract to test location
Expand-Archive build\confur-production-2.1.zip -DestinationPath test-extract

# 4. Verify contents
dir test-extract\confur
```

## Automating Builds

### Create a Batch File

Create `build-plugin.bat`:

```batch
@echo off
echo Building Confur Plugin...
cd /d "%~dp0"
php build.php --clean
if %ERRORLEVEL% EQU 0 (
    echo Build successful!
    explorer build
) else (
    echo Build failed!
)
pause
```

Double-click `build-plugin.bat` to build with one click!

### Create a PowerShell Script

Create `build-plugin.ps1`:

```powershell
Write-Host "Building Confur Plugin..." -ForegroundColor Green
Set-Location $PSScriptRoot
php build.php --clean

if ($LASTEXITCODE -eq 0) {
    Write-Host "Build successful!" -ForegroundColor Green
    Invoke-Item build
} else {
    Write-Host "Build failed!" -ForegroundColor Red
}
```

Run: `powershell .\build-plugin.ps1`

## Integration with IDEs

### PHPStorm / IntelliJ IDEA

1. Open Run/Debug Configurations
2. Add new PHP Script
3. Set file: `build.php`
4. Add arguments: `--clean`
5. Click Run

### VS Code

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
            }
        }
    ]
}
```

Press `Ctrl+Shift+B` to build!

## Command Prompt Cheat Sheet

```powershell
# Navigate to plugin directory
cd C:\path\to\confur

# Build production
composer build

# Build development  
composer build:dev

# Clean build directory
composer clean

# Check version
composer version

# View build output
dir build

# Open build folder
explorer build

# Delete old builds
del build\*.zip

# View file contents
type composer.json
```

## Troubleshooting Checklist

- [ ] PHP is installed and in PATH
- [ ] PHP version is 7.4 or higher (`php --version`)
- [ ] ZIP extension is enabled (`php -m | findstr zip`)
- [ ] Composer is installed (optional) (`composer --version`)
- [ ] You're in the correct directory (`dir` shows build.php)
- [ ] You have write permissions (try as Administrator)
- [ ] Antivirus is not blocking PHP

## Getting Help

If you're stuck:

1. Run `php build.php --help` to see all options
2. Check `BUILD.md` for comprehensive documentation
3. Check `QUICKSTART.md` for quick reference

## Example Session

Here's what a successful build looks like:

```powershell
C:\Projects\confur> composer build
[BUILD] Cleaning build directory...
[BUILD] Build directory cleaned
[BUILD] Building production archive for version 2.1...
[BUILD] Platform: WINNT (Windows)
[BUILD] Added 26 files to archive
[BUILD] Archive created successfully: confur-production-2.1.zip
[BUILD] File size: 280.39 KB
[BUILD] Location: C:\Projects\confur\build\confur-production-2.1.zip

C:\Projects\confur> dir build
 Directory of C:\Projects\confur\build

12/19/2024  10:30 AM    <DIR>          .
12/19/2024  10:30 AM    <DIR>          ..
12/19/2024  10:30 AM           287,129 confur-production-2.1.zip
               1 File(s)        287,129 bytes
```

That's it! Your plugin is ready to distribute. 🚀
