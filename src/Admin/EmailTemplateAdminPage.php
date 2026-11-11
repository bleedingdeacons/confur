<?php

namespace Confur\Admin;

use Confur\Services\AdminAssets;

/**
 * Admin page for managing Email Templates
 */
class EmailTemplateAdminPage
{
    // Option name for storing email template settings
    private const OPTION_NAME = 'confur_email_templates';

    // Default templates - these mirror the HTML files in /emails directory
    private const DEFAULT_TEMPLATES = [
            'RegistrationConfirmation' => [
                    'name' => 'Registration Confirmation',
                    'subject' => 'Registration Successful',
                    'description' => 'Sent when a user successfully registers. Available placeholders: {{MeetingName}}, {{Url}}, {{AllocationNotice}}',
                    'placeholders' => ['{{MeetingName}}', '{{Url}}', '{{AllocationNotice}}'],
            ],
            'AnswersComplete' => [
                    'name' => 'Answers Complete',
                    'subject' => 'All Questions Completed :)',
                    'description' => 'Sent when a user completes all their answers. Available placeholders: {{MeetingName}}',
                    'placeholders' => ['{{MeetingName}}'],
            ],
            'RegistrationBlocked' => [
                    'name' => 'Registration Blocked',
                    'subject' => 'Registration Could Not Be Completed',
                    'description' => 'Sent when a blocked email address attempts to register. No placeholders available.',
                    'placeholders' => [],
            ],
    ];

    /**
     * Initialize the admin page
     */
    public function init(): void
    {
        // Only load in admin area
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_post_confur_update_email_templates', [$this, 'handleFormSubmission']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    /**
     * Add admin menu item as submenu under Confur
     */
    public function addAdminMenu(): void
    {
        add_submenu_page(
            'confur',                           // Parent slug (Confur menu)
            'Email Templates',                   // Page title
            'Email Templates',                   // Menu title
            'manage_options',                    // Capability (admin only)
            'confur-email-templates',            // Menu slug
            [$this, 'renderAdminPage']          // Callback
        );
    }

    /**
     * Enqueue admin styles and scripts
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueueAdminAssets($hook): void
    {
        // Only load on our admin page
        if ($hook !== 'questions-for-conference_page_confur-email-templates') {
            return;
        }

        // Enqueue WordPress editor
        wp_enqueue_editor();

        AdminAssets::style('email-templates');
    }

    /**
     * Get all email templates from options, merged with defaults
     *
     * @return array<string, array<string, mixed>> Templates with subject and body
     */
    public static function getAll(): array
    {
        $saved = get_option(self::OPTION_NAME, []);
        $templates = [];

        foreach (self::DEFAULT_TEMPLATES as $key => $defaults) {
            $templates[$key] = [
                    'name' => $defaults['name'],
                    'subject' => $saved[$key]['subject'] ?? $defaults['subject'],
                    'body' => $saved[$key]['body'] ?? self::getDefaultBody($key),
                    'description' => $defaults['description'],
                    'placeholders' => $defaults['placeholders'],
            ];
        }

        return $templates;
    }

    /**
     * Get a single template by key
     *
     * @param string $key Template key (e.g., 'RegistrationConfirmation')
     * @return array<string, mixed>|null Template data or null if not found
     */
    public static function get(string $key): ?array
    {
        $templates = self::getAll();
        return $templates[$key] ?? null;
    }

    /**
     * Get template subject
     *
     * @param string $key Template key
     * @return string Subject line
     */
    public static function getSubject(string $key): string
    {
        $template = self::get($key);
        return $template['subject'] ?? '';
    }

    /**
     * Get template body (rendered HTML)
     *
     * @param string $key Template key
     * @return string Body HTML
     */
    public static function getBody(string $key): string
    {
        $template = self::get($key);
        return $template['body'] ?? '';
    }

    /**
     * Get default body from the HTML file
     *
     * @param string $key Template key
     * @return string Default body HTML
     */
    private static function getDefaultBody(string $key): string
    {
        // Sanitize key to prevent path traversal
        $key = sanitize_file_name($key);

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
            return '';
        }

        $templatePath = CONFUR_PLUGIN_DIR . "/emails/{$key}.html";

        if (!file_exists($templatePath)) {
            return '';
        }

        $content = file_get_contents($templatePath);

        // file_exists() above does not guarantee a readable file — permissions
        // or a race can still leave this false.
        if ($content === false) {
            return '';
        }

        // Extract just the body content (between <body> tags)
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }

