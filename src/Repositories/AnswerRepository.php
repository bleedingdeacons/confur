<?php

namespace Confur\Repositories;

use Confur\Domain\Answer;
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
     * Find duplicate registration by meeting, fellow_meeting, and email
     *
     * A duplicate has the same email (ignoring case) and the same meetings —
     * in either order for a paired registration. Paired registrations only
     * match paired ones, single only single. Cancelled ones never match.
     * If several match, the most recently updated wins, falling back to the
     * most recently created.
     *
     * @param int|null $meetingId Meeting post ID
     * @param int|null $fellowMeetingId Fellow meeting post ID (can be null)
     * @param string $email Email address
     * @param int|null $excludePostId Post ID to exclude from search (the newly created post)
     * @return array{post_id: int, slug: string}|null The latest duplicate, or null
     */
    public function findDuplicate(?int $meetingId, ?int $fellowMeetingId, string $email, ?int $excludePostId = null): ?array
    {
        // Meeting and post IDs only — the registrant's address is not logged.
        error_log("AnswerRepository::findDuplicate - meetingId: " . ($meetingId ?? 'null') . ", fellowMeetingId: " . ($fellowMeetingId ?? 'null') . ", excludePostId: " . ($excludePostId ?? 'null'));

        if (empty($meetingId) || empty($email)) {
            return null;
        }

        $candidate = new Answer(0, $meetingId, $fellowMeetingId ?: null, $email, null, null);

        $duplicates = array_values(array_filter(
            $this->store->all(false, $excludePostId ?: null),
            static fn (Answer $existing): bool => !$existing->isCancelled()
                && strtolower($existing->email ?? '') === strtolower($email)
                && $existing->isPaired() === $candidate->isPaired()
                && $existing->meetingIds() === $candidate->meetingIds()
        ));

        if ($duplicates === []) {
            return null;
        }

        usort($duplicates, [$this, 'compareLatestFirst']);

        $latest = $duplicates[0];
        $post = get_post($latest->postId);

        error_log("AnswerRepository::findDuplicate - Found " . count($duplicates) . " duplicate(s), returning latest: post " . $latest->postId);

        return [
            'post_id' => $latest->postId,
            // Null only if the post went away since the query; the post_id is
            // what callers act on, so an empty slug is enough.
            'slug' => $post->post_name ?? '',
        ];
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

    /**
     * Latest-updated first; a post with an updated date before one without;
     * otherwise latest-created first. A post deleted since the query sorts last.
     */
    private function compareLatestFirst(Answer $a, Answer $b): int
    {
        $aUpdated = ($a->updated ?? '') !== '' ? (int) strtotime($a->updated ?? '') : 0;
        $bUpdated = ($b->updated ?? '') !== '' ? (int) strtotime($b->updated ?? '') : 0;

        if ($aUpdated > 0 && $bUpdated > 0) {
            return $bUpdated <=> $aUpdated;
        }

        if ($aUpdated > 0 || $bUpdated > 0) {
            return $aUpdated > 0 ? -1 : 1;
        }

        $aPost = get_post($a->postId);
        $bPost = get_post($b->postId);
        $aCreated = $aPost !== null ? (int) strtotime($aPost->post_date) : 0;
        $bCreated = $bPost !== null ? (int) strtotime($bPost->post_date) : 0;

        return $bCreated <=> $aCreated;
    }
}
