<?php
/**
 * PHPUnit Bootstrap File - Smart WordPress Detection
 *
 * This bootstrap file intelligently detects whether WordPress is loaded:
 * - If WordPress IS loaded: Use WordPress functions as-is
 * - If WordPress is NOT loaded: Mock WordPress functions for testing
 */

// Load Composer autoloader
$autoload_path = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
	require_once $autoload_path;
} else {
	echo "Error: Composer autoloader not found. Run 'composer install' first.\n";
	exit(1);
}

// Detect if WordPress is loaded
$wordpress_loaded = defined('ABSPATH') && function_exists('add_action');

if ($wordpress_loaded) {
	echo "✓ WordPress detected - using WordPress functions\n";
	echo "  WordPress version: " . (defined('WP_VERSION') ? WP_VERSION : 'Unknown') . "\n";
	echo "  WordPress path: " . ABSPATH . "\n";
} else {
	echo "✓ WordPress NOT detected - mocking WordPress functions\n";

	// Define WordPress constants
	if (!defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/../');
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

	// Mock WordPress core functions
	// These are only defined if WordPress is NOT loaded

	if (!function_exists('sanitize_text_field')) {
		function sanitize_text_field($str) {
			return trim(strip_tags($str));
		}
	}

	if (!function_exists('esc_attr')) {
		function esc_attr($text) {
			return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		}
	}

	if (!function_exists('esc_html')) {
		function esc_html($text) {
			return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		}
	}

	if (!function_exists('esc_textarea')) {
		function esc_textarea($text) {
			return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		}
	}

	if (!function_exists('esc_url')) {
		function esc_url($url) {
			return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
		}
	}

	if (!function_exists('do_shortcode')) {
		function do_shortcode($content) {
			return $content;
		}
	}

	if (!function_exists('__')) {
		function __($text, $domain = 'default') {
			return $text;
		}
	}

	if (!function_exists('_e')) {
		function _e($text, $domain = 'default') {
			echo $text;
		}
	}

	if (!function_exists('_x')) {
		function _x($text, $context, $domain = 'default') {
			return $text;
		}
	}

	if (!function_exists('esc_html__')) {
		function esc_html__($text, $domain = 'default') {
			return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		}
	}

	if (!function_exists('esc_attr__')) {
		function esc_attr__($text, $domain = 'default') {
			return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		}
	}

	if (!function_exists('wp_kses_post')) {
		function wp_kses_post($data) {
			return strip_tags($data, '<p><a><strong><em><ul><ol><li><br><img><h1><h2><h3><h4><h5><h6>');
		}
	}

	if (!function_exists('add_action')) {
		function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
			return true;
		}
	}

	if (!function_exists('add_filter')) {
		function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
			return true;
		}
	}

	if (!function_exists('apply_filters')) {
		function apply_filters($hook, $value) {
			$args = func_get_args();
			return $args[1] ?? $value;
		}
	}

	if (!function_exists('do_action')) {
		function do_action($hook) {
			return true;
		}
	}

	if (!function_exists('remove_action')) {
		function remove_action($hook, $callback, $priority = 10) {
			return true;
		}
	}

	if (!function_exists('remove_filter')) {
		function remove_filter($hook, $callback, $priority = 10) {
			return true;
		}
	}

	if (!function_exists('wp_enqueue_script')) {
		function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) {
			return true;
		}
	}

	if (!function_exists('wp_enqueue_style')) {
		function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') {
			return true;
		}
	}

	if (!function_exists('wp_localize_script')) {
		function wp_localize_script($handle, $object_name, $l10n) {
			return true;
		}
	}

	if (!function_exists('wp_die')) {
		function wp_die($message, $title = '', $args = []) {
			throw new Exception($message);
		}
	}

	if (!function_exists('is_admin')) {
		function is_admin() {
			return false;
		}
	}

	if (!function_exists('current_user_can')) {
		function current_user_can($capability) {
			return true;
		}
	}

	if (!function_exists('wp_verify_nonce')) {
		function wp_verify_nonce($nonce, $action = -1) {
			return 1;
		}
	}

	if (!function_exists('wp_create_nonce')) {
		function wp_create_nonce($action = -1) {
			return 'test_nonce_value';
		}
	}

	if (!function_exists('check_ajax_referer')) {
		function check_ajax_referer($action = -1, $query_arg = false, $die = true) {
			return 1;
		}
	}

	if (!function_exists('wp_send_json_success')) {
		function wp_send_json_success($data = null, $status_code = null) {
			echo json_encode(['success' => true, 'data' => $data]);
			exit;
		}
	}

	if (!function_exists('wp_send_json_error')) {
		function wp_send_json_error($data = null, $status_code = null) {
			echo json_encode(['success' => false, 'data' => $data]);
			exit;
		}
	}

	if (!function_exists('absint')) {
		function absint($maybeint) {
			return abs(intval($maybeint));
		}
	}

	if (!function_exists('wp_unslash')) {
		function wp_unslash($value) {
			return is_string($value) ? stripslashes($value) : $value;
		}
	}

	if (!function_exists('sanitize_key')) {
		function sanitize_key($key) {
			return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key));
		}
	}

	if (!function_exists('sanitize_email')) {
		function sanitize_email($email) {
			return filter_var($email, FILTER_SANITIZE_EMAIL);
		}
	}

	if (!function_exists('is_email')) {
		function is_email($email) {
			return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
		}
	}

	if (!function_exists('wp_parse_args')) {
		function wp_parse_args($args, $defaults = []) {
			if (is_object($args)) {
				$parsed_args = get_object_vars($args);
			} elseif (is_array($args)) {
				$parsed_args = &$args;
			} else {
				parse_str($args, $parsed_args);
			}

			if (is_array($defaults)) {
				return array_merge($defaults, $parsed_args);
			}
			return $parsed_args;
		}
	}

	if (!function_exists('get_post_meta')) {
		function get_post_meta($post_id, $key = '', $single = false) {
			// Mock implementation - returns empty
			return $single ? '' : [];
		}
	}

	if (!function_exists('update_post_meta')) {
		function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
			return true;
		}
	}

	if (!function_exists('delete_post_meta')) {
		function delete_post_meta($post_id, $meta_key, $meta_value = '') {
			return true;
		}
	}

	if (!function_exists('get_the_ID')) {
		function get_the_ID() {
			global $post;
			return isset($post->ID) ? $post->ID : 0;
		}
	}

	if (!function_exists('get_the_title')) {
		function get_the_title($post = 0) {
			if (is_numeric($post)) {
				return "Post Title {$post}";
			}
			return "Post Title";
		}
	}

	if (!function_exists('get_field')) {
		/**
		 * Mock Advanced Custom Fields get_field() function
		 * Returns null by default, can be overridden in tests
		 */
		function get_field($selector, $post_id = false, $format_value = true) {
			// Return null by default - tests can override via filters or globals
			return null;
		}
	}

	echo "  Mocked " . count(get_defined_functions()['user']) . " functions\n";
}

// Define test-specific constants
if (!defined('CONFUR_TEST_MODE')) {
	define('CONFUR_TEST_MODE', true);
}

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Prevent "headers already sent" warnings
ini_set('output_buffering', 'on');
ob_start();

// Load namespace-specific WordPress function overrides
// These allow WordPress functions to be called from within namespaced code
require_once __DIR__ . '/wordpress-functions.php';

echo "✓ PHPUnit Bootstrap loaded successfully\n";
echo "  Test mode: " . (CONFUR_TEST_MODE ? 'Enabled' : 'Disabled') . "\n";
echo "\n";