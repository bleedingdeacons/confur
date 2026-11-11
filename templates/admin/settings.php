<?php
/**
 * Rendered by ConfurSettingsAdminPage::renderAdminPage().
 *
 * @var array<string, mixed> $settings The saved email settings.
 * @var array<string, mixed> $defaults Their default values.
 * @var string $blocklistText The blocked addresses, one per line.
 * @var int $blocklistCount How many there are.
 * @var string $updated The ?updated= flag after a save, or ''.
 * @var string $error The ?error= flag after a failed save, or ''.
 */

?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if ($updated === '1') : ?>
        <div class="confur-notice">
            <p><strong>Settings updated successfully.</strong></p>
        </div>
    <?php endif; ?>

    <?php if ($updated === 'reset') : ?>
        <div class="confur-notice">
            <p><strong>Settings reset to defaults successfully.</strong></p>
        </div>
    <?php endif; ?>

    <?php if ($updated === 'blocklist_cleared') : ?>
        <div class="confur-notice">
            <p><strong>Email blocked list cleared successfully.</strong></p>
        </div>
    <?php endif; ?>

    <?php if ($error !== '') : ?>
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

                <?php if ($blocklistCount > 0) : ?>
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
                <?php if ($blocklistCount > 0) : ?>
                    <?php submit_button('Clear Blocked List', 'delete', 'clear_blocklist', false, ['onclick' => 'return confirm("Are you sure you want to clear the entire blocked list?");']); ?>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
