<?php

namespace Confur\Storage;

use Confur\Domain\QuestionSet;

/**
 * Stores the question set edited on the Questions admin screen.
 *
 * Saved as ['committees' => [number => question count], 'all_committees' => number].
 * 'all_committees' is null when the set has no questions for all committees.
 * Nothing saved, or something saved that no longer makes a valid set, means
 * QuestionSet::defaults() — a broken option must not stop groups saving answers.
 */
final class QuestionSetStore
{
    public const OPTION_NAME = 'confur_question_set';

    public static function load(): QuestionSet
    {
        $stored = get_option(self::OPTION_NAME, false);

        if (!is_array($stored)) {
            return QuestionSet::defaults();
        }

        try {
            return self::fromStored($stored);
        } catch (\InvalidArgumentException $e) {
            error_log('QuestionSetStore::load - Saved question set is invalid, using the default: ' . $e->getMessage());
            return QuestionSet::defaults();
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

    /** Go back to QuestionSet::defaults(). */
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
            // Whole numbers only: casting "2.7" to 2 would quietly change
            // which answers groups must give, where a broken option should
            // fall back to the default instead.
            if (!self::isWholeNumber($committee) || !self::isWholeNumber($count)) {
                throw new \InvalidArgumentException('Committee numbers and counts must be whole numbers.');
            }

            $counts[(int) $committee] = (int) $count;
        }

        $all = $stored['all_committees'] ?? null;

        if ($all !== null && !self::isWholeNumber($all)) {
            throw new \InvalidArgumentException('The All Committees choice must be a whole number.');
        }

        return new QuestionSet($counts, $all !== null ? (int) $all : null);
    }

    /** An int, or a string of digits (options can come back as strings). */
    private static function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }
}