    /**
     * Update templates
     *
     * @param array<string, array<string, mixed>> $templates Array of template data
     * @return bool Success status
     */
    public static function update(array $templates): bool
    {
        $sanitized = [];

        foreach ($templates as $key => $data) {
            // Only save known templates
            if (!isset(self::DEFAULT_TEMPLATES[$key])) {
                continue;
            }

            $sanitized[$key] = [
                    'subject' => sanitize_text_field($data['subject'] ?? ''),
                    'body' => wp_kses_post($data['body'] ?? ''),
            ];
        }

        return update_option(self::OPTION_NAME, $sanitized);
    }

    /**
     * Reset all templates to defaults
     *
     * @return bool Success status
     */
    public static function resetToDefaults(): bool
    {
        // delete_option returns false if option doesn't exist, but that's fine
        delete_option(self::OPTION_NAME);
        return true;
    }

    /**
     * Reset a single template to default
     *
     * @param string $key Template key
     * @return bool Success status
     */
    public static function resetTemplate(string $key): bool
    {
        if (!isset(self::DEFAULT_TEMPLATES[$key])) {
            return false;
        }

        $saved = get_option(self::OPTION_NAME, []);

        // If the key doesn't exist in saved options, it's already at default
        if (!isset($saved[$key])) {
            return true;
        }

        unset($saved[$key]);

        if (empty($saved)) {
            // delete_option returns false if option doesn't exist, but that's fine
            delete_option(self::OPTION_NAME);
            return true;
        }

        return update_option(self::OPTION_NAME, $saved);
    }

    /**
     * Handle form submission
     */
    public function handleFormSubmission(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Verify nonce
        if (
            !isset($_POST['confur_email_templates_nonce']) ||
            !wp_verify_nonce($_POST['confur_email_templates_nonce'], 'confur_email_templates_action')
        ) {
            wp_die(__('Security check failed.'));
        }

        wp_redirect($this->resolveSubmissionRedirect());
        exit;
    }

    /**
     * Apply whatever the submitted form asked for and say where to go next.
     *
     * Split out of handleFormSubmission() so it can be driven in a test: the
     * caller ends in wp_redirect() followed by a bare exit, which would take
     * the test runner with it. Each branch below previously redirected and
     * exited in place, so returning the URL early is the same control flow;
     * the caller keeps the guards and the single redirect.
     *
     * @return string Redirect URL
     */
    private function resolveSubmissionRedirect(): string
    {
        // Check if reset all button was clicked
        if (isset($_POST['reset_all_defaults'])) {
            if (self::resetToDefaults()) {
                $redirect_url = add_query_arg(
                    ['page' => 'confur-email-templates', 'updated' => 'reset_all'],
                    admin_url('admin.php')
                );
            } else {
                $redirect_url = add_query_arg(
                    ['page' => 'confur-email-templates', 'error' => '1'],
                    admin_url('admin.php')
                );
            }

            return $redirect_url;
        }

        // Check if reset single template
        if (isset($_POST['reset_template'])) {
            $templateKey = sanitize_text_field($_POST['reset_template']);
            if (self::resetTemplate($templateKey)) {
                $redirect_url = add_query_arg(
                    ['page' => 'confur-email-templates', 'updated' => 'reset_single'],
                    admin_url('admin.php')
                );
            } else {
                $redirect_url = add_query_arg(
                    ['page' => 'confur-email-templates', 'error' => '1'],
                    admin_url('admin.php')
                );
            }

            return $redirect_url;
        }

        // Normal save
        $templates = [];
        foreach (array_keys(self::DEFAULT_TEMPLATES) as $key) {
            $templates[$key] = [
                    'subject' => $_POST["template_{$key}_subject"] ?? '',
                    'body' => $_POST["template_{$key}_body"] ?? '',
            ];
        }

        if (self::update($templates)) {
            $redirect_url = add_query_arg(
                ['page' => 'confur-email-templates', 'updated' => '1'],
                admin_url('admin.php')
            );
        } else {
            $redirect_url = add_query_arg(
                ['page' => 'confur-email-templates', 'error' => '1'],
                admin_url('admin.php')
            );
        }

        return $redirect_url;
    }

    /**
     * Render the admin page
     */
    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $templates = self::getAll();

        Template::render('admin/email-templates', [
            'templates' => $templates,
            'updated' => isset($_GET['updated']) ? sanitize_key(wp_unslash($_GET['updated'])) : '',
            'error' => isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '',
        ]);
    }
}
