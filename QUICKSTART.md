# Confur WordPress Plugin - Build System Quick Start

## What's Included

Your WordPress plugin now has a complete build system with:

1. **composer.json** - Composer configuration with build scripts
2. **build.php** - Advanced PHP build script with full control
3. **BUILD.md** - Comprehensive documentation
4. **.gitignore** - Updated to exclude build artifacts

## Installation

No installation needed! The build system is ready to use.

### Requirements
- PHP 7.4 or higher
- PHP ZIP extension
- Composer (optional, but recommended)

### Platform-Specific Setup Guides

- **Windows users**: See `WINDOWS.md` for detailed Windows setup instructions
- **macOS users**: See `MACOS.md` for detailed macOS setup instructions  
- **Linux users**: The build system works out of the box on most distributions

## Quick Commands

### Using Composer (Easiest)

```bash
# Build production archive (recommended for releases)
composer build

# Build development archive (includes tests)
composer build:dev

# Check version
composer version

# Clean build directory
composer clean

# Get help
composer help
```

### Using PHP Script Directly

```bash
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

## What Gets Created

After running `composer build`, you'll get:

```
build/
└── confur-production-2.1.zip  ← Ready to upload to WordPress
```

The archive contains:
- ✅ All source code (src/, js/, config/)
- ✅ Main plugin file (Confur.php)
- ✅ License file
- ❌ No development files (tests, .git, .idea)
- ❌ No build configs (composer.json, package.json)

## Typical Workflow

```bash
# 1. Make your changes to the plugin
# 2. Update version in Confur.php
# 3. Check the version
composer version

# 4. Build production archive
composer build

# 5. Test the archive
# Extract build/confur-production-2.1.zip to a test WordPress site

# 6. If good, distribute or upload to WordPress.org
```

## Archive Types

### Production (`composer build`)
- **File:** `confur-production-2.1.zip`
- **Use:** WordPress.org uploads, production sites
- **Size:** ~280 KB
- **Contains:** Only files needed to run the plugin

### Development (`composer build:dev`)
- **File:** `confur-dev-2.1.zip`
- **Use:** Development, testing, code review
- **Size:** ~282 KB
- **Contains:** Everything including tests and documentation

## Updating Version

Edit `Confur.php` and update both places:

```php
/**
 * Version: 2.2  ← Update this
 */

define('CONFUR_VERSION', '2.2');  ← And this
```

Then build:
```bash
composer build
# Creates: confur-production-2.2.zip
```

## Troubleshooting

### "php: command not found"
Install PHP:
```bash
# Ubuntu/Debian
sudo apt-get install php php-zip

# macOS (see MACOS.md for details)
brew install php

# Windows (see WINDOWS.md for details)
choco install php
```

### "composer: command not found"
You can still use the PHP script directly:
```bash
php build.php
```

Or install Composer from https://getcomposer.org/

### Permission denied
```bash
# Unix-like systems (macOS/Linux)
chmod +x build.php

# Windows: Run Command Prompt as Administrator
```

### ZIP extension missing
See platform-specific guides:
- **Windows**: `WINDOWS.md` - Enable extension in php.ini
- **macOS**: `MACOS.md` - Reinstall PHP via Homebrew
- **Linux**: `sudo apt-get install php-zip`

## Files Explained

### composer.json
Contains build commands and autoloader configuration. Run `composer install` after adding dependencies.

### build.php
The actual build script that creates ZIP archives. Can be run directly with PHP.

### BUILD.md
Full documentation with examples, CI/CD integration, and advanced usage.

### .gitignore
Updated to ignore the `build/` directory so you don't commit ZIP files.

## Next Steps

1. Test the build system:
   ```bash
   composer build
   ```

2. Read the full documentation:
   ```bash
   cat BUILD.md
   ```

3. Integrate with your workflow:
   - Add to your release process
   - Set up CI/CD (see BUILD.md)
   - Create npm scripts if needed

## Support

- Full documentation: `BUILD.md`
- Script help: `php build.php --help`
- Composer help: `composer help`

## Example Output

```bash
$ composer build
[BUILD] Cleaning build directory...
[BUILD] Build directory cleaned
[BUILD] Building production archive for version 2.1...
[BUILD] Added 26 files to archive
[BUILD] Archive created successfully: confur-production-2.1.zip
[BUILD] File size: 280.39 KB
[BUILD] Location: /path/to/confur/build/confur-production-2.1.zip
```

That's it! Your build system is ready to use. 🚀
