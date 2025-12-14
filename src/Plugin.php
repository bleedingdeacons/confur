<?php

namespace Confur;

use Confur\Config\Constants;
use Confur\Services\AssetService;
use Confur\Services\ShortcodeService;
use Confur\Handlers\AnswerHandler;
use Confur\API\AnswerAPI;
use Confur\Admin\AnswerAdminPage;
use Confur\Admin\ReportingAdminPage;

/**
 * Main plugin class
 */
class Plugin
{
	private AssetService $assetService;
	private ShortcodeService $shortcodeService;
	private AnswerHandler $answerHandler;
	private AnswerAPI $answerAPI;
	private AnswerAdminPage $answerAdminPage;
	private ReportingAdminPage $reportingAdminPage;

	/**
	 * Initialize the plugin
	 */
	public function init(): void
	{
		// Load constants
		Constants::init();

		// Initialize services
		$this->assetService = new AssetService();
		$this->shortcodeService = new ShortcodeService();
		$this->answerHandler = new AnswerHandler();
		$this->answerAPI = new AnswerAPI();
		$this->answerAdminPage = new AnswerAdminPage();
		$this->reportingAdminPage = new ReportingAdminPage();

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

		// SEO - Exclude answer post type from search engines
		add_action('init', [$this, 'modifyAnswerPostType'], 99);

		// Divi compatibility - disable custom shortcodes when Visual Builder is active
		add_action('init', [$this, 'maybeDisableShortcodesForDivi'], 20);
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