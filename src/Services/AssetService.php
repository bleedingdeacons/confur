<?php

namespace Confur\Services;

/**
 * Handles asset enqueueing (scripts and styles)
 */
class AssetService
{
	/**
	 * Enqueue scripts and styles
	 */
	public function enqueueScripts(): void
	{
		// Only enqueue on 'answer' post type
		if (is_singular('answer')) {
			wp_enqueue_script(
				'confur-client-js',
				CONFUR_PLUGIN_URL . 'js/confur-client.js',
				[],
				CONFUR_VERSION,
				true
			);

			// Inject admin URLs
			$this->injectAdminUrls();
		}
	}

	/**
	 * Inject admin URLs for JavaScript
	 */
	private function injectAdminUrls(): void
	{
		$endpoints = [
			'adminUrl' => esc_url(admin_url('admin-post.php')),
			'ajaxUrl' => esc_url(admin_url('admin-ajax.php'))
		];

		wp_add_inline_script(
			'jquery',
			'var endpoints = ' . wp_json_encode($endpoints) . ';'
		);
	}
}