<?php

/**
 * Plugin Name: Confur
 * Plugin URI:
 * Description: Automated collation of answers to questions for conference.
 * Version: 3.4.0
 * Requires at least: 6.0
 * Requires PHP: 8.4
 * Requires Plugins: unity, tsml-for-unity
 * GitHub Plugin URI: https://github.com/bleedingdeacons/confur
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Fix AAM permission check for answer post type before it's registered by ACF
// AAM checks permissions before ACF registers the post type, causing access denied
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    // Only intercept edit_post capability for answer posts
    if ($cap !== 'edit_post' || empty($args[0])) {
        return $caps;
    }

    $post = get_post($args[0]);
    if (!$post || $post->post_type !== 'answer') {
        return $caps;
    }

    // If post type isn't registered yet, manually map the capability
    if (!post_type_exists('answer')) {
        // Check if user has the answer capabilities
        if (user_can($user_id, 'edit_others_answers')) {
            return ['edit_others_answers'];
        }
        if (user_can($user_id, 'edit_answers')) {
            return ['edit_answers'];
        }
    }

    return $caps;
}, 1, 4);

// Define plugin constants
// Get version from plugin header to maintain single source of truth
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$confur_plugin_data = get_plugin_data(__FILE__, false, false);
define('CONFUR_VERSION', $confur_plugin_data['Version']);
define('CONFUR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONFUR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader with exception handling
spl_autoload_register(function ($class) {
    try {
        // Project namespace prefix
        $prefix = 'Confur\\';

        // Base directory for the namespace prefix
        $base_dir = CONFUR_PLUGIN_DIR . 'src/';

        // Check if the class uses the namespace prefix
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        // Get the relative class name
        $relative_class = substr($class, $len);

        // Replace namespace separators with directory separators
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        // If the file exists, require it
        if (file_exists($file)) {
            require $file;
        }
    } catch (\Exception $e) {
        error_log('Confur Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        error_log('Confur Autoloader Fatal Error: ' . $e->getMessage());
    }
});

// Grant the administrator role the answer capabilities. Plugin::activate()
// existed but was never hooked up, so on a fresh install nothing granted them.
register_activation_hook(__FILE__, [\Confur\Plugin::class, 'activate']);

// Unity is required: groups (registrations' `group` / `fellow_group`) come
// from its GroupRepository, which TSML for Unity binds. Confur boots on
// unity/loaded like the rest of the suite, and says so if Unity is missing.
add_action('plugins_loaded', function () {
    if (!class_exists('Unity\\Plugin')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Confur:</strong> '
                . esc_html__('This plugin requires the Unity and TSML for Unity plugins to be installed and activated.', 'confur')
                . '</p></div>';
        });
    }
}, 20);

// Initialize the plugin with exception handling. The repository itself is
// resolved when first used: TSML for Unity binds it in its own unity/loaded
// handler, which can run after this one.
add_action('unity/loaded', function ($container) {
    try {
        // Check if Plugin class exists before trying to instantiate
        if (!class_exists('Confur\Plugin')) {
            throw new \Exception('Confur\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        global $confur_plugin;
        $confur_plugin = new \Confur\Plugin();
        $confur_plugin->init($container);
    } catch (\Exception $e) {
        // Log the error
        error_log('Confur Plugin Initialization Error: ' . $e->getMessage());
        error_log('Confur Plugin Stack Trace: ' . $e->getTraceAsString());

        // Show admin notice only in admin area
        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                $message = sprintf(
                    '<strong>Confur Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
            });
        }

        // Prevent further execution
        return;
    } catch (\Throwable $e) {
        // Catch any fatal errors
        error_log('Confur Plugin Fatal Error: ' . $e->getMessage());
        error_log('Confur Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Confur Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }

        return;
    }
}, 10);
