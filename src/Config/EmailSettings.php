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
        'registration_reply' => 'conference@aa-bristol.org',
        'support' => 'support@aa-bristol.org',
        'backup' => 'backup@aa-bristol.org',
    ];

    /**
     * Get all email settings
     *
     * @return array Email settings
     */
    public static function getAll(): array
    {
        $settings = get_option(self::OPTION_NAME, false);

        // If option doesn't exist, initialize with defaults
        if ($settings === false) {
            self::initialize();
            return self::DEFAULTS;
        }

        // If not an array, something went wrong - return defaults
        if (!is_array($settings)) {
            return self::DEFAULTS;
        }

        // Merge with defaults to ensure all keys exist
        return wp_parse_args($settings, self::DEFAULTS);
    }

    /**
     * Initialize email settings with defaults if they don't exist
     *
     * @return bool True if initialized, false if already exists
     */
    public static function initialize(): bool
    {
        // Only initialize if option doesn't exist
        if (get_option(self::OPTION_NAME) === false) {
            return add_option(self::OPTION_NAME, self::DEFAULTS);
        }

        return false;
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
        // Sanitize email addresses - use trim and sanitize_email
        $sanitized = [
            'registration_reply' => sanitize_email(trim($settings['registration_reply'] ?? '')),
            'support' => sanitize_email(trim($settings['support'] ?? '')),
            'backup' => sanitize_email(trim($settings['backup'] ?? '')),
        ];

        // Log sanitized values
        error_log('EmailSettings - Sanitized emails:');
        error_log('  registration_reply: "' . $sanitized['registration_reply'] . '"');
        error_log('  support: "' . $sanitized['support'] . '"');
        error_log('  backup: "' . $sanitized['backup'] . '"');

        // Validate all emails
        foreach ($sanitized as $key => $email) {
            error_log("EmailSettings - Validating '{$key}': '{$email}' - is_email=" . (is_email($email) ? 'true' : 'false'));

            if (empty($email)) {
                error_log("EmailSettings validation failed for '{$key}': email is empty");
                return false;
            }

            if (!is_email($email)) {
                error_log("EmailSettings validation failed for '{$key}': is_email() returned false for '{$email}'");
                return false;
            }
        }

        error_log('EmailSettings - All validations passed, updating option');

        // Get current values to check if anything changed
        $current = get_option(self::OPTION_NAME, []);

        // update_option returns false if the value is unchanged
        // So we need to check if update happened OR if values are already correct
        $result = update_option(self::OPTION_NAME, $sanitized);

        // If update_option returned false, check if it's because values are already the same
        if (!$result) {
            // Compare current with new values
            if ($current === $sanitized) {
                error_log('EmailSettings - Values unchanged, but that is OK');
                return true; // Values are already correct, return success
            }
        }

        error_log('EmailSettings - update_option result: ' . ($result ? 'true' : 'false'));

        return $result;
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
