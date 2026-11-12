<?php

namespace Confur\Application;

use Confur\Domain\Answer;
use Confur\Storage\AnswerStore;

/**
 * Finds an existing registration that a new one repeats.
 *
 * A duplicate has the same email address (ignoring case) and the same
 * groups — in either order for a paired registration. Paired registrations
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
    public function find(?int $groupId, ?int $fellowGroupId, string $email, ?int $excluding = null): ?Answer
    {
        if (empty($groupId) || trim($email) === '') {
            return null;
        }

        $key = self::matchKey(new Answer(0, $groupId, $fellowGroupId ?: null, $email, null, null));

        $duplicates = array_values(array_filter(
            $this->store->all(false, $excluding ?: null),
            static fn (Answer $existing): bool => self::matchKey($existing) === $key
        ));

        if ($duplicates === []) {
            return null;
        }

        usort($duplicates, [$this, 'compareLatestFirst']);

        return $duplicates[0];
    }

    /**
     * Every set of two or more registrations that duplicate each other,
     * by the same rule find() uses. For the Status screen's warning.
     *
     * @param list<Answer> $answers
     * @return list<list<Answer>> Each group in post order; groups in order of first appearance.
     */
    public function groups(array $answers): array
    {
        $byKey = [];

        foreach ($answers as $answer) {
            $key = self::matchKey($answer);

            if ($key !== null) {
                $byKey[$key][] = $answer;
            }
        }

        return array_values(array_filter($byKey, static fn (array $group): bool => count($group) > 1));
    }

    /**
     * What makes two registrations the same: the email (ignoring case and
     * surrounding space), paired or single, and the sorted groups. Null for
     * a registration that cannot duplicate anything — cancelled, or missing
     * its group or email.
     */
    private static function matchKey(Answer $answer): ?string
    {
        $email = strtolower(trim($answer->email ?? ''));

        if ($answer->isCancelled() || $answer->groupId === null || $email === '') {
            return null;
        }

        return $email . '|' . ($answer->isPaired() ? 'paired' : 'single') . '|' . implode('-', $answer->groupIds());
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
