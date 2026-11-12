<?php

namespace Confur\Application;

use Confur\Config\Constants;
use Confur\Config\Settings;
use Confur\Domain\AnswerState;
use Confur\Services\Mailer;
use Confur\Storage\AnswerStore;

/**
 * Turns a registration form submission into a group's answer page.
 *
 * The form plugin has already created the `answer` post with the group(s)
 * and email filled in. From there, in order:
 * - a blocked address is told no, and its post deleted if the setting says so;
 * - with duplicate detection on, a repeat registration is trashed and the
 *   group is re-sent its existing link;
 * - a registration with no group is reported to the registrant;
 * - otherwise the post gets its title, an unguessable slug (the slug is the
 *   only thing protecting the answer page) and the Draft state, and the group
 *   is sent the link.
 */
class RegisterGroup
{
    public function __construct(
        private readonly AnswerStore $store,
        private readonly DuplicateFinder $duplicates,
        private readonly Mailer $mailer,
        private readonly Settings $settings,
    ) {
    }

    public function register(int $postId): void
    {
        $registration = $this->store->load($postId);
        $email = (string) get_field(Constants::REGISTRATION_RECIPIENT_EMAIL, $postId);

        if ($this->settings->isBlocked($email)) {
            $this->reject($postId, $email);
            return;
        }

        if ($this->settings->duplicateDetection) {
            $existing = $this->duplicates->find($registration->groupId, $registration->fellowGroupId, $email, $postId);

            if ($existing !== null) {
                $this->resendExisting($postId, $existing->postId, $registration->groupId, $registration->fellowGroupId, $email);
                return;
            }
        }

        if ($registration->groupId === null) {
            error_log("RegisterGroup::register - No group set for post ID: $postId");
            $this->mailer->sendRegistrationError(
                $email,
                'Error: Missing Group',
                'There was an issue with your registration: no group was given.'
            );
            return;
        }

        $groupName = self::groupName($registration->groupId, $registration->fellowGroupId);

        update_field(Constants::STATUS_FIELD, AnswerState::Draft->value, $postId);

        $postData = ['ID' => $postId, 'post_title' => 'Answers from ' . $groupName];
        $slug = self::unguessableSlug($groupName);

        // Without a slug, leave post_name out and let WordPress derive one,
        // rather than blanking it.
        if ($slug !== null) {
            $postData['post_name'] = $slug;
        }

        wp_update_post($postData);

        $this->mailer->sendConfirmation(
            $email,
            $groupName,
            get_permalink($postId) ?: '',
            self::allocatedCommittee($registration->groupId)
        );
    }

    /**
     * The name a registration goes by: its group, or both groups for a
     * pair, each cut to 85 characters so the title stays a sensible length.
     */
    public static function groupName(int $groupId, ?int $fellowGroupId): string
    {
        $name = get_the_title($groupId);

        if ($fellowGroupId !== null) {
            $name = substr($name, 0, 85) . ' and ' . substr(get_the_title($fellowGroupId), 0, 85);
        }

        return $name;
    }

    private function reject(int $postId, string $email): void
    {
        error_log("RegisterGroup::register - Blocked email rejected for post ID: $postId");

        if ($this->settings->deleteBlockedPosts) {
            wp_delete_post($postId, true);
        }

        $this->mailer->sendRegistrationBlocked($email);
    }

    private function resendExisting(int $postId, int $existingId, ?int $groupId, ?int $fellowGroupId, string $email): void
    {
        error_log("RegisterGroup::register - Post $postId duplicates post $existingId; trashing it and resending the existing link");

        wp_trash_post($postId);

        $this->mailer->sendConfirmation(
            $email,
            $groupId !== null ? self::groupName($groupId, $fellowGroupId) : '',
            get_permalink($existingId) ?: '',
            $groupId !== null ? self::allocatedCommittee($groupId) : '',
            true
        );
    }

    private static function allocatedCommittee(int $groupId): string
    {
        $allocation = get_field(Constants::ALLOCATION_FIELD, $groupId);

        return is_scalar($allocation) ? (string) $allocation : '';
    }

    /**
     * A random prefix plus the group name. The answer page is reachable by
     * anyone with the link, so the prefix is what keeps it private.
     */
    private static function unguessableSlug(string $groupName): ?string
    {
        if ($groupName === '') {
            return null;
        }

        try {
            $prefix = substr(hash('sha256', random_bytes(16)), 0, 16);
        } catch (\Exception $e) {
            error_log('RegisterGroup::unguessableSlug - Could not generate a prefix: ' . $e->getMessage());
            return null;
        }

        return $prefix . '_' . str_replace('-', '_', sanitize_title($groupName));
    }
}
