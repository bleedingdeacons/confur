<?php

namespace Confur\Services;

/**
 * Handles admin asset enqueueing (scripts and styles)
 */
class AdminAssetService
{
	/**
	 * Enqueue admin scripts and styles
	 */
	public function enqueueScripts(): void
	{
		if (isset($_GET['page']) && $_GET['page'] === 'confur-reporting') {
			wp_enqueue_script(
				'confur-reporting-js',
				CONFUR_PLUGIN_URL . 'js/confur-reporting.js',
				[],
				CONFUR_VERSION,
				true
			);
		 }
	}
}