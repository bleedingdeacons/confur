<?php

namespace Confur\Cli;

use Confur\Storage\DateMigration;
use WP_CLI;

/**
 * Rewrite answers' saved times into the date-time picker's own format.
 *
 * Old saves stored "Monday, 2024-01-01 01:02:03 PM"; new ones store
 * "2024-01-01 13:02:03". Both display the same, but only the new one sorts.
 * Safe to run more than once. Back the database up first, and run with
 * --dry-run to see the changes before making them.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Report what would change without writing anything.
 *
 * ## EXAMPLES
 *
 *     wp confur migrate-dates --dry-run
 *     wp confur migrate-dates
 */
final class MigrateDatesCommand
{
    public function __construct(private readonly DateMigration $migration = new DateMigration())
    {
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $dryRun = isset($assocArgs['dry-run']);
        $report = $this->migration->run($dryRun);

        foreach ($report['converted'] as $change) {
            WP_CLI::log(sprintf('post %d %s: "%s" -> "%s"', $change['post'], $change['field'], $change['from'], $change['to']));
        }

        foreach ($report['unreadable'] as $bad) {
            WP_CLI::warning(sprintf('post %d %s: "%s" is not a time this can read; left as it is', $bad['post'], $bad['field'], $bad['value']));
        }

        foreach ($report['changed'] as $moved) {
            WP_CLI::log(sprintf('post %d %s: saved again while this ran; left as the group wrote it', $moved['post'], $moved['field']));
        }

        foreach ($report['failed'] as $failed) {
            WP_CLI::warning(sprintf('post %d %s: could not be written; still "%s". Run again to retry.', $failed['post'], $failed['field'], $failed['value']));
        }

        $summary = sprintf(
            '%s%d answer posts: %d %s, %d already in the new format, %d empty, %d unreadable%s.',
            $dryRun ? 'Dry run, nothing written. ' : '',
            $report['posts'],
            count($report['converted']),
            $dryRun ? 'would be converted' : 'converted',
            $report['current'],
            $report['empty'],
            count($report['unreadable']),
            $dryRun ? '' : sprintf(', %d saved again meanwhile, %d failed', count($report['changed']), count($report['failed']))
        );

        // A failed write leaves old values behind: not a success.
        if ($report['failed'] !== []) {
            WP_CLI::warning($summary);
            return;
        }

        WP_CLI::success($summary);
    }
}
