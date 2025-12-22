<?php

namespace Confur;

use Confur\Config\Constants;
use Confur\Config\EmailSettings;
use Confur\Services\AdminAssetService;
use Confur\Services\AssetService;
use Confur\Services\ShortcodeService;
use Confur\Handlers\AnswerHandler;
use Confur\API\AnswerAPI;
use Confur\Admin\StatusAdminPage;
use Confur\Admin\ResultAdminPage;
use Confur\Admin\EmailSettingsAdminPage;

/**
 * Main plugin class
 */
class Plugin
{
	private AssetService $assetService;
	private AdminAssetService $adminAssetService;
	private ShortcodeService $shortcodeService;
	private AnswerHandler $answerHandler;
	private AnswerAPI $answerAPI;
	private StatusAdminPage $answerAdminPage;
	private ResultAdminPage $reportingAdminPage;
	private EmailSettingsAdminPage $emailSettingsAdminPage;

	/**
	 * Initialize the plugin
	 */
	public function init(): void
	{
		// Load constants
		Constants::init();

		// Initialize email settings with defaults
		EmailSettings::initialize();

		// Initialize services
		$this->assetService = new AssetService();
		$this->adminAssetService = new AdminAssetService();
		$this->shortcodeService = new ShortcodeService();
		$this->answerHandler = new AnswerHandler();
		$this->answerAPI = new AnswerAPI();
		$this->answerAdminPage = new StatusAdminPage();
		$this->reportingAdminPage = new ResultAdminPage();
		$this->emailSettingsAdminPage = new EmailSettingsAdminPage();

		// Register hooks
		$this->registerHooks();
	}

	/**
	 * Register WordPress hooks
	 */
	private function registerHooks(): void
	{
		// Asset hooks
		add_action('wp_enqueue_scripts', [$this->assetService, 'enqueueScripts']);
		// Hook admin assets
		add_action('admin_enqueue_scripts', [$this->adminAssetService, 'enqueueScripts']);

		// Shortcode hooks
		add_action('init', [$this->shortcodeService, 'registerShortcodes']);

		// Answer submission hooks
		add_action('admin_post_nopriv_answer_submission', [$this->answerHandler, 'handleSubmission']);
		add_action('admin_post_answer_submission', [$this->answerHandler, 'handleSubmission']);
		add_action('df_after_insert_post', [$this->answerHandler, 'handleAfterInsert'], 10, 2);

		// REST API hooks
		add_action('rest_api_init', [$this->answerAPI, 'registerRoutes']);

		// Admin page hooks
		$this->answerAdminPage->init();
		$this->reportingAdminPage->init();
		$this->emailSettingsAdminPage->init();

		// SEO - Exclude answer post type from search engines
		add_action('init', [$this, 'modifyAnswerPostType'], 99);

		// Divi compatibility - disable custom shortcodes when Visual Builder is active
		add_action('init', [$this, 'maybeDisableShortcodesForDivi'], 20);

		// Hide edit answer admin menu
		add_action('admin_menu', function() {
			remove_submenu_page('edit.php?post_type=answer', 'post-new.php?post_type=answer');
		}, 999);

// Hide the admin menu items if not an administrator.
		add_action('admin_menu', function() {
			$current_user = wp_get_current_user();

			// If NOT an administrator, remove submenu items
			if (!empty($current_user) && !in_array('administrator', (array) $current_user->roles)) {
				// Hide "All Items" submenu
				remove_submenu_page('edit.php?post_type=answer', 'edit.php?post_type=answer');

				// Hide "Add New" submenu
				remove_submenu_page('edit.php?post_type=answer', 'post-new.php?post_type=answer');

				// Add any other submenus you want to hide (categories, tags, etc.)
			}
		}, 999);
	}

	/**
	 * Modify answer post type to exclude from SEO while keeping it publicly accessible
	 */
	public function modifyAnswerPostType(): void
	{
		global $wp_post_types;

		if (isset($wp_post_types['answer'])) {
			//$wp_post_types['answer']->public = false;
			$wp_post_types['answer']->publicly_queryable = true;
			$wp_post_types['answer']->exclude_from_search = true;

		}

	}

	/**
	 * Disable custom shortcodes when Divi Visual Builder is active to prevent rendering issues
	 */
	public function maybeDisableShortcodesForDivi(): void
	{
		// Check if Visual Builder is active (either via et_fb parameter or function_exists check)
		$is_visual_builder = isset($_GET['et_fb']) || (function_exists('et_fb_is_enabled') && et_fb_is_enabled());

		if (!$is_visual_builder) {
			return;
		}

		// List of all custom shortcodes to disable - verify these names match your ShortcodeService.php registrations
		$custom_shortcodes = [
			// Answer shortcodes
			'answer_field',
			'question',
			'committee',
			'start_committee',
			'end_committee',
			'header',
			'custom_form',
			'status',
			'progress_table',
			'control',
			'days_remaining',

			// General shortcodes
			'open_blank',
			'link_email',
			'pdf_link',

			// Step shortcode
			'step',

			// Tradition shortcode
			'tradition',

			// Add any shortcodes from ReportingShortcode.php here
		];

		// Remove all custom shortcodes when Visual Builder is active
		foreach ($custom_shortcodes as $shortcode) {
			remove_shortcode($shortcode);
		}
	}
}