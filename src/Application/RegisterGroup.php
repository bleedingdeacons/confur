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
 * The form plugin has already created the `answer` post with the meeting(s)
 * and email filled in. From there, in order:
 * - a blocked address is told no, and its post deleted if the setting says so;
 * - with duplicate detection on, a repeat registration is trashed and the
 *   group is re-sent its existing link;
 * - a registration with no meeting is reported to the registrant;
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
            $existing = $this->duplicates->find($registration->meetingId, $registration->fellowMeetingId, $email, $postId);

            if ($existing !== null) {
                $this->resendExisting($postId, $existing->postId, $registration->meetingId, $registration->fellowMeetingId, $email);
                return;
            }
        }

        if ($registration->meetingId === null) {
            error_log("RegisterGroup::register - No meeting group set for post ID: $postId");
            $this->mailer->sendRegistrationError(
                $email,
                'Error: Missing Meeting Group',
                'There was an issue with your registration: No meeting group was given.'
            );
            return;
        }

        $meetingName = self::meetingName($registration->meetingId, $registration->fellowMeetingId);

        update_field(Constants::STATUS_FIELD, AnswerState::Draft->value, $postId);

        $postData = ['ID' => $postId, 'post_title' => 'Answers from ' . $meetingName];
        $slug = self::unguessableSlug($meetingName);

        // Without a slug, leave post_name out and let WordPress derive one,
        // rather than blanking it.
        if ($slug !== null) {
            $postData['post_name'] = $slug;
        }

        wp_update_post($postData);

        $this->mailer->sendConfirmation(
            $email,
            $meetingName,
            get_permalink($postId) ?: '',
            self::allocatedCommittee($registration->meetingId)
        );
    }

    /**
     * The name a registration goes by: its meeting, or both meetings for a
     * pair, each cut to 85 characters so the title stays a sensible length.
     */
    public static function meetingName(int $meetingId, ?int $fellowMeetingId): string
    {
        $name = get_the_title($meetingId);

        if ($fellowMeetingId !== null) {
            $name = substr($name, 0, 85) . ' and ' . substr(get_the_title($fellowMeetingId), 0, 85);
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

    private function resendExisting(int $postId, int $existingId, ?int $meetingId, ?int $fellowMeetingId, string $email): void
    {
        error_log("RegisterGroup::register - Post $postId duplicates post $existingId; trashing it and resending the existing link");

        wp_trash_post($postId);

        $this->mailer->sendConfirmation(
            $email,
            $meetingId !== null ? self::meetingName($meetingId, $fellowMeetingId) : '',
            get_permalink($existingId) ?: '',
            $meetingId !== null ? self::allocatedCommittee($meetingId) : '',
            true
        );
    }

    private static function allocatedCommittee(int $meetingId): string
    {
        $allocation = get_field(Constants::ALLOCATION_FIELD, $meetingId);

        return is_scalar($allocation) ? (string) $allocation : '';
    }

    /**
     * A random prefix plus the meeting name. The answer page is reachable by
     * anyone with the link, so the prefix is what keeps it private.
     */
    private static function unguessableSlug(string $meetingName): ?string
    {
        if ($meetingName === '') {
            return null;
        }

        try {
            $prefix = substr(hash('sha256', random_bytes(16)), 0, 16);
        } catch (\Exception $e) {
            error_log('RegisterGroup::unguessableSlug - Could not generate a prefix: ' . $e->getMessage());
            return null;
        }

        return $prefix . '_' . str_replace('-', '_', sanitize_title($meetingName));
    }
}
