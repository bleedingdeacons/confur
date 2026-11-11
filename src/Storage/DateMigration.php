<?php

namespace Confur\Storage;

use Confur\Config\Constants;
use Confur\Domain\SaveTime;

/**
 * Rewrites answers' `updated` and `completed` values from the old display
 * string ("Monday, 2024-01-01 01:02:03 PM") to the date-time picker's own
 * `Y-m-d H:i:s` (see SaveTime).
 *
 * One-off and safe to repeat: a value already in the new format is left
 * alone, and one that cannot be read as either is reported, never changed.
 * It works on the raw post meta, not get_field(), which would hand back the
 * field's formatted value rather than what is stored.
 *
 * Run with `wp confur migrate-dates --dry-run` first. Nothing reads the old
 * format wrongly in the meantime — SaveTime reads both — so the only thing
 * waiting on this is sorting by those fields.
 */
final class DateMigration
{
    /** Set once a real (not dry) run has finished. */
    public const DONE_OPTION = 'confur_dates_migrated';

    private const FIELDS = [Constants::UPDATED_FIELD, Constants::COMPLETION_FIELD];

    /** Every status an answer post can have, the trash included. */
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future', 'trash'];

    /**
     * @return array{
     *     posts: int,
     *     converted: list<array{post: int, field: string, from: string, to: string}>,
     *     current: int,
     *     empty: int,
     *     unreadable: list<array{post: int, field: string, value: string}>
     * }
     */
    public function run(bool $dryRun = true): array
    {
        $ids = array_values(array_filter(array_map('intval', get_posts([
            'post_type' => Constants::ANSWER_CUSTOM_TYPE,
            'post_status' => self::STATUSES,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]))));

        $report = ['posts' => count($ids), 'converted' => [], 'current' => 0, 'empty' => 0, 'unreadable' => []];

        foreach ($ids as $postId) {
            foreach (self::FIELDS as $field) {
                $raw = get_post_meta($postId, $field, true);
                $value = is_scalar($raw) ? trim((string) $raw) : '';

                if ($value === '') {
                    $report['empty']++;
                    continue;
                }

                $stored = SaveTime::normalise($value);

                if ($stored === null) {
                    $report['unreadable'][] = ['post' => $postId, 'field' => $field, 'value' => $value];
                    continue;
                }

                if ($stored === $value) {
                    $report['current']++;
                    continue;
                }

                if (!$dryRun) {
                    update_post_meta($postId, $field, $stored);
                }

                $report['converted'][] = ['post' => $postId, 'field' => $field, 'from' => $value, 'to' => $stored];
            }
        }

        if (!$dryRun) {
            update_option(self::DONE_OPTION, current_time('mysql'), false);
        }

        return $report;
    }
}
