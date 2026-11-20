<?php

declare(strict_types=1);

namespace Confur\Logger;

/**
 * Logs through Sentinel's shared logger (the mu-plugin Sentinel deploys),
 * on one channel, `confur`, so every line names the plugin on Sentinel's log
 * screen and Monitor's error alerts can be scoped to it.
 *
 * A local copy of the suite's HasLogger (Amber, Fellowship, Beacon) with two
 * deliberate differences:
 *
 * - **It falls back to error_log() when Sentinel is absent**, rather than
 *   dropping the message. Confur does not require Sentinel, and before this
 *   trait every one of these messages went to the PHP error log; a site
 *   without Sentinel keeps them there.
 * - **The channel is not cached.** wp_log() already memoises channels, so the
 *   cache saved nothing, and without it a test can switch the logger off
 *   between cases.
 *
 * Messages and context are written to Sentinel's table and shown on its
 * admin screen, and anything at `error` or above can be pushed to Monitor.
 * Keep members' answers and email addresses out of both, as before: name
 * posts by ID and settings by key.
 */
trait HasLogger
{
    public const LOG_CHANNEL = 'confur';

    public static function log(): ?\Sentinel_Log_Channel
    {
        return function_exists('wp_log') ? wp_log(self::LOG_CHANNEL) : null;
    }

    /** @param array<string, mixed> $context */
    public static function logCritical(string $message, array $context = []): void
    {
        self::write('critical', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function logError(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function logWarning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function logNotice(string $message, array $context = []): void
    {
        self::write('notice', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function logInfo(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function logDebug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /**
     * The usual context for a caught exception: its message and class, plus
     * the stack trace when asked for.
     *
     * @return array<string, string>
     */
    public static function exceptionContext(\Throwable $e, bool $withTrace = false): array
    {
        $context = ['error' => $e->getMessage(), 'exception' => $e::class];

        if ($withTrace) {
            $context['trace'] = $e->getTraceAsString();
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $channel = self::log();

        if ($channel !== null) {
            match ($level) {
                'critical' => $channel->critical($message, $context),
                'error' => $channel->error($message, $context),
                'warning' => $channel->warning($message, $context),
                'notice' => $channel->notice($message, $context),
                'info' => $channel->info($message, $context),
                default => $channel->debug($message, $context),
            };
            return;
        }

        $line = sprintf('[%s] %s: %s', self::LOG_CHANNEL, strtoupper($level), $message);

        if ($context !== []) {
            $line .= ' ' . (json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        }

        error_log($line);
    }
}
