<?php

namespace Confur\Handlers;

use Confur\Config\Constants;
use Confur\Services\EmailService;
use Confur\Repositories\AnswerRepository;

/**
 * Handles answer submission and processing
 */
class AnswerHandler
{
	private EmailService $emailService;
	private AnswerRepository $answerRepository;

	public function __construct()
	{
		$this->emailService = new EmailService();
		$this->answerRepository = new AnswerRepository();
	}

	/**
	 * Handle answer submission
	 */
	public function handleSubmission(): void
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_answers'])) {
			error_log('AnswerHandler::handleSubmission - Invalid request method or missing submit_answers field');
			wp_send_json_error(['message' => 'Unrecognized Action.'], 400);
			return;
		}

		if (!isset($_POST['post_id']) || !is_numeric($_POST['post_id'])) {
			error_log('AnswerHandler::handleSubmission - Invalid or missing post ID');
			wp_send_json_error(['message' => 'Invalid Post ID.'], 400);
			return;
		}

		$postId = intval($_POST['post_id']);

		if (get_post_status($postId) === false) {
			error_log("AnswerHandler::handleSubmission - Post ID: $postId does not exist");
			wp_send_json_error(['message' => 'Post does not exist.'], 404);
			return;
		}

		// Send backup email
		$subject = 'POST';
		if (isset($_SERVER['REQUEST_URI'])) {
			$subject = get_permalink($postId);
		}
		$this->emailService->sendBackupEmail(
			'backup@aa-bristol.org',
			Constants::SUPPORT_EMAIL,
			$subject,
			serialize($_POST)
		);

		error_log("AnswerHandler::handleSubmission - Processing answers for Post ID: $postId");

		// Update answer fields
		$this->updateAnswerFields($postId, $_POST);

		// Update status
		$validStatuses = [Constants::STATUS_DRAFT, Constants::STATUS_COMPLETED];
		$status = isset($_POST['submit_answers']) && in_array($_POST['submit_answers'], $validStatuses)
			? sanitize_text_field($_POST['submit_answers'])
			: Constants::STATUS_DRAFT;

		error_log('Status: ' . $status);

		$updated = current_time('l, Y-m-d h:i:s A');

		update_field(Constants::UPDATED_FIELD, esc_html($updated), $postId);
		update_field(Constants::STATUS_FIELD, esc_html($status), $postId);

		$meetingId = get_field(Constants::MEETING_FIELD, $postId);
		$meetingName = get_the_title($meetingId);
		$email = get_field(Constants::EMAIL_FIELD, $postId);

		acf_save_post();
		wp_publish_post($postId);

		if ($status === Constants::STATUS_COMPLETED) {
			$this->emailService->sendCompletionThanks($email, $meetingName);
		}

		wp_send_json_success([
			'message' => "Answers Saved as $status",
			'updated' => $updated,
			'state' => $status
		]);
	}

	/**
	 * Handle after insert post
	 *
	 * @param string $formId Form ID
	 * @param int $postId Post ID
	 */
	public function handleAfterInsert(string $formId, int $postId): void
	{
		error_log('AnswerHandler::handleAfterInsert triggered');

		if (Constants::REGISTER_QUESTION_FORM !== $formId) {
			return;
		}

		error_log(Constants::REGISTER_QUESTION_FORM);

		$meetingId = get_field(Constants::MEETING_FIELD, $postId);
		$email = get_field(Constants::REGISTRATION_RECIPIENT_EMAIL, $postId);

		if (empty($meetingId)) {
			error_log("Error: No meeting group set for post ID: $postId");

			$errorBody = '<p>There was an issue with your registration: No meeting group was given.</p>';
			$params = ['content' => $errorBody];

			$this->emailService->sendCustomEmail(
				$email,
				Constants::SUPPORT_EMAIL,
				'Error: Missing Meeting Group',
				$params
			);

			return;
		}

		$meetingName = get_the_title($meetingId);
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
		$this->emailService->sendRegistrationConfirmation($email, $meetingName, $url);
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