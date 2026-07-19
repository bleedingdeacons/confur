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
			'post_status' => ['publish', 'draft', 'pending', 'private'],
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

			// Add entry if primary meeting exists (includes fellow_meeting if present)
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
		}

		return $registered;
	}

	/**
	 * Find duplicate registration by meeting, fellow_meeting, and email
	 * Handles both paths - checks if the meeting/fellow_meeting combination matches
	 * in either order (A+B or B+A)
	 * Only compares answers with both meetings against other answers with both meetings
	 * Returns the latest duplicate based on the 'updated' field if multiple exist
	 *
	 * @param int|null $meetingId Meeting post ID
	 * @param int|null $fellowMeetingId Fellow meeting post ID (can be null)
	 * @param string $email Email address
	 * @param int|null $excludePostId Post ID to exclude from search (the newly created post)
	 * @return array|null Array with 'post_id' and 'slug' if duplicate found, null otherwise
	 */
	public function findDuplicate(?int $meetingId, ?int $fellowMeetingId, string $email, ?int $excludePostId = null): ?array
	{
		error_log("AnswerRepository::findDuplicate - Called with meetingId: " . ($meetingId ?? 'null') . ", fellowMeetingId: " . ($fellowMeetingId ?? 'null') . ", email: $email, excludePostId: " . ($excludePostId ?? 'null'));

		if (empty($meetingId) || empty($email)) {
			error_log("AnswerRepository::findDuplicate - Returning null due to empty meetingId or email");
			return null;
		}

		// Determine if this is a paired registration (has both meetings)
		$isPairedRegistration = !empty($fellowMeetingId);

		// Get all answer posts and filter in PHP - more reliable with ACF fields
		$args = [
			'post_type' => Constants::ANSWER_CUSTOM_TYPE,
			'posts_per_page' => -1,
			'post_status' => ['publish', 'draft', 'pending', 'private'],
			'fields' => 'ids'
		];

		// Exclude the newly created post from the search
		if ($excludePostId) {
			$args['post__not_in'] = [$excludePostId];
		}

		$allPosts = get_posts($args);

		error_log("AnswerRepository::findDuplicate - Found " . count($allPosts) . " total answer posts to check");

		// Build the set of meeting IDs from the new registration
		$inputMeetingIds = [$meetingId];
		if ($isPairedRegistration) {
			$inputMeetingIds[] = $fellowMeetingId;
		}
		sort($inputMeetingIds);

		// Collect all matching duplicates
		/** @var array<int, array{post_id: int, updated: string}> $duplicates */
		$duplicates = [];

		foreach ($allPosts as $postId) {
			// Get ACF fields and normalize them
			$postMeeting = $this->normalizePostId(get_field(Constants::MEETING_FIELD, $postId));
			$postFellowMeeting = $this->normalizePostId(get_field(Constants::FELLOW_MEETING_FIELD, $postId));
			$postEmail = get_field(Constants::EMAIL_FIELD, $postId);
			$postStatus = get_field(Constants::STATUS_FIELD, $postId);
			// Cast at the source: get_field() is mixed, and the usort below
			// feeds this straight to strtotime(), which wants a string.
			$postUpdated = (string) (get_field(Constants::UPDATED_FIELD, $postId) ?? '');

			error_log("AnswerRepository::findDuplicate - Checking post $postId: meeting=$postMeeting, fellow=$postFellowMeeting, email=$postEmail, status=$postStatus, updated=$postUpdated");

			// Skip cancelled registrations
			if ($postStatus === Constants::STATUS_CANCELLED) {
				continue;
			}

			// Check email match (case-insensitive)
			if (strtolower($postEmail) !== strtolower($email)) {
				continue;
			}

			// Determine if existing post is a paired registration
			$isPostPaired = !empty($postFellowMeeting);

			// Only compare paired with paired, and single with single
			if ($isPairedRegistration !== $isPostPaired) {
				continue;
			}

			// Build the set of meeting IDs from the existing post
			$postMeetingIds = [];
			if (!empty($postMeeting)) {
				$postMeetingIds[] = $postMeeting;
			}
			if (!empty($postFellowMeeting)) {
				$postMeetingIds[] = $postFellowMeeting;
			}
			sort($postMeetingIds);

			// Check if the meeting combinations match (handles swapped order for paired)
			if ($inputMeetingIds !== $postMeetingIds) {
				continue;
			}

			// Found a duplicate - add to list
			$duplicates[] = [
				'post_id' => $postId,
				'updated' => $postUpdated
			];
		}

		if (empty($duplicates)) {
			error_log("AnswerRepository::findDuplicate - No duplicate found");
			return null;
		}

		// Sort duplicates by updated date (latest first), fall back to post creation date
		usort($duplicates, function($a, $b) {
			// Handle empty/null updated values - fall back to post creation date
			$aUpdated = !empty($a['updated']) ? strtotime($a['updated']) : 0;
			$bUpdated = !empty($b['updated']) ? strtotime($b['updated']) : 0;

			// If both have updated dates, compare them
			if ($aUpdated > 0 && $bUpdated > 0) {
				return $bUpdated - $aUpdated; // Descending order (latest first)
			}

			// If only one has updated date, prefer the one with updated date
			if ($aUpdated > 0) return -1;
			if ($bUpdated > 0) return 1;

			// If neither has updated date, fall back to post creation date
			$aPost = get_post($a['post_id']);
			$bPost = get_post($b['post_id']);
			$aCreated = strtotime($aPost->post_date);
			$bCreated = strtotime($bPost->post_date);

			return $bCreated - $aCreated; // Descending order (latest first)
		});

		// Return the latest duplicate
		$latestDuplicate = $duplicates[0];
		$post = get_post($latestDuplicate['post_id']);

		error_log("AnswerRepository::findDuplicate - Found " . count($duplicates) . " duplicate(s), returning latest: post " . $latestDuplicate['post_id']);

		return [
			'post_id' => $latestDuplicate['post_id'],
			'slug' => $post->post_name
		];
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