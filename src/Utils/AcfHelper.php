<?php

namespace Confur\Utils;

class AcfHelper {

	/**
	 * Update a single ACF field using $_POST method with field name to field key translation
	 *
	 * @param int $post_id The post ID to update
	 * @param string $field_name The ACF field name (e.g., 'product_details_price')
	 * @param mixed $value The value to set
	 * @return bool True on success, false on failure
	 */
	static function update_acf_field($post_id, $field_name, $value) {
		if (empty($post_id) || empty($field_name)) {
			return false;
		}

		// Get the field object by name
		$field_object = acf_get_field($field_name);

		if (!$field_object || !isset($field_object['key'])) {
			error_log("ACF field not found: {$field_name}");
			return false;
		}

		// Set up $_POST with the field key
		$_POST['acf'] = array(
			$field_object['key'] => $value
		);

		// Trigger ACF's save process
		acf_save_post($post_id);

		// Clean up $_POST
		unset($_POST['acf']);

		// Clear caches
		clean_post_cache($post_id);
		wp_cache_flush();

		return true;
	}

	/**
	 * Update ACF fields using $_POST method with field name to field key translation
	 *
	 * @param int $post_id The post ID to update
	 * @param array $fields Associative array of field_name => value pairs
	 * @return bool True on success, false on failure
	 */
	static function update_acf_fields($post_id, $fields) {
		if (empty($post_id) || empty($fields) || !is_array($fields)) {
			return false;
		}

		// Initialize $_POST['acf'] array
		$_POST['acf'] = array();

		// Translate field names to field keys
		foreach ($fields as $field_name => $value) {
			// Get the field object by name
			$field_object = acf_get_field($field_name);

			if ($field_object && isset($field_object['key'])) {
				// Use the field key (e.g., 'field_abc123') in $_POST
				$_POST['acf'][$field_object['key']] = $value;
			} else {
				error_log("ACF field not found: {$field_name}");
			}
		}

		// Only proceed if we have valid fields
		if (empty($_POST['acf'])) {
			error_log("No valid ACF fields to update for post {$post_id}");
			unset($_POST['acf']);
			return false;
		}

		// Trigger ACF's save process
		acf_save_post($post_id);

		// Clean up $_POST
		unset($_POST['acf']);

		// Clear all caches
		clean_post_cache($post_id);
		wp_cache_flush();

		return true;
	}

	/**
	 * Update ACF field with error handling and debugging
	 */
	static function update_acf_field2($post_id, $field_name, $value) {
		if (empty($post_id) || empty($field_name)) {
			error_log("Invalid post_id or field_name");
			return false;
		}

		// Check if post exists
		if (!get_post($post_id)) {
			error_log("Post {$post_id} does not exist");
			return false;
		}

		// Get the field object
		$field_object = acf_get_field($field_name);

		if (!$field_object || !isset($field_object['key'])) {
			error_log("ACF field not found: {$field_name}");
			return false;
		}

		error_log("Updating field: {$field_name} with key: {$field_object['key']}");

		try {
			// Set up $_POST
			$_POST['acf'] = array(
				$field_object['key'] => $value
			);

			error_log("POST data: " . print_r($_POST['acf'], true));

			// Save with ACF
			acf_save_post($post_id);

			// Clean up
			unset($_POST['acf']);

			clean_post_cache($post_id);

			error_log("Field updated successfully");
			return true;

		} catch (Exception $e) {
			error_log("Error updating ACF field: " . $e->getMessage());
			unset($_POST['acf']);
			return false;
		}
	}

}