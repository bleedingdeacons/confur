<?php

namespace Confur\Services;

/**
 * Loads the admin screens' CSS and JS from assets/admin/.
 *
 * Each screen decides when to load (its own hook check) and calls these. The
 * files used to be PHP strings echoed or added inline on every load; as real
 * files they are cached by the browser, versioned with the plugin, and can be
 * edited and linted as CSS and JS.
 */
final class AdminAssets
{
    private const DIR = 'assets/admin/';

    /**
     * Enqueue assets/admin/{$name}.css.
     *
     * @param non-empty-string $name
     */
    public static function style(string $name): void
    {
        wp_enqueue_style(self::handle($name), CONFUR_PLUGIN_URL . self::DIR . $name . '.css', [], CONFUR_VERSION);
    }

    /**
     * Enqueue assets/admin/{$name}.js in the footer, with the values it needs
     * from PHP (nonces, URLs) passed as a global object rather than baked into
     * the code.
     *
     * @param non-empty-string $name
     * @param list<non-empty-string> $deps Script handles it depends on.
     * @param array<string, mixed> $config Exposed to the script as window[$configName].
     */
    public static function script(string $name, array $deps = [], ?string $configName = null, array $config = []): void
    {
        wp_enqueue_script(self::handle($name), CONFUR_PLUGIN_URL . self::DIR . $name . '.js', $deps, CONFUR_VERSION, true);

        if ($configName !== null) {
            wp_localize_script(self::handle($name), $configName, $config);
        }
    }

    /**
     * @param non-empty-string $name
     * @return non-empty-string
     */
    public static function handle(string $name): string
    {
        return 'confur-admin-' . $name;
    }
}
