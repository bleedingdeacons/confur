<?php

namespace Confur\Admin;

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
            'description' => 'Sent when a user successfully registers. Available placeholders: {{MeetingName}}, {{Url}}',
            'placeholders' => ['{{MeetingName}}', '{{Url}}'],
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
     */
    public function enqueueAdminAssets($hook): void
    {
        // Only load on our admin page
        if ($hook !== 'questions-for-conference_page_confur-email-templates') {
            return;
        }

        // Enqueue WordPress editor
        wp_enqueue_editor();

        // Inline CSS for the admin page
        $custom_css = "
            .confur-templates-container {
                max-width: 1000px;
                margin-top: 20px;
            }
            .confur-template-card {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                border-radius: 4px;
            }
            .confur-template-card h2 {
                margin-top: 0;
                margin-bottom: 10px;
                font-size: 18px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .confur-template-card .template-description {
                color: #646970;
                font-size: 13px;
                margin-bottom: 15px;
                padding: 10px;
                background: #f0f0f1;
                border-radius: 4px;
            }
            .confur-template-card .template-description code {
                background: #e0e0e0;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }
            .confur-template-field {
                margin-bottom: 15px;
            }
            .confur-template-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
            }
            .confur-template-field input[type='text'] {
                width: 100%;
                max-width: 500px;
            }
            .confur-template-field .wp-editor-wrap {
                margin-top: 5px;
            }
            .confur-form-actions {
                margin-top: 20px;
                padding: 20px;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                display: flex;
                gap: 10px;
                border-radius: 4px;
            }
            .confur-notice {
                background: #fff;
                border-left: 4px solid #00a32a;
                padding: 12px;
                margin: 20px 0;
                box-shadow: 0 1px 1px rgba(0,0,0,0.04);
            }
            .confur-notice.error {
                border-left-color: #d63638;
            }
            .confur-notice p {
                margin: 0.5em 0;
                padding: 2px;
            }
            .confur-placeholder-info {
                margin-top: 5px;
                font-size: 12px;
                color: #646970;
            }
            .confur-template-badge {
                font-size: 11px;
                background: #2271b1;
                color: #fff;
                padding: 2px 8px;
                border-radius: 10px;
                font-weight: normal;
            }
            .confur-reset-warning {
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 12px;
                margin: 15px 0;
            }
        ";
        wp_add_inline_style('wp-admin', $custom_css);
    }

    /**
     * Get all email templates from options, merged with defaults
     *
     * @return array Templates with subject and body
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
     * @return array|null Template data or null if not found
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
        
        // Extract just the body content (between <body> tags)
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }

    /**
     * Update templates
     *
     * @param array $templates Array of template data
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
        return delete_option(self::OPTION_NAME);
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
        if (!isset($_POST['confur_email_templates_nonce']) ||
            !wp_verify_nonce($_POST['confur_email_templates_nonce'], 'confur_email_templates_action')) {
            wp_die(__('Security check failed.'));
        }

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
            wp_redirect($redirect_url);
            exit;
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
            wp_redirect($redirect_url);
            exit;
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

        wp_redirect($redirect_url);
        exit;
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

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
                <div class="confur-notice">
                    <p><strong>Email templates updated successfully.</strong></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'reset_all'): ?>
                <div class="confur-notice">
                    <p><strong>All email templates reset to defaults.</strong></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'reset_single'): ?>
                <div class="confur-notice">
                    <p><strong>Email template reset to default.</strong></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="confur-notice error">
                    <p><strong>Error:</strong> Failed to update email templates.</p>
                </div>
            <?php endif; ?>

            <div class="confur-templates-container">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('confur_email_templates_action', 'confur_email_templates_nonce'); ?>
                    <input type="hidden" name="action" value="confur_update_email_templates">

                    <?php foreach ($templates as $key => $template): ?>
                        <div class="confur-template-card">
                            <h2>
                                <?php echo esc_html($template['name']); ?>
                                <span class="confur-template-badge"><?php echo esc_html($key); ?></span>
                            </h2>
                            
                            <div class="template-description">
                                <?php echo esc_html($template['description']); ?>
                                <?php if (!empty($template['placeholders'])): ?>
                                    <br><br>
                                    <strong>Placeholders:</strong>
                                    <?php foreach ($template['placeholders'] as $placeholder): ?>
                                        <code><?php echo esc_html($placeholder); ?></code>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="confur-template-field">
                                <label for="template_<?php echo esc_attr($key); ?>_subject">Subject Line</label>
                                <input
                                    type="text"
                                    id="template_<?php echo esc_attr($key); ?>_subject"
                                    name="template_<?php echo esc_attr($key); ?>_subject"
                                    value="<?php echo esc_attr($template['subject']); ?>"
                                    class="regular-text"
                                />
                            </div>

                            <div class="confur-template-field">
                                <label for="template_<?php echo esc_attr($key); ?>_body">Email Body</label>
                                <?php
                                $editor_id = 'template_' . $key . '_body';
                                $editor_settings = [
                                    'textarea_name' => $editor_id,
                                    'textarea_rows' => 12,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'quicktags' => true,
                                    'tinymce' => [
                                        'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,undo,redo',
                                        'toolbar2' => '',
                                        'block_formats' => 'Paragraph=p;Heading 3=h3;Heading 4=h4',
                                    ],
                                ];
                                wp_editor($template['body'], $editor_id, $editor_settings);
                                ?>
                                <p class="confur-placeholder-info">
                                    Use the placeholders shown above to insert dynamic content.
                                </p>
                            </div>

                            <p>
                                <button type="submit" name="reset_template" value="<?php echo esc_attr($key); ?>" class="button button-secondary" onclick="return confirm('Reset this template to default? This cannot be undone.');">
                                    Reset to Default
                                </button>
                            </p>
                        </div>
                    <?php endforeach; ?>

                    <div class="confur-form-actions">
                        <?php submit_button('Save All Templates', 'primary', 'submit', false); ?>
                        <?php submit_button('Reset All to Defaults', 'delete', 'reset_all_defaults', false, ['onclick' => 'return confirm("Reset ALL templates to their default values? This cannot be undone.");']); ?>
                    </div>
                </form>

                <div class="confur-reset-warning" style="margin-top: 20px;">
                    <strong>Note:</strong> The default templates are stored in the <code>/emails</code> directory of the plugin. 
                    Custom changes are stored in the database and will override the file-based templates.
                </div>
            </div>
        </div>
        <?php
    }
}
