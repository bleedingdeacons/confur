#!/usr/bin/env php
<?php
/**
 * Build Script for Confur WordPress Plugin
 *
 * Cross-platform build script that works on Windows, macOS, and Linux.
 *
 * This script handles the compression and packaging of the plugin
 * for distribution.
 *
 * Usage: php build.php [options]
 * Options:
 *   --type=production   Create production archive (default)
 *   --type=dev          Create development archive (includes tests)
 *   --version=X.X       Override version number
 *   --clean             Clean build directory first
 *   --linux-safe        Force forward slashes in zip (for Linux deployment)
 *   --help              Show this help message
 */

class PluginBuilder {
    private $pluginDir;
    private $buildDir;
    private $version;
    private $pluginName = 'confur';
    private $isWindows;
    private $linuxSafe = false;

    // Files and directories to exclude in production builds
    private $productionExcludes = [
            '.git',
            '.gitignore',
            '.gitattributes',
            '.idea',
            'build',
            'tests',
            'node_modules',
            '.DS_Store',
            'composer.json',
            'composer.lock',
            'phpunit.xml',
            'phpunit.xml.dist',
            '.phpcs.xml',
            '.phpcs.xml.dist',
            'README.md',
            'package.json',
            'package-lock.json',
            '.editorconfig',
            'build.php'
    ];

    // Files and directories to exclude in dev builds
    private $devExcludes = [
            '.git',
            '.idea',
            'build',
            'node_modules',
            '.DS_Store'
    ];

    public function __construct() {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $this->pluginDir = $this->normalizePath(dirname(__FILE__));
        $this->buildDir = $this->pluginDir . DIRECTORY_SEPARATOR . 'build';
        $this->version = $this->getVersionFromPlugin();

        // Check for required extensions
        $this->checkRequirements();
    }

    /**
     * Normalize path separators for cross-platform compatibility
     */
    private function normalizePath($path) {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Check for required PHP extensions
     */
    private function checkRequirements() {
        if (!extension_loaded('zip')) {
            $this->error("PHP ZIP extension is required but not installed.");
            $this->error("Install it with:");
            if ($this->isWindows) {
                $this->error("  - Enable extension=zip in php.ini");
            } else {
                $this->error("  - Ubuntu/Debian: sudo apt-get install php-zip");
                $this->error("  - macOS: brew install php");
            }
            exit(1);
        }
    }

    /**
     * Enable Linux-safe mode (force forward slashes in zip paths)
     */
    public function setLinuxSafe($enabled = true) {
        $this->linuxSafe = $enabled;
        if ($enabled) {
            $this->log("Linux-safe mode enabled: All paths will use forward slashes");
        }
    }

    /**
     * Extract version from the main plugin file
     */
    private function getVersionFromPlugin() {
        $mainFile = $this->pluginDir . DIRECTORY_SEPARATOR . 'Confur.php';

        if (!file_exists($mainFile)) {
            $this->error("Warning: Confur.php not found at: {$mainFile}");
            $this->error("Using default version: 1.0.0");
            $this->error("To fix: Ensure you're running build.php from the plugin root directory");
            return '1.0.0';
        }

        $content = file_get_contents($mainFile);

        // Try to match the Version header first
        if (preg_match('/Version:\s*([0-9.]+)/', $content, $matches)) {
            return $matches[1];
        }

        // Try to match the CONFUR_VERSION constant
        if (preg_match("/define\('CONFUR_VERSION',\s*'([^']+)'/", $content, $matches)) {
            return $matches[1];
        }

        // If we get here, the file exists but version couldn't be parsed
        $this->error("Warning: Could not parse version from Confur.php");
        $this->error("Expected format: 'Version: X.X.X' or define('CONFUR_VERSION', 'X.X.X')");
        $this->error("Using default version: 1.0.0");
        return '1.0.0';
    }

    /**
     * Clean the build directory
     */
    public function clean() {
        $this->log("Cleaning build directory...");
        if (is_dir($this->buildDir)) {
            $this->deleteDirectory($this->buildDir);
        }
        $this->log("Build directory cleaned");
    }

    /**
     * Create the plugin archive
     */
    public function build($type = 'production', $customVersion = null) {
        if ($customVersion) {
            $this->version = $customVersion;
            $this->log("Using custom version: {$this->version}");
        } else {
            // Warn if using default version
            if ($this->version === '1.0.0') {
                $this->log("⚠️  WARNING: Using default version 1.0.0");
                $this->log("    This likely means Confur.php could not be found or parsed.");
                $this->log("    Your zip will be named: confur-{$type}-1.0.0.zip");
                $this->log("");
            }
        }

        $this->log("Building {$type} archive for version {$this->version}...");
        $this->log("Platform: " . PHP_OS . " (" . ($this->isWindows ? "Windows" : "Unix-like") . ")");

        // Create build directory
        if (!is_dir($this->buildDir)) {
            if (!mkdir($this->buildDir, 0755, true)) {
                $this->error("Failed to create build directory: {$this->buildDir}");
                exit(1);
            }
        }

        // Determine archive name and excludes
        $archiveName = $this->buildDir . DIRECTORY_SEPARATOR . $this->pluginName;
        if ($type === 'dev') {
            $archiveName .= '-dev';
            $excludes = $this->devExcludes;
        } else {
            $archiveName .= '-production';
            $excludes = $this->productionExcludes;
        }
        $archiveName .= '-' . $this->version . '.zip';

        // Create ZIP archive
        $this->createZip($archiveName, $excludes);

        // Display file size
        $size = $this->formatBytes(filesize($archiveName));
        $this->log("Archive created successfully: " . basename($archiveName));
        $this->log("File size: {$size}");
        $this->log("Location: {$archiveName}");
    }

    /**
     * Create a ZIP archive
     */
    private function createZip($archivePath, $excludes) {
        $zip = new ZipArchive();

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Failed to create ZIP archive");
            exit(1);
        }

        $files = $this->getFiles($this->pluginDir, $excludes);
        $fileCount = 0;

        foreach ($files as $file) {
            $relativePath = substr($file, strlen($this->pluginDir) + 1);
            if (is_file($file)) {
                // Force forward slashes for Linux compatibility if linuxSafe is enabled
                if ($this->linuxSafe) {
                    $relativePath = str_replace('\\', '/', $relativePath);
                }

                $zipPath = $this->pluginName . '/' . $relativePath;
                $zip->addFile($file, $zipPath);
                $fileCount++;
            }
        }

        $zip->close();
        $this->log("Added {$fileCount} files to archive");
    }

