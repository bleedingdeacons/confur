<?php

declare(strict_types=1);

/**
 * Confur unit-test harness.
 *
 * Confur is standalone (no WordPress in the test run), so its classes reach for
 * WordPress/ACF functions that don't exist. This file supplies controllable
 * stubs for the surface the *non-admin* classes touch, backed by process-global
 * state ($GLOBALS['confur_*']) the tests set up and assert against, plus the
 * couple of WordPress classes and plugin constants the code references.
 *
 * PHP falls back to the global namespace for unqualified function calls, so
 * these global definitions serve every Confur namespace without per-namespace
 * shims. Not faithful implementations — just enough to drive the logic.
 */

// ── Constants ────────────────────────────────────────────────────────────────
if (!defined('CONFUR_PLUGIN_DIR')) {
    define('CONFUR_PLUGIN_DIR', dirname(__DIR__));
}
if (!defined('CONFUR_PLUGIN_URL')) {
    define('CONFUR_PLUGIN_URL', 'http://example.test/wp-content/plugins/confur/');
}
if (!defined('CONFUR_VERSION')) {
    define('CONFUR_VERSION', '9.9.9-test');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

// ── WordPress classes ────────────────────────────────────────────────────────
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string,mixed> */
        public array $data;

        public function __construct(public string $code = '', public string $message = '', array $data = [])
        {
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public mixed $data = null, public int $status = 200)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

/**
 * Thrown by the patched wp_send_json_* stubs so a test can inspect the payload.
 * Extends \Error (not \Exception) so the production `catch (\Exception)` blocks
 * ignore it — mirroring the real wp_send_json_*'s terminal exit() rather than
 * being swallowed and re-reported as a 500.
 */
if (!class_exists('ConfurJsonResponse')) {
    class ConfurJsonResponse extends \Error
    {
        public function __construct(public bool $success, public mixed $payload = null, public ?int $statusCode = null)
        {
            parent::__construct('wp_send_json');
        }
    }
}

// ── ACF ──────────────────────────────────────────────────────────────────────
if (!function_exists('update_field')) {
    function update_field($selector, $value, $post_id = false)
    {
        $pid = ($post_id === false || $post_id === null) ? 0 : $post_id;
        $GLOBALS['confur_fields'][$pid][$selector] = $value;
        return $GLOBALS['confur_update_field_result'] ?? true;
    }
}
if (!function_exists('acf_save_post')) {
    function acf_save_post($post_id = false)
    {
        if ($GLOBALS['confur_acf_save_throws'] ?? false) {
            throw new \RuntimeException('acf_save_post failed');
        }
        return true;
    }
}
if (!function_exists('acf_get_field')) {
    function acf_get_field($selector)
    {
        return $GLOBALS['confur_acf_fieldobj'][$selector] ?? false;
    }
}
if (!function_exists('get_fields')) {
    function get_fields($post_id = false)
    {
        return $GLOBALS['confur_allfields'][$post_id] ?? [];
    }
}
if (!function_exists('clean_post_cache')) {
    function clean_post_cache($post)
    {
    }
}
if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush()
    {
        return true;
    }
}

