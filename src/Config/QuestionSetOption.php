<?php

namespace Confur\Config;

use Confur\Domain\QuestionSet;

/**
 * Stores the question set edited on the Questions admin screen.
 *
 * Saved as ['committees' => [number => question count], 'all_committees' => number].
 * Nothing saved, or something saved that no longer makes a valid set, means
 * QuestionSet::DEFAULT — a broken option must not stop groups saving answers.
 */
final class QuestionSetOption
{
    public const OPTION_NAME = 'confur_question_set';

    public static function load(): QuestionSet
    {
        $stored = get_option(self::OPTION_NAME, false);

        if (!is_array($stored)) {
            return new QuestionSet(QuestionSet::DEFAULT);
        }

        try {
            return self::fromStored($stored);
        } catch (\InvalidArgumentException $e) {
            error_log('QuestionSetOption::load - Saved question set is invalid, using the default: ' . $e->getMessage());
            return new QuestionSet(QuestionSet::DEFAULT);
        }
    }

    public static function save(QuestionSet $questions): bool
    {
        $value = [
            'committees' => $questions->questionCounts(),
            'all_committees' => $questions->allCommittees(),
        ];

        // update_option() returns false when the value is unchanged; that is
        // still a successful save from the administrator's point of view.
        return get_option(self::OPTION_NAME) === $value || update_option(self::OPTION_NAME, $value);
    }

    /** Go back to QuestionSet::DEFAULT. */
    public static function reset(): bool
    {
        return get_option(self::OPTION_NAME, false) === false || delete_option(self::OPTION_NAME);
    }

    /** Whether an administrator has saved a set (as opposed to the default being in force). */
    public static function isCustomised(): bool
    {
        return get_option(self::OPTION_NAME, false) !== false;
    }

    /**
     * @param array<mixed> $stored
     * @throws \InvalidArgumentException When the stored value is not a valid set.
     */
    private static function fromStored(array $stored): QuestionSet
    {
        $committees = $stored['committees'] ?? null;

        if (!is_array($committees)) {
            throw new \InvalidArgumentException('No committees saved.');
        }

        $counts = [];

        foreach ($committees as $committee => $count) {
            if (!is_numeric($committee) || !is_numeric($count)) {
                throw new \InvalidArgumentException('Committee numbers and counts must be numbers.');
            }

            $counts[(int) $committee] = (int) $count;
        }

        $all = $stored['all_committees'] ?? null;

        return new QuestionSet($counts, is_numeric($all) ? (int) $all : null);
    }
}