    /**
     * Get all files in directory, excluding specified patterns
     */
    private function getFiles($dir, $excludes) {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relativePath = substr($path, strlen($dir) + 1);

            // Check if file should be excluded
            if ($this->shouldExclude($relativePath, $excludes)) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * Check if a file path should be excluded
     */
    private function shouldExclude($path, $excludes) {
        // Normalize path for comparison (use forward slashes)
        $normalizedPath = str_replace('\\', '/', $path);

        foreach ($excludes as $exclude) {
            $normalizedExclude = str_replace('\\', '/', $exclude);

            // Check if path starts with exclude pattern
            if (strpos($normalizedPath, $normalizedExclude) === 0) {
                return true;
            }
            // Check if any part of the path matches
            if (strpos($normalizedPath, '/' . $normalizedExclude . '/') !== false) {
                return true;
            }
            // Check exact match
            if ($normalizedPath === $normalizedExclude) {
                return true;
            }
        }
        return false;
    }

    /**
     * Delete directory recursively (cross-platform)
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;

            // Windows: Remove read-only attribute if present
            if ($this->isWindows && file_exists($path)) {
                chmod($path, 0777);
            }

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                if (!unlink($path)) {
                    $this->error("Failed to delete file: {$path}");
                }
            }
        }

        if (!rmdir($dir)) {
            $this->error("Failed to remove directory: {$dir}");
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Log message
     */
    private function log($message) {
        echo "[BUILD] {$message}\n";
    }

    /**
     * Log error message
     */
    private function error($message) {
        echo "[ERROR] {$message}\n";
    }

    /**
     * Display help message
     */
    public function showHelp() {
        $phpVersion = PHP_VERSION;
        $platform = PHP_OS;

        echo <<<HELP

Confur WordPress Plugin Build Script
=====================================
Platform: {$platform}
PHP Version: {$phpVersion}

Usage: php build.php [options]

Options:
  --type=production   Create production archive (default) - excludes dev files
  --type=dev          Create development archive - includes test files
  --version=X.X       Override version number (default: from plugin file)
  --clean             Clean build directory before building
  --clean-only        Only clean build directory without building
  --linux-safe        Force forward slashes in zip paths (for Linux servers)
  --help              Show this help message

Examples:
  php build.php                           # Build production archive
  php build.php --type=dev                # Build development archive
  php build.php --clean                   # Clean and build production
  php build.php --version=2.2             # Build with custom version
  php build.php --type=dev --version=2.2  # Dev build with custom version
  php build.php --clean-only              # Only clean build directory
  php build.php --linux-safe              # Build with Linux-safe paths
  php build.php --linux-safe --type=dev   # Dev build for Linux deployment

Files Excluded (Production):
  - Development files (.git, .idea, tests, etc.)
  - Build configuration (composer.json, package.json, etc.)
  - Documentation (README.md)

Files Excluded (Dev):
  - Only: .git, .idea, build, node_modules, .DS_Store

Platform-Specific Notes:

HELP;

        if ($this->isWindows) {
            echo <<<WINDOWS
  Windows Detected:
  - Paths use backslashes (\\) automatically
  - Build directory: .\\build\\
  - If permission errors occur, run Command Prompt as Administrator
  - Ensure ZIP extension is enabled in php.ini (extension=zip)
  - You can also run via composer: composer build
  
  IMPORTANT for Linux Deployment:
  - Use --linux-safe flag when building for Linux servers
  - This ensures forward slashes (/) in zip paths instead of backslashes (\\)
  - Without this, Linux servers may not extract the directory structure correctly
  - Example: php build.php --linux-safe

WINDOWS;
        } else {
            echo <<<UNIX
  Unix-like System (macOS/Linux) Detected:
  - Paths use forward slashes (/)
  - Build directory: ./build/
  - Make script executable: chmod +x build.php
  - Then run directly: ./build.php [options]
  - Or run via: php build.php [options]
  - Or run via composer: composer build
  
  Note: --linux-safe is automatically applied on Unix systems

UNIX;
        }
    }
}

// Parse command line arguments
$options = getopt('', ['type:', 'version:', 'clean', 'clean-only', 'linux-safe', 'help']);

$builder = new PluginBuilder();

// Handle help
if (isset($options['help'])) {
    $builder->showHelp();
    exit(0);
}

// Handle clean-only
if (isset($options['clean-only'])) {
    $builder->clean();
    exit(0);
}

// Handle clean before build
if (isset($options['clean'])) {
    $builder->clean();
}

// Enable Linux-safe mode if requested
if (isset($options['linux-safe'])) {
    $builder->setLinuxSafe(true);
}

// Build archive
$type = $options['type'] ?? 'production';
$version = $options['version'] ?? null;

$builder->build($type, $version);