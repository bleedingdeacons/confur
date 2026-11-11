<?php

namespace Confur\Application;

use Confur\Domain\Answer;
use Confur\Storage\AnswerStore;

/**
 * Finds an existing registration that a new one repeats.
 *
 * A duplicate has the same email address (ignoring case) and the same
 * meetings — in either order for a paired registration. Paired registrations
 * only match paired ones and single only single, and a cancelled
 * registration never matches. If several match, the most recently updated
 * wins, then the most recently created.
 */
class DuplicateFinder
{
    public function __construct(private readonly AnswerStore $store)
    {
    }

    /**
     * @param int|null $excluding The new registration's own post.
     */
    public function find(?int $meetingId, ?int $fellowMeetingId, string $email, ?int $excluding = null): ?Answer
    {
        if (empty($meetingId) || trim($email) === '') {
            return null;
        }

        $candidate = new Answer(0, $meetingId, $fellowMeetingId ?: null, $email, null, null);

        $duplicates = array_values(array_filter(
            $this->store->all(false, $excluding ?: null),
            static fn (Answer $existing): bool => !$existing->isCancelled()
                && strtolower($existing->email ?? '') === strtolower($email)
                && $existing->isPaired() === $candidate->isPaired()
                && $existing->meetingIds() === $candidate->meetingIds()
        ));

        if ($duplicates === []) {
            return null;
        }

        usort($duplicates, [$this, 'compareLatestFirst']);

        return $duplicates[0];
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
