<?php

namespace Confur\Repositories;

use Confur\Config\Constants;

/**
 * Repository for answer data operations
 */
class AnswerRepository
{
	/**
	 * Get value from ACF field
	 *
	 * @param string $name Field name
	 * @return string Sanitized value
	 */
	public function getValue(string $name): string
	{
		$value = get_field($name);
		return sanitize_textarea_field($value);
	}

	/**
	 * Get answer status
	 *
	 * @param int $postId Post ID
	 * @return array Status data
	 */
	public function getAnswerStatus(int $postId): array
	{
		$status = get_field(Constants::STATUS_FIELD, $postId);

		error_log($status);

		if (empty($status)) {
			update_field(Constants::STATUS_FIELD, Constants::STATUS_DRAFT, $postId);
			acf_save_post();
			$status = get_field(Constants::STATUS_FIELD, $postId);
		}

		$updated = get_field(Constants::UPDATED_FIELD, $postId);

		if (empty($updated)) {
			$updated = 'N/A';
		}

		return [
//			'id' => $postId,
			'state' => $status,
			'updated' => $updated
		];
	}

	/**
	 * Get all answer posts
	 *
	 * @return array Answer post IDs
	 */
	public function getAllAnswers(): array
	{
		$all = get_posts([
			'post_type' => Constants::ANSWER_CUSTOM_TYPE,
			'posts_per_page' => -1,
			'fields' => 'ids'
		]);

		return array_filter($all);
	}

	/**
	 * Get registered groups
	 *
	 * @return array Registered groups data
	 */
	public function getRegisteredGroups(): array
	{
		$registered = [];
		$all = $this->getAllAnswers();

		foreach ($all as $postId) {
			$meeting = get_field(Constants::MEETING_FIELD, $postId);
			$fellow_meeting = get_field(Constants::FELLOW_MEETING_FIELD, $postId);
			$email = get_field(Constants::EMAIL_FIELD, $postId);
			$updated = get_field(Constants::UPDATED_FIELD, $postId);
			$status = get_field(Constants::STATUS_FIELD, $postId);

			// Normalize meeting IDs (handle objects/arrays from ACF)
			$meetingId = $this->normalizePostId($meeting);
			$fellowMeetingId = $this->normalizePostId($fellow_meeting);

			// Add primary meeting if it exists
			if (!empty($meetingId)) {
				$registered[] = [
					'answersId' => $postId,
					'meetingId' => $meetingId,
					'fellowMeetingId' => $fellowMeetingId,
					'email' => $email,
					'updated' => $updated,
					'status' => $status
				];
			}

			// Add fellow_meeting as a separate entry if it exists
			if (!empty($fellowMeetingId)) {
				$registered[] = [
					'answersId' => $postId,
					'meetingId' => $fellowMeetingId,
					'fellowMeetingId' => null,
					'email' => $email,
					'updated' => $updated,
					'status' => $status
				];
			}
		}

		return $registered;
	}

	/**
	 * Normalize post ID from ACF field value
	 * ACF can return post ID as int, object, or array depending on configuration
	 *
	 * @param mixed $value ACF field value
	 * @return int|null Post ID or null
	 */
	private function normalizePostId($value): ?int
	{
		if (empty($value)) {
			return null;
		}

		// Already an integer
		if (is_numeric($value)) {
			return (int)$value;
		}

		// Post object
		if (is_object($value) && isset($value->ID)) {
			return (int)$value->ID;
		}

		// Array with ID key
		if (is_array($value) && isset($value['ID'])) {
			return (int)$value['ID'];
		}

		return null;
	}

	/**
	 * Get group answers
	 *
	 * @return array Group answers data
	 */
//	public function getGroupAnswers(): array
//	{
//		$answers = [];
//		$all = $this->getAllAnswers();
//
//		foreach ($all as $postId) {
//			$meeting = get_field(Constants::MEETING_FIELD, $postId);
//			$email = get_field(Constants::EMAIL_FIELD, $postId);
//			$updated = get_field(Constants::UPDATED_FIELD, $postId);
//			$status = get_field(Constants::STATUS_FIELD, $postId);
//
//			if (!empty($updated)) {
//				$allFields = get_fields($postId);
//
//				foreach ($allFields as $fieldName => $fieldValue) {
//					if (str_starts_with($fieldName, 'c')) {
//						foreach ($fieldValue as $questionName => $answer) {
//							if (!empty($answer)) {
//								$meetingName = get_the_title($meeting);
//								$resultUrl = get_permalink($postId);
//
//								$groupAnswer = [
//									$meeting,
//									$meetingName,
//									$resultUrl,
//									$email,
//									$updated,
//									$answer,
//									$status
//								];
//
//								$answers[$fieldName . '_' . $questionName][] = $groupAnswer;
//							}
//						}
//					}
//				}
//			}
//		}
//
//		return $answers;
//	}

	/**
	 * Get group answers
	 *
	 * @return array Group answers data
	 */
	public function getGroupAnswers(): array
	{
		$answers = [];
		$all = $this->getAllAnswers();

		foreach ($all as $postId) {
			// Skip posts that are in the trash
			if (get_post_status($postId) === 'trash') {
				continue;
			}

			$meeting = get_field(Constants::MEETING_FIELD, $postId);
			$fellow_meeting = get_field(Constants::FELLOW_MEETING_FIELD, $postId);
			$email = get_field(Constants::EMAIL_FIELD, $postId);
			$updated = get_field(Constants::UPDATED_FIELD, $postId);
			$status = get_field(Constants::STATUS_FIELD, $postId);

			// Normalize meeting IDs
			$meetingId = $this->normalizePostId($meeting);
			$fellowMeetingId = $this->normalizePostId($fellow_meeting);

			if (!empty($updated)) {

				$allFields = get_fields($postId);

				foreach ($allFields as $fieldName => $fieldValue) {

					if (preg_match('/^c\d+_/',$fieldName)) {

						$answer = $fieldValue;

						if (!empty($answer)) {

							$meetingName = get_the_title($meetingId);
							$resultUrl = get_permalink($postId);

							$groupAnswer = [
								'meetingId' => $meetingId,
								'fellowMeetingId' => $fellowMeetingId,
								'meetingName' => $meetingName,
								'resultUrl' => $resultUrl,
								'email' => $email,
								'updated' => $updated,
								'answer' => $answer,
								'status' => $status
							];

							$answers[$fieldName][] = $groupAnswer;
						}

					}
				}
			}
		}

		return $answers;
	}

}