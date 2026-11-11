<?php

namespace Confur\Domain;

use Confur\Storage\QuestionSetStore;

/**
 * The questions a conference cycle asks: which committees there are and how
 * many questions each has.
 *
 * This is the one place that knowledge lives. The progress table, the results
 * page, the allocation wording and the "is every question answered?" check
 * all read it, where each used to carry its own "1 to 6, then 7" loop.
 *
 * The last committee is special: it holds questions not owned by any single
 * committee, and is shown as "All Committees" rather than "Committee 7".
 *
 * Administrators edit it on the Questions screen (QuestionSetStore stores
 * it). DEFAULT is what the bundled ACF field group in setup/Acf_Answers.json
 * defines, and a test checks the two agree. A question added beyond that
 * still saves and reports — AnswerStore reads the set's field names directly
 * — but has no ACF field, so it is not editable on the answer post's own
 * edit screen.
 */
final class QuestionSet
{
    /**
     * The current cycle: committee number => number of questions.
     *
     * @var array<int, int>
     */
    public const DEFAULT = [
        1 => 3,
        2 => 2,
        3 => 2,
        4 => 2,
        5 => 2,
        6 => 2,
        7 => 1,
    ];

    /** @var array<int, int> */
    private array $questionCounts;

    private int $allCommittees;

    /**
     * @param array<int, int> $questionCounts Committee number => question count.
     * @param int|null $allCommittees The "All Committees" committee. Defaults
     *                                to the highest-numbered one.
     */
    public function __construct(array $questionCounts, ?int $allCommittees = null)
    {
        if ($questionCounts === []) {
            throw new \InvalidArgumentException('A question set needs at least one committee.');
        }

        foreach ($questionCounts as $committee => $count) {
            if (!is_int($committee) || $committee < 1 || !is_int($count) || $count < 1) {
                throw new \InvalidArgumentException(
                    'Committee numbers and question counts must be positive integers.'
                );
            }
        }

        ksort($questionCounts);

        $allCommittees ??= (int) array_key_last($questionCounts);

        if (!isset($questionCounts[$allCommittees])) {
            throw new \InvalidArgumentException("Committee $allCommittees is not in the question set.");
        }

        $this->questionCounts = $questionCounts;
        $this->allCommittees = $allCommittees;
    }

    /**
     * The set in force for this request: the one saved on the Questions admin
     * screen, or DEFAULT if none has been saved. The confur_question_set
     * filter sees the counts last, for code that needs to override the admin.
     */
    public static function current(): self
    {
        $saved = QuestionSetStore::load();

        /** @var mixed $counts */
        $counts = apply_filters('confur_question_set', $saved->questionCounts());

        if ($counts === $saved->questionCounts()) {
            return $saved;
        }

        if (!is_array($counts)) {
            return $saved;
        }

        try {
            // Keep the saved All Committees choice if the filter left it in.
            /** @var array<int, int> $counts */
            return new self($counts, isset($counts[$saved->allCommittees()]) ? $saved->allCommittees() : null);
        } catch (\InvalidArgumentException $e) {
            error_log('QuestionSet::current - confur_question_set filter returned an invalid set: ' . $e->getMessage());
            return $saved;
        }
    }

    /** @return array<int, int> Committee number => question count, ascending. */
    public function questionCounts(): array
    {
        return $this->questionCounts;
    }

    /** The committee shown as "All Committees". */
    public function allCommittees(): int
    {
        return $this->allCommittees;
    }

    public function equals(self $other): bool
    {
        return $this->questionCounts === $other->questionCounts
            && $this->allCommittees === $other->allCommittees;
    }

    /** @return list<int> Committee numbers, ascending. */
    public function committees(): array
    {
        return array_keys($this->questionCounts);
    }

    public function hasCommittee(int $committee): bool
    {
        return isset($this->questionCounts[$committee]);
    }

    /** Number of questions a committee has; 0 for one not in the set. */
    public function questionCount(int $committee): int
    {
        return $this->questionCounts[$committee] ?? 0;
    }

    /** Whether this is the "All Committees" committee. */
    public function isAllCommittees(int $committee): bool
    {
        return $committee === $this->allCommittees;
    }

    /** "Committee N", or "All Committees" for the shared one. */
    public function committeeLabel(int $committee): string
    {
        return $this->isAllCommittees($committee) ? 'All Committees' : 'Committee ' . $committee;
    }

    /** @return list<AnswerKey> Every answer the set asks for, in order. */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->questionCounts as $committee => $count) {
            for ($question = 1; $question <= $count; $question++) {
                $keys[] = new AnswerKey($committee, $question);
            }
        }

        return $keys;
    }

    /** @return list<string> Every answer field name, e.g. ["c1_a1", ...]. */
    public function fieldNames(): array
    {
        return array_map(static fn (AnswerKey $k): string => $k->fieldName(), $this->keys());
    }

    public function contains(AnswerKey $key): bool
    {
        return $key->question >= 1 && $key->question <= $this->questionCount($key->committee);
    }

    /**
     * Keys the set asks for that have no answer in $answers.
     *
     * @param array<string, string> $answers Field name => answer text.
     * @return list<AnswerKey>
     */
    public function unanswered(array $answers): array
    {
        return array_values(array_filter(
            $this->keys(),
            static fn (AnswerKey $k): bool => trim($answers[$k->fieldName()] ?? '') === ''
        ));
    }

    /**
     * Whether every question in the set has an answer.
     *
     * @param array<string, string> $answers Field name => answer text.
     */
    public function isComplete(array $answers): bool
    {
        return $this->unanswered($answers) === [];
    }
}
