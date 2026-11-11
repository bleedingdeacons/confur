<?php

namespace Confur\Domain;

/**
 * Where a group's answers are in their lifecycle.
 *
 * The backing values are what the ACF "state" select stores, so a case can be
 * written straight to the field and read back with fromStored().
 */
enum AnswerState: string
{
    case Draft = 'Draft';
    case Complete = 'Complete';
    case Cancelled = 'Cancelled';

    /**
     * Read a stored value. Matching ignores case because older rows and the
     * admin list's own badge mapping use lower-case spellings. Anything empty
     * or unrecognised is null — callers decide what that means (most treat it
     * as a draft, see orDraft()).
     */
    public static function fromStored(mixed $value): ?self
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $value) {
                return $case;
            }
        }

        return null;
    }

    /** A post with no recognisable state has not been completed: a draft. */
    public static function orDraft(mixed $value): self
    {
        return self::fromStored($value) ?? self::Draft;
    }

    /** States a group may choose when it saves. Cancelled is admin-only. */
    public function isSubmittable(): bool
    {
        return $this !== self::Cancelled;
    }
}
