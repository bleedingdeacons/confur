<?php

namespace Confur\Handlers;

use Confur\Config\Constants;
use Confur\Config\ConfurSettings;
use Confur\Domain\AnswerKey;
use Confur\Domain\AnswerState;
use Confur\Domain\QuestionSet;
use Confur\Services\EmailService;
use Confur\Repositories\AnswerRepository;
use Confur\Storage\AnswerStore;

/**
 * Handles answer submission and processing
 */
class AnswerHandler
{
    private AnswerRepository $answerRepository;
    private AnswerStore $store;

    public function __construct(?AnswerStore $store = null)
    {
        $this->store = $store ?? new AnswerStore();
        $this->answerRepository = new AnswerRepository($this->store);
    }

    /**
     * Handle answer submission
     */
    public function handleSubmission(): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_answers'])) {
                error_log('AnswerHandler::handleSubmission - Invalid request method or missing submit_answers field');
                wp_send_json_error(['message' => 'Unrecognized Action.'], 400);
            }

            // Submissions are constrained to same-site requests: the target post is
            // resolved from the referer below, and url_to_postid() only matches URLs
            // on this site, so a cross-site post cannot reach a valid post ID.
            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
            $postId = url_to_postid($referer);

            if ($postId === 0) {
                error_log("AnswerHandler::handleSubmission - Could not determine post ID from URI: $referer");
                wp_send_json_error(['message' => 'Invalid referer URL.'], 400);
            }

            if (get_post_status($postId) === false) {
                error_log("AnswerHandler::handleSubmission - Post ID: $postId does not exist");
                wp_send_json_error(['message' => 'Post does not exist.'], 404);
            }

            // The referer can resolve to any post on this site, so the type
            // has to be checked: without it a submission could write answer
            // fields onto, and publish, an unrelated post.
            if (get_post_type($postId) !== Constants::ANSWER_CUSTOM_TYPE) {
                error_log("AnswerHandler::handleSubmission - Post ID: $postId is not an answer post");
                wp_send_json_error(['message' => 'Post does not exist.'], 404);
            }

            // A cancelled registration is read-only. The client disables its
            // buttons, but that is a convenience, not the enforcement.
            if (AnswerState::fromStored(get_field(Constants::STATUS_FIELD, $postId)) === AnswerState::Cancelled) {
                error_log("AnswerHandler::handleSubmission - Post ID: $postId is cancelled");
                wp_send_json_error(['message' => 'This registration has been cancelled.'], 403);
            }

            // Send backup email (sanitize POST data before logging)
            // Both are string|false. The post is known to exist by here so the
            // permalink will resolve, but the backup email is a safety net —
            // send it with what we have rather than not at all.
            $subject = get_permalink($postId) ?: '';
            $submitted = json_encode($_POST) ?: '';

            try {
                EmailService::sendBackup(
                    ConfurSettings::getBackupEmail(),
                    ConfurSettings::getSupportEmail(),
                    $subject,
                    $submitted
                );
            } catch (\Exception $e) {
                error_log("AnswerHandler::handleSubmission - Failed to send backup email: " . $e->getMessage());
            }

            error_log("AnswerHandler::handleSubmission - Processing answers for Post ID: $postId");

            // Update answer fields
            $this->updateAnswerFields($postId, $_POST);

            // Update status. A group may ask for Draft or Complete; anything
            // else, including Cancelled, is saved as a draft.
            $requested = AnswerState::fromStored($_POST['submit_answers'] ?? null);
            $state = $requested !== null && $requested->isSubmittable() ? $requested : AnswerState::Draft;

            // Complete means every question has an answer. The client only
            // enables the button when that holds, but the server decides: the
            // answers are saved either way, and an incomplete set stays a draft.
            $message = null;

            if ($state === AnswerState::Complete) {
                $questions = QuestionSet::current();
                $unanswered = $questions->unanswered($this->store->load($postId, true)->answers);

                if ($unanswered !== []) {
                    $state = AnswerState::Draft;
                    $message = sprintf(
                        'Answers Saved as Draft: %d %s still %s an answer.',
                        count($unanswered),
                        count($unanswered) === 1 ? 'question' : 'questions',
                        count($unanswered) === 1 ? 'needs' : 'need'
                    );
                    error_log("AnswerHandler::handleSubmission - Post ID: $postId asked for Complete with " . count($unanswered) . ' unanswered; saved as Draft');
                }
            }

            $status = $state->value;

            $updated = current_time('l, Y-m-d h:i:s A');

            // Stored raw; escaped where it is output.
            update_field(Constants::UPDATED_FIELD, $updated, $postId);
            update_field(Constants::STATUS_FIELD, $status, $postId);

            error_log("AnswerHandler::handleSubmission - Answers for Post ID: $postId Status: $status");

            $email = get_field(Constants::EMAIL_FIELD, $postId);

            if ($status === Constants::STATUS_COMPLETED) {
                update_field(Constants::COMPLETION_FIELD, $updated, $postId);
            } else {
                update_field(Constants::COMPLETION_FIELD, "", $postId);
            }

            // update_field() has already written each value. The bare
            // acf_save_post() that used to follow had no post id and no
            // $_POST['acf'] to save, so it did nothing.
            wp_publish_post($postId);

            $title = get_the_title($postId);

            if ($status === Constants::STATUS_COMPLETED) {
                try {
                    EmailService::sendCompletion($email, $title);
                } catch (\Exception $e) {
                    error_log("AnswerHandler::handleSubmission - Failed to send completion email: " . $e->getMessage());
                }
            }

            wp_send_json_success([
                'message' => $message ?? "Answers Saved as $status",
                'updated' => $updated,
                'state' => $status
            ]);
        } catch (\Exception $e) {
            error_log("AnswerHandler::handleSubmission - Unexpected error: " . $e->getMessage());
            error_log("AnswerHandler::handleSubmission - Stack trace: " . $e->getTraceAsString());
            wp_send_json_error([
                'message' => 'An error occurred while processing your submission. Please try again.'
            ], 500);
        }
    }

    /**
     * Handle after insert post
     *
     * @param string $formId Form ID
     * @param int $postId Post ID
     */
    public function handleRegistration(string $formId, int $postId): void
    {
        try {
            if (Constants::REGISTER_QUESTION_FORM !== $formId) {
                return;
            }

            error_log(Constants::REGISTER_QUESTION_FORM);

            $meetingId = get_field(Constants::MEETING_FIELD, $postId);
            $fellow_meetingId = get_field(Constants::FELLOW_MEETING_FIELD, $postId);
            $email = get_field(Constants::REGISTRATION_RECIPIENT_EMAIL, $postId);

            // Check if email is blocked
            if (ConfurSettings::isBlocked($email)) {
                error_log("AnswerHandler::handleRegistration - Blocked email rejected for post ID: $postId");

                // Delete the post if the setting is enabled
                if (ConfurSettings::shouldDeleteBlockedPosts()) {
                    wp_delete_post($postId, true);
                    error_log("AnswerHandler::handleRegistration - Deleted post ID: $postId for blocked email");
                }

                // Send a generic error response to the blocked user using template
                try {
                    EmailService::sendRegistrationBlocked($email);
                } catch (\Exception $e) {
                    error_log("AnswerHandler::handleRegistration - Failed to send blocked notification email: " . $e->getMessage());
                }

                return;
            }

            // Normalize meeting IDs for duplicate check
            $normalizedMeetingId = AnswerStore::normalizePostId($meetingId);
            $normalizedFellowMeetingId = AnswerStore::normalizePostId($fellow_meetingId);

            // Check for duplicate registration (only if feature is enabled)
            if (ConfurSettings::isDuplicateDetectionEnabled()) {
                error_log("AnswerHandler::handleRegistration - Checking for duplicates. Meeting: $normalizedMeetingId, Fellow Meeting: $normalizedFellowMeetingId, Excluding Post: $postId");

                $duplicate = $this->answerRepository->findDuplicate(
                    $normalizedMeetingId,
                    $normalizedFellowMeetingId,
                    $email,
                    $postId
                );

                error_log("AnswerHandler::handleRegistration - Duplicate check result: " . ($duplicate !== null ? "FOUND (post_id: {$duplicate['post_id']}, slug: {$duplicate['slug']})" : "NOT FOUND"));

                if ($duplicate !== null) {
                    error_log("AnswerHandler::handleRegistration - Duplicate registration found for meeting: $normalizedMeetingId, fellow_meeting: $normalizedFellowMeetingId");
                    error_log("AnswerHandler::handleRegistration - Existing post ID: {$duplicate['post_id']}, slug: {$duplicate['slug']}");

                    // Move the duplicate post to trash
                    wp_trash_post($postId);
                    error_log("AnswerHandler::handleRegistration - Moved post ID: $postId to trash");

                    // Send confirmation email with duplicate notification
                    $existingUrl = get_permalink($duplicate['post_id']) ?: '';
                    // AnswerStore::normalizePostId() returns null when the meeting field is
                    // empty, which get_the_title() will not take.
                    $meetingName = $normalizedMeetingId !== null
                        ? get_the_title($normalizedMeetingId)
                        : '';

                    if (!empty($normalizedFellowMeetingId)) {
                        $meetingName = substr($meetingName, 0, 85) . " and " . substr(get_the_title($normalizedFellowMeetingId), 0, 85);
                    }

                    // Get allocated committee from the existing meeting
                    $allocatedCommittee = get_field('allocated_committee', $normalizedMeetingId) ?: '';

                    try {
                        EmailService::sendConfirmation($email, $meetingName, $existingUrl, $allocatedCommittee, true);
                    } catch (\Exception $e) {
                        error_log("AnswerHandler::handleRegistration - Failed to send duplicate notification email: " . $e->getMessage());
                    }

                    return;
                }
            } else {
                error_log("AnswerHandler::handleRegistration - Duplicate detection is disabled, skipping check");
            }

            if (empty($meetingId)) {
                error_log("Error: No meeting group set for post ID: $postId");

                $errorBody = '<p>There was an issue with your registration: No meeting group was given.</p>';

                try {
                    EmailService::sendEmail(
                        $email,
                        ConfurSettings::getSupportEmail(),
                        'Error: Missing Meeting Group',
                        $errorBody
                    );
                } catch (\Exception $e) {
                    error_log("AnswerHandler::handleRegistration - Failed to send error email: " . $e->getMessage());
                }

                return;
            }

            $meetingName = get_the_title($meetingId);

            if (!empty($fellow_meetingId)) {
                $meetingName = substr($meetingName, 0, 85)  . " and " . substr(get_the_title($fellow_meetingId), 0, 85);
            }

            $slug = $this->generateUniqueSlug($meetingName);

            $title = 'Answers from ' . $meetingName;

            // Without the post id this wrote to whatever ACF took the current
            // post to be, so a new registration never got its initial state.
            update_field(Constants::STATUS_FIELD, Constants::DEFAULT_STATUS, $postId);

            $postData = [
                'ID' => $postId,
                'post_title' => $title,
            ];

            // generateUniqueSlug() returns false when it cannot build one.
            // Omit post_name in that case and let WordPress derive it from the
            // title, rather than sending false and blanking the slug.
            if ($slug !== false) {
                $postData['post_name'] = $slug;
            }

            wp_update_post($postData);

            $url = get_permalink($postId) ?: '';

            // Get allocated committee from the meeting
            $allocatedCommittee = get_field('allocated_committee', $meetingId) ?: '';

            try {
                EmailService::sendConfirmation($email, $meetingName, $url, $allocatedCommittee);
            } catch (\Exception $e) {
                error_log("AnswerHandler::handleRegistration - Failed to send registration confirmation email: " . $e->getMessage());
                // Continue processing even if email fails
            }
        } catch (\Exception $e) {
            error_log("AnswerHandler::handleRegistration - Unexpected error: " . $e->getMessage());
            error_log("AnswerHandler::handleRegistration - Stack trace: " . $e->getTraceAsString());

            // Attempt to send error notification email if we have an email address
            if (!empty($email)) {
                try {
                    $errorBody = '<p>There was an unexpected error during your registration. Please try again or contact support.</p>';
                    EmailService::sendEmail(
                        $email,
                        ConfurSettings::getSupportEmail(),
                        'Error: Registration Failed',
                        $errorBody
                    );
                } catch (\Exception $emailException) {
                    error_log("AnswerHandler::handleRegistration - Failed to send error notification email: " . $emailException->getMessage());
                }
            }
        }
    }

    /**
     * Update answer fields from POST data
     *
     * @param int $postId Post ID
     * @param array<string, mixed> $data POST data
     */
    private function updateAnswerFields(int $postId, array $data): void
    {
        foreach ($data as $fieldName => $newValue) {
            $key = AnswerKey::parse((string) $fieldName);

            if ($key === null) {
                continue;
            }

            $sanitizedValue = sanitize_textarea_field($newValue);

            // Field keys and post IDs only. The values are conference
            // answers written by members and must not reach the PHP
            // error log, which is neither access-controlled nor covered
            // by Scrutiny's audit trail.
            if ($this->store->value($key->fieldName(), $postId) === $sanitizedValue) {
                continue;
            }

            if (!$this->store->saveAnswer($postId, $key, $sanitizedValue)) {
                error_log("AnswerHandler::updateAnswerFields - Failed to update field {$key->fieldName()} for Post ID: $postId");
            }
        }
    }

    /**
     * Generate unique slug for answer page
     *
     * @param string $pageTitle Page title
     * @return string|false Unique slug or false on error
     */
    private function generateUniqueSlug(string $pageTitle)
    {
        if (empty($pageTitle)) {
            error_log('AnswerHandler::generateUniqueSlug - Empty page title received');
            return false;
        }

        try {
            $prefix = substr(hash('sha256', random_bytes(16)), 0, 16);
        } catch (\Exception $e) {
            error_log('AnswerHandler::generateUniqueSlug - Error generating prefix: ' . $e->getMessage());
            return false;
        }

        $pageTitle = sanitize_title($pageTitle);
        $suffix = str_replace('-', '_', $pageTitle);

        return $prefix . '_' . $suffix;
    }
}
