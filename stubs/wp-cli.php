<?php

/**
 * The WP-CLI symbols this plugin uses, for static analysis and tests only.
 *
 * Not php-stubs/wp-cli-stubs: every published version requires
 * php-stubs/wordpress-stubs ^6 or older, which this plugin is past, so
 * Composer refuses the combination. Tamar and Fellowship solve it the same
 * way.
 *
 * Listed in phpstan.neon.dist under scanFiles and loaded by the test
 * bootstrap, never at runtime: WP-CLI defines the real ones, and Plugin only
 * registers commands behind defined('WP_CLI'). Excluded from the production
 * zip by build.php.
 *
 * Keep it to what the plugin calls. Under test the output methods record
 * what was printed in WP_CLI::$printed, so a command's output can be checked.
 */

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            /** @var list<array{0: string, 1: string}> Test-only: [method, message]. */
            public static array $printed = [];

            /**
             * @param string $name The command, e.g. "confur migrate-dates".
             * @param callable|object $callable
             * @param array<string, mixed> $args
             */
            public static function add_command($name, $callable, $args = []): bool
            {
                return true;
            }

            /** @param string $message */
            public static function log($message): void
            {
                self::$printed[] = ['log', $message];
            }

            /** @param string $message */
            public static function success($message): void
            {
                self::$printed[] = ['success', $message];
            }

            /** @param string $message */
            public static function warning($message): void
            {
                self::$printed[] = ['warning', $message];
            }
        }
    }
}