// ── Posts ────────────────────────────────────────────────────────────────────
if (!function_exists('get_posts')) {
    function get_posts($args = [])
    {
        $posts = $GLOBALS['confur_posts'] ?? [];

        if (!empty($args['post_type'])) {
            $type = $args['post_type'];
            $posts = array_values(array_filter($posts, static fn ($p) => ($p->post_type ?? 'answer') === $type));
        }

        if (!empty($args['post__not_in'])) {
            $exclude = $args['post__not_in'];
            $posts = array_values(array_filter($posts, static fn ($p) => !in_array($p->ID, $exclude, false)));
        }

        if (($args['fields'] ?? '') === 'ids') {
            return array_map(static fn ($p) => $p->ID, $posts);
        }

        return $posts;
    }
}
if (!function_exists('get_post')) {
    function get_post($id = null)
    {
        foreach ($GLOBALS['confur_posts'] ?? [] as $p) {
            if ((int) $p->ID === (int) $id) {
                return $p;
            }
        }
        return $GLOBALS['confur_postdata'][$id] ?? null;
    }
}
if (!function_exists('get_post_status')) {
    function get_post_status($id = null)
    {
        return $GLOBALS['confur_poststatus'][$id] ?? 'publish';
    }
}
if (!function_exists('get_post_custom')) {
    function get_post_custom($id = 0)
    {
        return $GLOBALS['confur_postcustom'][$id] ?? [];
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($id = 0)
    {
        return $GLOBALS['confur_titles'][$id] ?? ('Title ' . $id);
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($id = 0)
    {
        return $GLOBALS['confur_permalinks'][$id] ?? ('http://example.test/?p=' . $id);
    }
}
if (!function_exists('get_page_by_path')) {
    function get_page_by_path($path, $output = OBJECT, $post_type = 'post')
    {
        return $GLOBALS['confur_page_by_path'] ?? null;
    }
}
if (!function_exists('url_to_postid')) {
    function url_to_postid($url)
    {
        return $GLOBALS['confur_url_to_postid'] ?? 0;
    }
}
if (!function_exists('wp_publish_post')) {
    function wp_publish_post($post)
    {
        return true;
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($postarr = [], $wp_error = false)
    {
        return $postarr['ID'] ?? 1;
    }
}
if (!function_exists('wp_delete_post')) {
    function wp_delete_post($id, $force = false)
    {
        $GLOBALS['confur_deleted_posts'][] = $id;
        return true;
    }
}
if (!function_exists('wp_trash_post')) {
    function wp_trash_post($id)
    {
        $GLOBALS['confur_trashed_posts'][] = $id;
        return true;
    }
}

// ── Options ──────────────────────────────────────────────────────────────────
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['confur_options'] ?? []) ? $GLOBALS['confur_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['confur_options'][$name] = $value;
        return $GLOBALS['confur_update_option_result'] ?? true;
    }
}
if (!function_exists('add_option')) {
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
    {
        if (array_key_exists($name, $GLOBALS['confur_options'] ?? [])) {
            return false;
        }
        $GLOBALS['confur_options'][$name] = $value;
        return true;
    }
}

// ── Sanitisers / misc ────────────────────────────────────────────────────────
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str)
    {
        return trim(strip_tags((string) $str));
    }
}
if (!function_exists('sanitize_url')) {
    function sanitize_url($url)
    {
        return filter_var((string) $url, FILTER_SANITIZE_URL);
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return $url;
    }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($name)
    {
        return preg_replace('/[^A-Za-z0-9_\-.]/', '', (string) $name);
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        $title = strtolower(trim((string) $title));
        return preg_replace('/[^a-z0-9]+/', '-', $title);
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        return date($type === 'timestamp' ? 'U' : $type);
    }
}
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = [])
    {
        $GLOBALS['confur_sent_mail'][] = compact('to', 'subject', 'message', 'headers');
        return $GLOBALS['confur_wp_mail_result'] ?? true;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

// ── Enqueue / admin / shortcodes ─────────────────────────────────────────────
if (!function_exists('is_singular')) {
    function is_singular($post_types = '')
    {
        return $GLOBALS['confur_is_singular'] ?? false;
    }
}
if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script($handle, $data, $position = 'after')
    {
        return true;
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin')
    {
        return 'http://example.test/wp-admin/' . $path;
    }
}
if (!function_exists('add_menu_page')) {
    function add_menu_page(...$args)
    {
        return 'toplevel_page_confur';
    }
}
if (!function_exists('add_submenu_page')) {
    function add_submenu_page(...$args)
    {
        return 'confur_page_sub';
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback)
    {
        $GLOBALS['confur_registered_shortcodes'][] = $tag;
        return true;
    }
}
if (!function_exists('shortcode_exists')) {
    function shortcode_exists($tag)
    {
        return $GLOBALS['confur_shortcode_exists'][$tag] ?? false;
    }
}
if (!function_exists('remove_shortcode')) {
    function remove_shortcode($tag)
    {
        if ($GLOBALS['confur_remove_shortcode_throws'] ?? false) {
            throw new \RuntimeException('remove_shortcode failed');
        }
        return true;
    }
}
if (!function_exists('shortcode_atts')) {
    function shortcode_atts($defaults, $atts, $shortcode = '')
    {
        $atts = (array) $atts;
        $out = [];
        foreach ($defaults as $name => $default) {
            $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
        }
        return $out;
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false)
    {
        $GLOBALS['confur_rest_routes'][] = $route;
        $GLOBALS['confur_rest_args'][$route] = $args;
        return true;
    }
}
if (!function_exists('get_role')) {
    function get_role($role)
    {
        return $GLOBALS['confur_roles'][$role] ?? null;
    }
}
if (!function_exists('wp_timezone')) {
    function wp_timezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
if (!function_exists('add_menu_page')) {
    function add_menu_page(...$args)
    {
        return 'menu';
    }
}
