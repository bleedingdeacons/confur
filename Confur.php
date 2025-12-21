<?php
/**
 * Plugin Name: Confur
 * Plugin URI:
 * Description: Automated collation of answers to questions for conference.
 * Author: The Bleeding Deacons
 * Author: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('CONFUR_VERSION', '2.1.1');
define('CONFUR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONFUR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
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
});

// Initialize the plugin
add_action('plugins_loaded', function() {
	global $confur_plugin;
	$confur_plugin = new \Confur\Plugin();
	$confur_plugin->init();
});