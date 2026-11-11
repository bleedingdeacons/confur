<?php

namespace Confur\Domain;

/**
 * Names one answer: committee N, question M, stored in the ACF field "cN_aM".
 *
 * Everything that used to split that string by hand — explode('_'),
 * str_replace('c', ...), its own regex — goes through parse() instead.
 */
final readonly class AnswerKey
{
    private const PATTERN = '/^c(\d+)_a(\d+)$/';

    public function __construct(
        public int $committee,
        public int $question,
    ) {
    }

    /** The key for an ACF field name, or null if it is not an answer field. */
    public static function parse(string $fieldName): ?self
    {
        if (preg_match(self::PATTERN, $fieldName, $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2]);
    }

    /** The ACF field name, e.g. "c1_a2". */
    public function fieldName(): string
    {
        return 'c' . $this->committee . '_a' . $this->question;
    }

    public function equals(self $other): bool
    {
        return $this->committee === $other->committee && $this->question === $other->question;
    }
}
