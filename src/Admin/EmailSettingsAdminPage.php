<?php

namespace Confur\Admin;

use Confur\Config\EmailSettings;

/**
 * Admin page for managing email settings
 */
class EmailSettingsAdminPage
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
		add_action('admin_post_confur_update_email_settings', [$this, 'handleFormSubmission']);
		add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
	}

	/**
	 * Add admin menu item as submenu under ACF Answers
	 */
	public function addAdminMenu(): void
	{
		add_submenu_page(
			'edit.php?post_type=answer',    // Parent slug (ACF Answers menu)
			'Email Settings',                // Page title
			'Email Settings',                // Menu title
			'manage_options',                // Capability
			'confur-email-settings',         // Menu slug
			[$this, 'renderAdminPage']      // Callback
		);
	}

	/**
	 * Enqueue admin styles
	 */
	public function enqueueAdminAssets($hook): void
	{
		// Only load on our admin page
		if ($hook !== 'answer_page_confur-email-settings') {
			return;
		}

		// Inline CSS for the admin page
		$custom_css = "
            .confur-email-settings-form {
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
		if (!isset($_POST['confur_email_settings_nonce']) ||
		    !wp_verify_nonce($_POST['confur_email_settings_nonce'], 'confur_email_settings_action')) {
			wp_die(__('Security check failed.'));
		}

		// Check if reset button was clicked
		if (isset($_POST['reset_to_defaults'])) {
			if (EmailSettings::resetToDefaults()) {
				$redirect_url = add_query_arg(
					['page' => 'confur-email-settings', 'updated' => 'reset'],
					admin_url('edit.php?post_type=answer')
				);
			} else {
				$redirect_url = add_query_arg(
					['page' => 'confur-email-settings', 'error' => '1'],
					admin_url('edit.php?post_type=answer')
				);
			}
		} else {
			// Regular update
			$settings = [
				'registration_reply' => $_POST['registration_reply_email'] ?? '',
				'support' => $_POST['support_email'] ?? '',
				'backup' => $_POST['backup_email'] ?? '',
			];

			if (EmailSettings::updateAll($settings)) {
				$redirect_url = add_query_arg(
					['page' => 'confur-email-settings', 'updated' => '1'],
					admin_url('edit.php?post_type=answer')
				);
			} else {
				$redirect_url = add_query_arg(
					['page' => 'confur-email-settings', 'error' => '1'],
					admin_url('edit.php?post_type=answer')
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

		$settings = EmailSettings::getAll();
		$defaults = EmailSettings::getDefaults();

		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
				<div class="confur-notice">
					<p><strong>Email settings updated successfully.</strong></p>
				</div>
			<?php endif; ?>

			<?php if (isset($_GET['updated']) && $_GET['updated'] === 'reset'): ?>
				<div class="confur-notice">
					<p><strong>Email settings reset to defaults successfully.</strong></p>
				</div>
			<?php endif; ?>

			<?php if (isset($_GET['error'])): ?>
				<div class="confur-notice error">
					<p><strong>Error:</strong> Failed to update email settings. Please ensure all email addresses are valid.</p>
				</div>
			<?php endif; ?>

			<div class="confur-email-settings-form">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('confur_email_settings_action', 'confur_email_settings_nonce'); ?>
					<input type="hidden" name="action" value="confur_update_email_settings">

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

					<div class="confur-form-actions">
						<?php submit_button('Save Email Settings', 'primary', 'submit', false); ?>
						<?php submit_button('Reset to Defaults', 'secondary', 'reset_to_defaults', false); ?>
					</div>
				</form>

				<div class="confur-defaults-box">
					<h3>Default Email Addresses</h3>
					<ul>
						<li><strong>Registration Reply:</strong> <?php echo esc_html($defaults['registration_reply']); ?></li>
						<li><strong>Support:</strong> <?php echo esc_html($defaults['support']); ?></li>
						<li><strong>Backup:</strong> <?php echo esc_html($defaults['backup']); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}
}