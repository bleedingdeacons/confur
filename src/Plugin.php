<?php

namespace Confur;

use Confur\Config\Constants;
use Confur\Config\ConfurSettings;
use Confur\Services\AdminAssetService;
use Confur\Services\AssetService;
use Confur\Services\ShortcodeService;
use Confur\Handlers\AnswerHandler;
use Confur\API\AnswerAPI;
use Confur\Admin\StatusAdminPage;
use Confur\Admin\ResultAdminPage;
use Confur\Admin\ConfurSettingsAdminPage;
use Confur\Admin\AnswerAdmin;

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
	private ConfurSettingsAdminPage $confurSettingsAdminPage;
	private AnswerAdmin $answerAdmin;

	/**
	 * Initialize the plugin
	 */
	public function init(): void
	{
		try {

			// Load constants
			try {
				Constants::init();
			} catch (\Exception $e) {
				error_log('Plugin::init - Failed to initialize constants: ' . $e->getMessage());
				throw new \Exception('Failed to load plugin constants: ' . $e->getMessage());
			}

			// Initialize email settings with defaults
			try {
				ConfurSettings::initialize();
			} catch (\Exception $e) {
				error_log('Plugin::init - Failed to initialize email settings: ' . $e->getMessage());
				// Continue execution - email settings are not critical for basic functionality
			}

			// Initialize services
			try {
				$this->assetService = new AssetService();
				$this->adminAssetService = new AdminAssetService();
				$this->shortcodeService = new ShortcodeService();
				$this->answerHandler = new AnswerHandler();
				$this->answerAPI = new AnswerAPI();
				$this->answerAdminPage = new StatusAdminPage();
				$this->reportingAdminPage = new ResultAdminPage();
				$this->confurSettingsAdminPage = new ConfurSettingsAdminPage();
				$this->answerAdmin = new AnswerAdmin();
			} catch (\Exception $e) {
				error_log('Plugin::init - Failed to initialize services: ' . $e->getMessage());
				throw new \Exception('Failed to initialize plugin services: ' . $e->getMessage());
			}

			// Register hooks
			$this->registerHooks();

		} catch (\Exception $e) {
			error_log('Plugin::init - Initialization failed: ' . $e->getMessage());
			error_log('Plugin::init - Stack trace: ' . $e->getTraceAsString());

			// Show admin notice
			if (is_admin()) {
				add_action('admin_notices', function() use ($e) {
					$message = sprintf(
						'<strong>Confur Plugin:</strong> Initialization failed. %s',
						esc_html($e->getMessage())
					);
					echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
				});
			}

			throw $e;
		}
	}

	/**
	 * Register WordPress hooks
	 */
	private function registerHooks(): void
	{
		try {
			// Asset hooks
			add_action('wp_enqueue_scripts', [$this->assetService, 'enqueueScripts']);
			// Hook admin assets
			add_action('admin_enqueue_scripts', [$this->adminAssetService, 'enqueueScripts']);

			// Shortcode hooks
			add_action('init', [$this->shortcodeService, 'registerShortcodes']);

			// Answer submission hooks
			add_action('admin_post_nopriv_answer_submission', [$this->answerHandler, 'handleSubmission']);
			add_action('admin_post_answer_submission', [$this->answerHandler, 'handleSubmission']);
			add_action('df_after_insert_post', [$this->answerHandler, 'handleRegistration' ], 10, 2);

			// REST API hooks
			add_action('rest_api_init', [$this->answerAPI, 'registerRoutes']);

			// Register Confur parent menu
			add_action('admin_menu', [$this, 'registerConfurMenu'], 5);

			// Admin page hooks
			try {
				$this->answerAdminPage->init();
				$this->reportingAdminPage->init();
				$this->confurSettingsAdminPage->init();
			} catch (\Exception $e) {
				error_log('Plugin::registerHooks - Failed to initialize admin pages: ' . $e->getMessage());
				// Continue - admin pages are not critical for front-end functionality
			}

			// SEO - Exclude answer post type from search engines
			add_action('init', [$this, 'modifyAnswerPostType'], 99);

			// Divi compatibility - disable custom shortcodes when Visual Builder is active
			add_action('init', [$this, 'maybeDisableShortcodesForDivi'], 20);

		} catch (\Exception $e) {
			error_log('Plugin::registerHooks - Failed to register hooks: ' . $e->getMessage());
			error_log('Plugin::registerHooks - Stack trace: ' . $e->getTraceAsString());
			throw $e;
		}
	}

	/**
	 * Register the Confur parent admin menu
	 */
	public function registerConfurMenu(): void
	{
		add_menu_page(
			'Questions for Conference',                    // Page title
			'Questions for Conference',                    // Menu title
			'read',                                        // Capability
			'confur',                                      // Menu slug
			'__return_null',                               // No callback needed
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGhlaWdodD0iMjRweCIgdmlld0JveD0iMCAtOTYwIDk2MCA5NjAiIHdpZHRoPSIyNHB4IiBmaWxsPSJibGFjayI+PHBhdGggZD0iTTg4MC04MCBMNzIwLTI0MEgzMjBxLTMzIDAtNTYuNS0yMy41VDI0MC0zMjB2LTQwaDQ0MHEzMyAwIDU2LjUtMjMuNVQ3NjAtNDQwdi0yODBoNDBxMzMgMCA1Ni41IDIzLjVUODgwLTY0MHY1NjBaTTE2MC00NzNsNDctNDdoMzkzdi0yODBIMTYwdjMyN1pNODAtMjgwdi01MjBxMC0zMyAyMy41LTU2LjVUMTYwLTg4MGg0NDBxMzMgMCA1Ni41IDIzLjVUNjgwLTgwMHYyODBxMCAzMy0yMy41IDU2LjVUNjAwLTQ0MEgyNDBMODAtMjgwWm04MC0yNDB2LTI4MCAyODBaIi8+PC9zdmc+',
			30                                             // Position
		);

		// Remove the auto-created "Confur" submenu that duplicates the parent
		add_action('admin_menu', function() {
			global $submenu;
			if (isset($submenu['confur'])) {
				foreach ($submenu['confur'] as $key => $item) {
					if (isset($item[2]) && $item[2] === 'confur') {
						unset($submenu['confur'][$key]);
						break;
					}
				}
			}
		}, 999);
	}

	/**
	 * Modify answer post type to exclude from SEO while keeping it publicly accessible
	 */
	public function modifyAnswerPostType(): void
	{
		try {
			global $wp_post_types;

			if (isset($wp_post_types['answer'])) {
				//$wp_post_types['answer']->public = false;
				$wp_post_types['answer']->publicly_queryable = true;
				$wp_post_types['answer']->exclude_from_search = true;
			}
		} catch (\Exception $e) {
			error_log('Plugin::modifyAnswerPostType - Failed to modify post type: ' . $e->getMessage());
			// Don't throw - this is not critical
		}
	}

	/**
	 * Disable custom shortcodes when Divi Visual Builder is active to prevent rendering issues
	 */
	public function maybeDisableShortcodesForDivi(): void
	{
		try {
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
				try {
					remove_shortcode($shortcode);
				} catch (\Exception $e) {
					error_log("Plugin::maybeDisableShortcodesForDivi - Failed to remove shortcode '$shortcode': " . $e->getMessage());
					// Continue with other shortcodes
				}
			}
		} catch (\Exception $e) {
			error_log('Plugin::maybeDisableShortcodesForDivi - Failed to disable shortcodes: ' . $e->getMessage());
			// Don't throw - this is not critical for functionality
		}
	}

	/**
	 * Run on plugin activation
	 *
	 * Register this with: register_activation_hook(__FILE__, [Plugin::class, 'activate']);
	 */
	public static function activate(): void
	{
		$admin = get_role('administrator');
		if (!$admin) {
			return;
		}

		$capabilities = [
			'edit_answer',
			'read_answer',
			'delete_answer',
			'edit_answers',
			'edit_others_answers',
			'publish_answers',
			'read_private_answers',
			'delete_answers',
			'delete_private_answers',
			'delete_published_answers',
			'delete_others_answers',
			'edit_private_answers',
			'edit_published_answers',
			'create_answers',
		];

		foreach ($capabilities as $cap) {
			$admin->add_cap($cap);
		}
	}


}