<?php

namespace Confur\Repositories;

/**
 * Repository for meeting data operations
 */
class MeetingRepository
{
	/**
	 * Get all meetings
	 *
	 * @return array All meetings data
	 */
	public function getMeetings(): array
	{
		$posts = get_posts([
			'post_type' => 'tsml_meeting',
			'numberposts' => -1,
			'post_status' => 'publish',
		]);

		$meetings = [];

		foreach ($posts as $post) {
			$meetingMeta = get_post_custom($post->ID);

			$types = isset($meetingMeta['types']) && !empty($meetingMeta['types'][0])
				? unserialize($meetingMeta['types'][0])
				: [];

			$meetings[] = [
				'id' => $post->ID,
				'name' => $post->post_title,
				'slug' => $post->post_name,
				'location' => get_the_title($post->post_parent),
				'url' => get_permalink($post->ID),
				'day' => $meetingMeta['day'][0] ?? '',
				'time' => $meetingMeta['time'][0] ?? '',
				'end_time' => $meetingMeta['end_time'][0] ?? '',
				'online' => $this->isOnline($types),
				'allocated' => get_field('allocated_committee', $post->ID) ?: '',
			];
		}

		return array_reverse($meetings);
	}

	/**
	 * Get meeting contacts
	 *
	 * @param int $postId Meeting post ID
	 * @return array Meeting contacts
	 */
	public function getMeetingContacts(int $postId): array
	{
		$contacts = [];
		$meetingMeta = get_post_custom($postId);

		for ($count = 1; $count <= 3; $count++) {
			$name = $meetingMeta["contact_{$count}_name"][0] ?? '';

			if (!empty($name)) {
				$phone = $meetingMeta["contact_{$count}_phone"][0] ?? '';
				$email = $meetingMeta["contact_{$count}_email"][0] ?? '';

				$contacts[] = [
					'name' => $name,
					'phone' => $phone,
					'email' => $email
				];
			}
		}

		return $contacts;
	}

	/**
	 * Check if meeting is online
	 *
	 * @param array $types Meeting types
	 * @return bool True if online
	 */
	private function isOnline(array $types): bool
	{
		return in_array('ONL', $types, false);
	}
}