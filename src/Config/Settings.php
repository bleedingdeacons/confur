<?php

namespace Confur\Config;

/**
 * The plugin settings the application needs, read once and passed in.
 *
 * ConfurSettings stays the read/write layer behind the Settings admin page;
 * this is the read side as a value, so the services that use it take it in
 * their constructor and a test can build one directly instead of seeding
 * WordPress options.
 */
final readonly class Settings
{
    /**
     * @param list<string> $blocklist Lower-cased blocked addresses.
     */
    public function __construct(
        public string $registrationReplyEmail,
        public string $supportEmail,
        public string $backupEmail,
        public bool $deleteBlockedPosts = false,
        public bool $duplicateDetection = false,
        public array $blocklist = [],
    ) {
    }

    /** The settings saved on the Settings admin page (or their defaults). */
    public static function fromOptions(): self
    {
        return new self(
            registrationReplyEmail: ConfurSettings::getRegistrationReplyEmail(),
            supportEmail: ConfurSettings::getSupportEmail(),
            backupEmail: ConfurSettings::getBackupEmail(),
            deleteBlockedPosts: ConfurSettings::shouldDeleteBlockedPosts(),
            duplicateDetection: ConfurSettings::isDuplicateDetectionEnabled(),
            blocklist: array_values(array_map('strval', ConfurSettings::getBlocklist())),
        );
    }

    /** Whether an address is on the blocklist, ignoring case and whitespace. */
    public function isBlocked(string $email): bool
    {
        return in_array(strtolower(sanitize_email(trim($email))), $this->blocklist, true);
    }
}
