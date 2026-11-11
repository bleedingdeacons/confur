<?php

namespace Confur\Repositories;

use Confur\Storage\AnswerStore;

/**
 * Array-shaped views of answer data for the admin screens and handlers.
 *
 * The reading is done by AnswerStore; this class only reshapes Answer objects
 * into the arrays its existing callers expect. New code should use the store
 * and Answer directly — these methods go away as their callers are moved over.
 */
class AnswerRepository
{
    private AnswerStore $store;

    public function __construct(?AnswerStore $store = null)
    {
        $this->store = $store ?? new AnswerStore();
    }

    /**
     * Get value from ACF field
     *
     * @param string $name Field name
     * @param int|false $postId Post to read from. False means the current
     *                          post, which only exists inside the loop — pass
     *                          the id anywhere else (e.g. admin-post.php).
     * @return string Sanitized value
     */
    public function getValue(string $name, int|false $postId = false): string
    {
        return $this->store->value($name, $postId);
    }

    /**
     * Get answer status
     *
     * A read — the public REST endpoint calls it — so a post with no state
     * yet is reported as a draft rather than having one written.
     *
     * @param int $postId Post ID
     * @return array{state: string, updated: string} Status data
     */
    public function getAnswerStatus(int $postId): array
    {
        $answer = $this->store->load($postId);

        return [
            'state' => $answer->state()->value,
            'updated' => ($answer->updated ?? '') !== '' ? (string) $answer->updated : 'N/A',
        ];
    }

    /**
     * Get all answer posts
     *
     * @return array<int, int> Answer post IDs
     */
    public function getAllAnswers(): array
    {
        return $this->store->ids();
    }

    /**
     * Get registered groups
     *
     * @return array<int, array<string, mixed>> Registered groups data
     */
    public function getRegisteredGroups(): array
    {
        $registered = [];

        foreach ($this->store->all() as $answer) {
            if ($answer->meetingId === null) {
                continue;
            }

            $registered[] = [
                'answersId' => $answer->postId,
                'meetingId' => $answer->meetingId,
                'fellowMeetingId' => $answer->fellowMeetingId,
                'email' => $answer->email,
                'updated' => $answer->updated,
                'status' => $answer->status,
            ];
        }

        return $registered;
    }

    /**
     * Get group answers
     *
     * Every non-empty answer from every registration that has been saved at
     * least once, grouped by answer field name.
     *
     * @return array<string, list<array<string, mixed>>> Field name => answers
     */
    public function getGroupAnswers(): array
    {
        $grouped = [];

        foreach ($this->store->all(true) as $answer) {
            if (($answer->updated ?? '') === '') {
                continue;
            }

            // get_the_title() will not take null, and the meeting can be empty.
            $meetingName = $answer->meetingId !== null ? get_the_title($answer->meetingId) : '';
            $resultUrl = get_permalink($answer->postId);

            foreach ($answer->answers as $fieldName => $text) {
                $grouped[$fieldName][] = [
                    'meetingId' => $answer->meetingId,
                    'fellowMeetingId' => $answer->fellowMeetingId,
                    'meetingName' => $meetingName,
                    'resultUrl' => $resultUrl,
                    'email' => $answer->email,
                    'updated' => $answer->updated,
                    'answer' => $text,
                    'status' => $answer->status,
                ];
            }
        }

        return $grouped;
    }
}
