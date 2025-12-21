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
			'id' => $postId,
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
			$email = get_field(Constants::EMAIL_FIELD, $postId);
			$updated = get_field(Constants::UPDATED_FIELD, $postId);
			$status = get_field(Constants::STATUS_FIELD, $postId);

			if (!empty($meeting)) {
				$registered[] = [
					'answers' => $postId,
					'meeting' => $meeting,
					'email' => $email,
					'updated' => $updated,
					'state' => $status
				];
			}
		}

		return $registered;
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
			$meeting = get_field(Constants::MEETING_FIELD, $postId);
			$email = get_field(Constants::EMAIL_FIELD, $postId);
			$updated = get_field(Constants::UPDATED_FIELD, $postId);
			$status = get_field(Constants::STATUS_FIELD, $postId);

			if (!empty($updated)) {

				$allFields = get_fields($postId);

				foreach ($allFields as $fieldName => $fieldValue) {

					if (preg_match('/^c\d+_/',$fieldName)) {

						$answer = $fieldValue;

						if (!empty($answer)) {

							$meetingName = get_the_title($meeting);
							$resultUrl = get_permalink($postId);

							$groupAnswer = [
								$meeting,
								$meetingName,
								$resultUrl,
								$email,
								$updated,
								$answer,
								$status
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