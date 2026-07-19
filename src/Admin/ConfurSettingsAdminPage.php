<?php

namespace Confur\Admin;

use Confur\Config\ConfurSettings;

/**
 * Admin page for managing Confur settings
 */
class ConfurSettingsAdminPage
{
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
        add_action('admin_post_confur_update_settings', [$this, 'handleFormSubmission']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    /**
     * Add admin menu item as submenu under Confur
     */
    public function addAdminMenu(): void
    {
        add_submenu_page(
                'confur',                        // Parent slug (Confur menu)
                'Confur Settings',               // Page title
                'Settings',                      // Menu title
                'manage_options',                // Capability (admin only)
                'confur-settings',               // Menu slug
                [$this, 'renderAdminPage']      // Callback
        );
    }

    /**
     * Enqueue admin styles
     */
    public function enqueueAdminAssets($hook): void
    {
        // Only load on our admin page
        if ($hook !== 'questions-for-conference_page_confur-settings') {
            return;
        }

        // Inline CSS for the admin page
        $custom_css = "
            .confur-settings-form {
                max-width: 800px;
                background: #fff;
                padding: 20px;
                margin-top: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .confur-form-table {
                width: 100%;
            }
            .confur-form-table th {
                width: 200px;
                text-align: left;
                padding: 15px 10px 15px 0;
                font-weight: 600;
            }
            .confur-form-table td {
                padding: 15px 0;
            }
            .confur-form-table input[type='email'] {
                width: 100%;
                max-width: 400px;
            }
            .confur-form-table .description {
                margin-top: 5px;
                color: #646970;
                font-size: 13px;
            }
            .confur-form-actions {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #e0e0e0;
                display: flex;
                gap: 10px;
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
            .confur-defaults-box {
                background: #f0f0f1;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .confur-defaults-box h3 {
                margin-top: 0;
                font-size: 14px;
            }
            .confur-defaults-box ul {
                margin: 10px 0 0 20px;
            }
            .confur-defaults-box li {
                margin: 5px 0;
                font-size: 13px;
                color: #646970;
            }
            .confur-blocklist-section {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e0e0e0;
            }
            .confur-blocklist-section h2 {
                margin-bottom: 15px;
            }
            .confur-blocklist-textarea {
                width: 100%;
                max-width: 600px;
                min-height: 150px;
                font-family: monospace;
                font-size: 13px;
            }
            .confur-blocklist-count {
                margin-top: 10px;
                font-size: 13px;
                color: #646970;
            }
            .confur-blocklist-warning {
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 12px;
                margin: 15px 0;
            }
            .confur-security-section {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e0e0e0;
            }
            .confur-security-section h2 {
                margin-bottom: 15px;
            }
        ";
        wp_add_inline_style('wp-admin', $custom_css);
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
        if (!isset($_POST['confur_settings_nonce']) ||
            !wp_verify_nonce($_POST['confur_settings_nonce'], 'confur_settings_action')) {
            wp_die(__('Security check failed.'));
        }

        // Check if reset button was clicked
        if (isset($_POST['reset_to_defaults'])) {
            if (ConfurSettings::resetToDefaults()) {
                $redirect_url = add_query_arg(
                        ['page' => 'confur-settings', 'updated' => '1'],
                        admin_url('admin.php')
                );
            } else {
                $redirect_url = add_query_arg(
                        ['page' => 'confur-settings', 'error' => '1'],
                        admin_url('admin.php')
                );
            }
        } elseif (isset($_POST['clear_blocklist'])) {
            // Handle clear blocklist button
            if (ConfurSettings::clearBlocklist()) {
                $redirect_url = add_query_arg(
                        ['page' => 'confur-settings', 'updated' => 'blocklist_cleared'],
                        admin_url('admin.php')
                );
            } else {
                $redirect_url = add_query_arg(
                        ['page' => 'confur-settings', 'error' => 'blocklist'],
                        admin_url('admin.php')
                );
            }
        } else {
            // Regular update - sanitize POST data first
            $registration_reply = isset($_POST['registration_reply_email']) ? sanitize_text_field($_POST['registration_reply_email']) : '';
            $support = isset($_POST['support_email']) ? sanitize_text_field($_POST['support_email']) : '';
            $backup = isset($_POST['backup_email']) ? sanitize_text_field($_POST['backup_email']) : '';
            $delete_blocked_posts = isset($_POST['delete_blocked_posts']) ? true : false;
            $enable_duplicate_detection = isset($_POST['enable_duplicate_detection']) ? true : false;

            $settings = [
                    'registration_reply' => $registration_reply,
                    'support' => $support,
                    'backup' => $backup,
                    'delete_blocked_posts' => $delete_blocked_posts,
                    'enable_duplicate_detection' => $enable_duplicate_detection,
            ];

            // Log what we're trying to save for debugging
            error_log('ConfurSettings - POST data: registration_reply_email=' . $registration_reply . ', support_email=' . $support . ', backup_email=' . $backup);
            error_log('ConfurSettings - Settings array: ' . print_r($settings, true));

            $settingsUpdated = ConfurSettings::updateAll($settings);

            // Process blocklist
            $blocklistRaw = isset($_POST['email_blocklist']) ? sanitize_textarea_field($_POST['email_blocklist']) : '';
            $blocklistEmails = array_filter(array_map('trim', explode("\n", $blocklistRaw)));
            $blocklistUpdated = ConfurSettings::updateBlocklist($blocklistEmails);

            if ($settingsUpdated || $blocklistUpdated) {
                $redirect_url = add_query_arg(
                        ['page' => 'confur-settings', 'updated' => '1'],
                        admin_url('admin.php')
                );
            } else {
                $redirect_url = add_query_arg(
                        ['page' => 'confur-settings', 'error' => '1'],
                        admin_url('admin.php')
                );
            }
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

        $settings = ConfurSettings::getAll();
        $defaults = ConfurSettings::getDefaults();
        $blocklist = ConfurSettings::getBlocklist();
        $blocklistText = implode("\n", $blocklist);
        $blocklistCount = count($blocklist);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
                <div class="confur-notice">
                    <p><strong>Settings updated successfully.</strong></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'reset'): ?>
                <div class="confur-notice">
                    <p><strong>Settings reset to defaults successfully.</strong></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'blocklist_cleared'): ?>
                <div class="confur-notice">
                    <p><strong>Email blocked list cleared successfully.</strong></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="confur-notice error">
                    <p><strong>Error:</strong> Failed to update settings. Please ensure all email addresses are valid.</p>
                </div>
            <?php endif; ?>

            <div class="confur-settings-form">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('confur_settings_action', 'confur_settings_nonce'); ?>
                    <input type="hidden" name="action" value="confur_update_settings">

                    <table class="confur-form-table">
                        <tbody>
                        <tr>
                            <th scope="row">
                                <label for="registration_reply_email">Registration Reply Email</label>
                            </th>
                            <td>
                                <input
                                        type="email"
                                        id="registration_reply_email"
                                        name="registration_reply_email"
                                        value="<?php echo esc_attr($settings['registration_reply']); ?>"
                                        class="regular-text"
                                        required
                                />
                                <p class="description">
                                    Email address used for registration reply messages.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="support_email">Support Email</label>
                            </th>
                            <td>
                                <input
                                        type="email"
                                        id="support_email"
                                        name="support_email"
                                        value="<?php echo esc_attr($settings['support']); ?>"
                                        class="regular-text"
                                        required
                                />
                                <p class="description">
                                    Email address for general support inquiries.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="backup_email">Backup Email</label>
                            </th>
                            <td>
                                <input
                                        type="email"
                                        id="backup_email"
                                        name="backup_email"
                                        value="<?php echo esc_attr($settings['backup']); ?>"
                                        class="regular-text"
                                        required
                                />
                                <p class="description">
                                    Backup email address for system notifications.
                                </p>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <div class="confur-defaults-box">
                        <h3>Default Values</h3>
                        <ul>
                            <li><strong>Registration Reply:</strong> <?php echo esc_html($defaults['registration_reply']); ?></li>
                            <li><strong>Support:</strong> <?php echo esc_html($defaults['support']); ?></li>
                            <li><strong>Backup:</strong> <?php echo esc_html($defaults['backup']); ?></li>
                        </ul>
                    </div>

                    <div class="confur-blocklist-section">
                        <h2>Email Blocked List</h2>
                        <p class="description">
                            Enter email addresses that should be blocked from registration, one per line.
                            Users with blocked emails will receive a different error message during registration.
                        </p>

                        <?php if ($blocklistCount > 0): ?>
                            <div class="confur-blocklist-warning">
                                <strong>Note:</strong> There are currently <strong><?php echo esc_html((string) $blocklistCount); ?></strong> blocked email address(es).
                            </div>
                        <?php endif; ?>

                        <textarea
                                id="email_blocklist"
                                name="email_blocklist"
                                class="confur-blocklist-textarea"
                                placeholder="example@domain.com&#10;another@domain.com"
                        ><?php echo esc_textarea($blocklistText); ?></textarea>

                        <p class="confur-blocklist-count">
                            Currently <?php echo esc_html((string) $blocklistCount); ?> email(s) in blocked list.
                        </p>

                        <p style="margin-top: 15px;">
                            <label for="delete_blocked_posts">
                                <input
                                        type="checkbox"
                                        id="delete_blocked_posts"
                                        name="delete_blocked_posts"
                                        value="1"
                                        <?php checked($settings['delete_blocked_posts'] ?? false); ?>
                                />
                                Delete registration posts from blocked email addresses
                            </label>
                        </p>
                        <p class="description">
                            When enabled, registration attempts from blocked emails will have their posts permanently deleted.
                            When disabled, the posts will remain but the registration will not proceed.
                        </p>
                    </div>

                    <div class="confur-security-section">
                        <h2>Registration Settings</h2>
                        
                        <p style="margin-top: 15px;">
                            <label for="enable_duplicate_detection">
                                <input
                                        type="checkbox"
                                        id="enable_duplicate_detection"
                                        name="enable_duplicate_detection"
                                        value="1"
                                        <?php checked($settings['enable_duplicate_detection'] ?? false); ?>
                                />
                                Enable duplicate registration detection
                            </label>
                        </p>
                        <p class="description">
                            When enabled, the system will check for existing registrations with the same email and meeting combination.
                            If a duplicate is found, the new registration will be moved to trash and the user will receive a reminder email 
                            with a link to their existing registration. Paired registrations (with both meeting and fellow meeting) only match 
                            other paired registrations, and single meeting registrations only match other single registrations.
                        </p>
                    </div>

                    <div class="confur-form-actions">
                        <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
                        <?php submit_button('Reset to Defaults', 'secondary', 'reset_to_defaults', false); ?>
                        <?php if ($blocklistCount > 0): ?>
                            <?php submit_button('Clear Blocked List', 'delete', 'clear_blocklist', false, ['onclick' => 'return confirm("Are you sure you want to clear the entire blocked list?");']); ?>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}