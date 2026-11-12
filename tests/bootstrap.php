<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite. Its bootstrap loads Patchwork before anything patchable, so
 * anything that defines WordPress functions of its own must come after the
 * Bootstrap::load() call, not before it.
 *
 * This file used to try to detect a real WordPress and stub only when one was
 * absent. That branch was dead in practice — the unit suite never runs inside
 * WordPress — and it made the stubs' behaviour depend on the environment,
 * which is the opposite of what a unit suite wants. The stubs are now
 * unconditional.
 *
 * Groups: `acf`, because Confur's answers are ACF fields, and `rest` for the
 * route callbacks in the answer API. Not `sentinel` — Confur is standalone and
 * does not use the shared logger.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::load(['wordpress', 'acf', 'rest']);

// WP-CLI's symbols, for src/Cli (see the file).
require_once dirname(__DIR__) . '/stubs/wp-cli.php';

// Makes plugins_url()/plugin_dir_url() answer with Confur's own path.
WpState::$pluginSlug = 'confur';

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

if (!defined('CONFUR_PLUGIN_DIR')) {
    define('CONFUR_PLUGIN_DIR', dirname(__DIR__));
}
if (!defined('CONFUR_PLUGIN_URL')) {
    define('CONFUR_PLUGIN_URL', 'http://example.test/wp-content/plugins/confur/');
}
if (!defined('CONFUR_VERSION')) {
    define('CONFUR_VERSION', '9.9.9-test');
}
if (!defined('CONFUR_TEST_MODE')) {
    define('CONFUR_TEST_MODE', true);
}

// Unity's interfaces and test doubles (GroupRepository, GroupStub,
// InMemoryGroupRepository, ...). Read from a real Unity checkout beside this
// one — the suite's convention, as in Reach — never from a copy here, which
// would drift from the contract Unity owns. UNITY_PATH overrides the location.
$unityPath = getenv('UNITY_PATH') ?: dirname(__DIR__, 2) . '/unity';
$unitySrc = $unityPath . '/src';

if (!is_dir($unitySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Unity plugin source not found at ' . $unitySrc . PHP_EOL
        . 'Confur reads its groups through Unity, so Unity must be checked out as a' . PHP_EOL
        . 'sibling directory (or UNITY_PATH set) for this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($unitySrc): void {
    if (!str_starts_with($class, 'Unity\\')) {
        return;
    }

    $file = $unitySrc . '/' . str_replace('\\', '/', substr($class, strlen('Unity\\'))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// Prevent "headers already sent" warnings from the render paths.
ini_set('output_buffering', 'on');
ob_start();
