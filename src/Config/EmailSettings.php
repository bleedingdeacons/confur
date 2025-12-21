<?php

namespace Confur\Config;

/**
 * Email Settings Manager
 * Handles storage and retrieval of email addresses using WordPress options
 */
class EmailSettings
{
	// Option name for storing email settings
	private const OPTION_NAME = 'confur_email_settings';

	// Default email addresses
	private const DEFAULTS = [
		'registration_reply' => 'conference@your.domain',
		'support' => 'support@your.domain',
		'backup' => 'backup@your.domain',
	];

	/**
	 * Get all email settings
	 *
	 * @return array Email settings
	 */
	public static function getAll(): array
	{
		$settings = get_option(self::OPTION_NAME, []);

		// Merge with defaults to ensure all keys exist
		return wp_parse_args($settings, self::DEFAULTS);
	}

	/**
	 * Get registration reply email
	 *
	 * @return string Email address
	 */
	public static function getRegistrationReplyEmail(): string
	{
		$settings = self::getAll();
		return $settings['registration_reply'];
	}

	/**
	 * Get support email
	 *
	 * @return string Email address
	 */
	public static function getSupportEmail(): string
	{
		$settings = self::getAll();
		return $settings['support'];
	}

	/**
	 * Get backup email
	 *
	 * @return string Email address
	 */
	public static function getBackupEmail(): string
	{
		$settings = self::getAll();
		return $settings['backup'];
	}

	/**
	 * Update all email settings
	 *
	 * @param array $settings Email settings to update
	 * @return bool True on success, false on failure
	 */
	public static function updateAll(array $settings): bool
	{
		// Sanitize email addresses
		$sanitized = [
			'registration_reply' => sanitize_email($settings['registration_reply'] ?? ''),
			'support' => sanitize_email($settings['support'] ?? ''),
			'backup' => sanitize_email($settings['backup'] ?? ''),
		];

		// Validate all emails
		foreach ($sanitized as $key => $email) {
			if (!is_email($email)) {
				return false;
			}
		}

		return update_option(self::OPTION_NAME, $sanitized);
	}

	/**
	 * Reset to default values
	 *
	 * @return bool True on success, false on failure
	 */
	public static function resetToDefaults(): bool
	{
		return update_option(self::OPTION_NAME, self::DEFAULTS);
	}

	/**
	 * Get default values
	 *
	 * @return array Default email settings
	 */
	public static function getDefaults(): array
	{
		return self::DEFAULTS;
	}
}