<?php

namespace Confur\Domain;

/**
 * The format of an answer's save times (the `updated` and `completed` fields).
 *
 * Both are ACF date-time picker fields, which store `Y-m-d H:i:s` and format
 * it on the way out. The plugin used to write its own display string —
 * "Monday, 2024-01-01 01:02:03 PM" — straight into them, so anything that
 * sorted by the stored value sorted by weekday name first, and the edit
 * screen's date picker could not read them. Times are now stored in ACF's own
 * format; LEGACY values are read until DateMigration has rewritten them.
 *
 * All times are site-local, as current_time() gives them. Nothing here
 * converts between time zones.
 */
final class SaveTime
{
    /** What the field stores. Sorts correctly as a string. */
    public const STORED = 'Y-m-d H:i:s';

    /** What the plugin used to store. */
    public const LEGACY = 'l, Y-m-d h:i:s A';

    /** How a time is shown: the ACF field's return format, so every screen agrees. */
    public const DISPLAY = 'l, F j, Y g:i A';

    /**
     * A stored value in STORED form, whichever format it was written in;
     * null when it is empty or cannot be read as either.
     */
    public static function normalise(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach ([self::STORED, self::LEGACY] as $format) {
            $time = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if ($time !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $time->format(self::STORED);
            }
        }

        return null;
    }

    /** A STORED time as it is shown to people. */
    public static function display(string $stored): string
    {
        $time = \DateTimeImmutable::createFromFormat('!' . self::STORED, $stored);

        return $time !== false ? $time->format(self::DISPLAY) : $stored;
    }
}
