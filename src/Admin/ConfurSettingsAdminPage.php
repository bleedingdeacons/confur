<?php

namespace Confur\Admin;

use Confur\Config\ConfurSettings;
use Confur\Services\AdminAssets;

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
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueueAdminAssets($hook): void
    {
        // Only load on our admin page
        if ($hook !== 'questions-for-conference_page_confur-settings') {
            return;
        }

        AdminAssets::style('settings');
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
            !isset($_POST['confur_settings_nonce']) ||
            !wp_verify_nonce($_POST['confur_settings_nonce'], 'confur_settings_action')
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
     * the test runner with it. Everything below the nonce check lives here
     * unchanged; the caller keeps the guards and the redirect.
     *
     * @return string Redirect URL
     */
    private function resolveSubmissionRedirect(): string
    {
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

            // The submitted addresses are deliberately not logged;
            // ConfurSettings::updateAll() reports validation failures by
            // setting key alone.
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

        $settings = ConfurSettings::getAll();
        $defaults = ConfurSettings::getDefaults();
        $blocklist = ConfurSettings::getBlocklist();
        $blocklistText = implode("\n", $blocklist);
        $blocklistCount = count($blocklist);

        Template::render('admin/settings', [
            'settings' => $settings,
            'defaults' => $defaults,
            'blocklistText' => $blocklistText,
            'blocklistCount' => $blocklistCount,
            'updated' => isset($_GET['updated']) ? sanitize_key(wp_unslash($_GET['updated'])) : '',
            'error' => isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '',
        ]);
    }
}
