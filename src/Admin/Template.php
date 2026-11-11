<?php

namespace Confur\Admin;

/**
 * Renders a view from templates/.
 *
 * Each admin screen splits into a controller (permission check, gather the
 * data) and a template (markup only). Templates receive exactly the
 * variables passed here and nothing else — no $this — so what a screen shows
 * is decided before any HTML is written, and a template can be read as HTML.
 */
final class Template
{
    /**
     * Echo a template.
     *
     * @param string $name Path under templates/, without .php (e.g. "admin/status").
     * @param array<string, mixed> $vars Variables the template can use.
     */
    public static function render(string $name, array $vars = []): void
    {
        if (preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid template name: $name");
        }

        $file = CONFUR_PLUGIN_DIR . '/templates/' . $name . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: $name");
        }

        (static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        })($file, $vars);
    }

    /**
     * A template's output as a string, for responses that return HTML
     * rather than print it.
     *
     * @param array<string, mixed> $vars
     */
    public static function capture(string $name, array $vars = []): string
    {
        ob_start();

        try {
            self::render($name, $vars);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
