<?php

declare(strict_types=1);

namespace Confur\Journal;

/**
 * Records journal entries through Sentinel's shared journal (wp_journal(),
 * provided by the mu-plugin Sentinel deploys), on the one `confur` channel,
 * so Sentinel → Journal lists every Confur entry together.
 *
 * A local copy of Sentinel's HasJournal, kept here so it resolves through
 * Confur's own autoloader with no class-load dependency on Sentinel. The
 * difference is the fixed channel, matching {@see \Confur\Logger\HasLogger}.
 *
 * Unlike a log line, an entry is a record: it is kept in its own table and
 * never pruned. That is what makes it the place for a submission's safety
 * copy, members' answers included — which still do not belong in the log.
 */
trait HasJournal
{
    public const JOURNAL_CHANNEL = 'confur';

    /**
     * Record a journal entry.
     *
     * @param string|array<mixed>|object $entry JSON text, or a PHP object or array to encode as JSON.
     *
     * @return string|null The entry's uuid, or null when it was not recorded: Sentinel
     *                     is not deployed, the entry was not valid JSON or could not be
     *                     encoded, or the write failed. Sentinel logs the reason on the
     *                     same channel.
     */
    public static function journal(string|array|object $entry): ?string
    {
        if (!function_exists('wp_journal')) {
            return null;
        }

        return wp_journal(self::JOURNAL_CHANNEL, $entry);
    }
}
