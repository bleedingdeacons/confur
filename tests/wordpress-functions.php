<?php
/**
 * Namespace-specific WordPress function overrides
 *
 * This file defines WordPress functions in the Confur\Shortcodes namespace
 * so that when the code calls get_field(), get_the_ID(), etc. from within
 * that namespace, it finds these functions instead of failing.
 *
 * These functions delegate to either:
 * - Real WordPress functions (if WordPress is loaded)
 * - Mock functions from bootstrap.php (if WordPress is not loaded)
 */

namespace Confur\Shortcodes;

if (!function_exists('Confur\Shortcodes\get_field')) {
	/**
	 * Mock/delegate get_field() for ACF
	 */
	function get_field($selector, $post_id = false, $format_value = true) {
		// Call the global function
		return \get_field($selector, $post_id, $format_value);
	}
}

if (!function_exists('Confur\Shortcodes\get_the_ID')) {
	/**
	 * Mock/delegate get_the_ID()
	 */
	function get_the_ID() {
		return \get_the_ID();
	}
}

if (!function_exists('Confur\Shortcodes\get_the_title')) {
	/**
	 * Mock/delegate get_the_title()
	 */
	function get_the_title($post = 0) {
		return \get_the_title($post);
	}
}

if (!function_exists('Confur\Shortcodes\sanitize_text_field')) {
	/**
	 * Mock/delegate sanitize_text_field()
	 */
	function sanitize_text_field($str) {
		return \sanitize_text_field($str);
	}
}

if (!function_exists('Confur\Shortcodes\esc_attr')) {
	/**
	 * Mock/delegate esc_attr()
	 */
	function esc_attr($text) {
		return \esc_attr($text);
	}
}

if (!function_exists('Confur\Shortcodes\esc_html')) {
	/**
	 * Mock/delegate esc_html()
	 */
	function esc_html($text) {
		return \esc_html($text);
	}
}

if (!function_exists('Confur\Shortcodes\esc_textarea')) {
	/**
	 * Mock/delegate esc_textarea()
	 */
	function esc_textarea($text) {
		return \esc_textarea($text);
	}
}

if (!function_exists('Confur\Shortcodes\do_shortcode')) {
	/**
	 * Mock/delegate do_shortcode()
	 */
	function do_shortcode($content, $ignore_html = false) {
		return \do_shortcode($content, $ignore_html);
	}
}

// Also define in Confur\Repositories namespace for AnswerRepository
namespace Confur\Repositories;

if (!function_exists('Confur\Repositories\get_the_ID')) {
	function get_the_ID() {
		return \get_the_ID();
	}
}

if (!function_exists('Confur\Repositories\get_post_meta')) {
	function get_post_meta($post_id, $key = '', $single = false) {
		return \get_post_meta($post_id, $key, $single);
	}
}

if (!function_exists('Confur\Repositories\update_post_meta')) {
	function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
		return \update_post_meta($post_id, $meta_key, $meta_value, $prev_value);
	}
}

if (!function_exists('Confur\Repositories\delete_post_meta')) {
	function delete_post_meta($post_id, $meta_key, $meta_value = '') {
		return \delete_post_meta($post_id, $meta_key, $meta_value);
	}
}