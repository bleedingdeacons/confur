<?php
/**
 * Plugin Name: Confur
 * Plugin URI:
 * Description: Automated collation of answers to questions for conference.
 * Version: 2.12.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/confur
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
add_filter('map_meta_cap', function($caps, $cap, $user_id, $args) {
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

// Debug - check permissions when accessing answer posts
add_action('admin_init', function() {
	if (!isset($_GET['post']) || !isset($_GET['action']) || $_GET['action'] != 'edit') return;

	$post_id = intval($_GET['post']);
	$post = get_post($post_id);
	if (!$post || $post->post_type != 'answer') return;

	$user = wp_get_current_user();
	if (!$user->ID) return;

	error_log('=== CONFUR DEBUG POST ' . $post_id . ' ===');
	error_log('User: ' . $user->user_login);
	error_log('Roles: ' . implode(', ', $user->roles));
	error_log('Post type registered: ' . (post_type_exists('answer') ? 'YES' : 'NO'));
	error_log('edit_post (' . $post_id . '): ' . (current_user_can('edit_post', $post_id) ? 'YES' : 'NO'));
	error_log('edit_answer: ' . (current_user_can('edit_answer') ? 'YES' : 'NO'));
	error_log('edit_others_answers: ' . (current_user_can('edit_others_answers') ? 'YES' : 'NO'));
}, 0);

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

// Initialize the plugin with exception handling
add_action('plugins_loaded', function() {
	try {
		// Check if Plugin class exists before trying to instantiate
		if (!class_exists('Confur\Plugin')) {
			throw new \Exception('Confur\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
		}

		global $confur_plugin;
		$confur_plugin = new \Confur\Plugin();
		$confur_plugin->init();

	} catch (\Exception $e) {
		// Log the error
		error_log('Confur Plugin Initialization Error: ' . $e->getMessage());
		error_log('Confur Plugin Stack Trace: ' . $e->getTraceAsString());

		// Show admin notice only in admin area
		if (is_admin()) {
			add_action('admin_notices', function() use ($e) {
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
			add_action('admin_notices', function() {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Confur Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
			});
		}

		return;
	}
}, 10);