<?php

namespace Confur\Handlers;

use Confur\Config\Constants;
use Confur\Config\ConfurSettings;
use Confur\Services\EmailService;
use Confur\Repositories\AnswerRepository;

/**
 * Handles answer submission and processing
 */
class AnswerHandler
{
	private AnswerRepository $answerRepository;

	public function __construct()
	{
		$this->answerRepository = new AnswerRepository();
	}

	/**
	 * Handle answer submission
	 */
    public function handleSubmission(): void
    {
        try {
            error_log('AnswerHandler::handleSubmission');

//            // Only allow HTTPS
//            if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
//                error_log('AnswerHandler::handleSubmission - HTTPS required');
//                wp_send_json_error(['message' => 'HTTPS required.'], 403);
//                return;
//            }

            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_answers'])) {
                error_log('AnswerHandler::handleSubmission - Invalid request method or missing submit_answers field');
                wp_send_json_error(['message' => 'Unrecognized Action.'], 400);
                return;
            }

            // Verify nonce for CSRF protection (unless disabled in settings)
            if (!ConfurSettings::isNonceVerificationDisabled()) {
                if (!isset($_POST['answer_submission_nonce']) || 
                    !wp_verify_nonce($_POST['answer_submission_nonce'], 'answer_submission_action')) {
                    error_log('AnswerHandler::handleSubmission - Nonce verification failed');
                    wp_send_json_error(['message' => 'Security check failed.'], 403);
                    return;
                }
            }

            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
            $postId = url_to_postid($referer);

            if ($postId === 0) {
                error_log("AnswerHandler::handleSubmission - Could not determine post ID from URI: $referer");
                wp_send_json_error(['message' => 'Invalid referer URL.'], 400);
                return;
            }

            if (get_post_status($postId) === false) {
                error_log("AnswerHandler::handleSubmission - Post ID: $postId does not exist");
                wp_send_json_error(['message' => 'Post does not exist.'], 404);
                return;
            }

            // Send backup email (sanitize POST data before logging)
            $subject = get_permalink($postId);

            try {
                EmailService::sendBackup(
                    ConfurSettings::getBackupEmail(),
                    ConfurSettings::getSupportEmail(),
                    $subject,
                    json_encode($_POST)
                );
            } catch (\Exception $e) {
                error_log("AnswerHandler::handleSubmission - Failed to send backup email: " . $e->getMessage());
            }

            error_log("AnswerHandler::handleSubmission - Processing answers for Post ID: $postId");

            // Update answer fields
            $this->updateAnswerFields($postId, $_POST);

            // Update status
            $validStatuses = [Constants::STATUS_DRAFT, Constants::STATUS_COMPLETED];
            $status = isset($_POST['submit_answers']) && in_array($_POST['submit_answers'], $validStatuses)
                ? sanitize_text_field($_POST['submit_answers'])
                : Constants::STATUS_DRAFT;

            $updated = current_time('l, Y-m-d h:i:s A');

            update_field(Constants::UPDATED_FIELD, esc_html($updated), $postId);
            update_field(Constants::STATUS_FIELD, esc_html($status), $postId);

            error_log("AnswerHandler::handleSubmission - Answers for Post ID: $postId Status: $status");

            $email = get_field(Constants::EMAIL_FIELD, $postId);

            if ($status === Constants::STATUS_COMPLETED) {
                update_field(Constants::COMPLETION_FIELD, esc_html($updated), $postId);
            } else {
                update_field(Constants::COMPLETION_FIELD, "", $postId);
            }

            acf_save_post();
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
                'message' => "Answers Saved as $status",
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
				error_log("AnswerHandler::handleRegistration - Email is blocked: $email for post ID: $postId");

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

			if (empty($meetingId)) {
				error_log("Error: No meeting group set for post ID: $postId");

				$errorBody = '<p>There was an issue with your registration: No meeting group was given.</p>';
				$params = ['content' => $errorBody];

				try {
					EmailService::sendCustomEmail(
						$email,
						ConfurSettings::getSupportEmail(),
						'Error: Missing Meeting Group',
						$params
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

			update_field(Constants::STATUS_FIELD, Constants::DEFAULT_STATUS);
			acf_save_post();

			wp_update_post([
				'ID' => $postId,
				'post_title' => $title,
				'post_name' => $slug
			]);

			$url = get_permalink($postId);

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
					$params = ['content' => $errorBody];
					EmailService::sendEmail(
						$email,
						ConfurSettings::getSupportEmail(),
						'Error: Registration Failed',
						$params
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
	 * @param array $data POST data
	 */
	private function updateAnswerFields(int $postId, array $data): void
	{
		foreach ($data as $key => $newValue) {
			if (preg_match('/^c\d+_a\d+$/', $key)) {
				$sanitizedValue = sanitize_textarea_field($newValue);
				error_log($key . ' = ' . $newValue);

				$existing = $this->answerRepository->getValue($key);

				if ($existing !== $sanitizedValue) {
					error_log("AnswerHandler::updateAnswerFields - Updating field $key current value: '{$existing}' new value: '{$sanitizedValue}'");
					if (!update_field($key, $sanitizedValue, $postId)) {
//					if (!AcfHelper::update_acf_field2($postId, $key, $sanitizedValue)) {
						error_log("AnswerHandler::updateAnswerFields - Failed to update field $key for Post ID: $postId");
					}
				}
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