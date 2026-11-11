<?php

namespace Confur\Services;

use Confur\API\AnswerAPI;
use Confur\Config\Constants;

/**
 * Front-end assets for an answer page.
 */
class AssetService
{
    public const CLIENT_HANDLE = 'confur-client-js';

    /**
     * Enqueue the answer page script, with the page's configuration.
     */
    public function enqueueScripts(): void
    {
        if (!is_singular(Constants::ANSWER_CUSTOM_TYPE)) {
            return;
        }

        wp_enqueue_script(
            self::CLIENT_HANDLE,
            CONFUR_PLUGIN_URL . 'js/confur-client.js',
            [],
            CONFUR_VERSION,
            true
        );

        // Attached to this script's own handle. The old endpoint URLs were
        // added to 'jquery', so they went missing on any page without jQuery.
        wp_localize_script(self::CLIENT_HANDLE, 'confurAnswerPage', self::clientConfig());
    }

    /**
     * What the answer page script needs: which page it is on, and where to
     * read its status and save its answers. The URLs come from rest_url() so
     * they are right on a site installed in a subdirectory or without pretty
     * permalinks, where the old hard-coded '/wp-json/...' paths were not.
     *
     * @return array{slug: string, statusUrl: string, saveUrl: string}
     */
    public static function clientConfig(): array
    {
        $post = get_queried_object();
        $slug = is_object($post) && isset($post->post_name) ? (string) $post->post_name : '';

        return [
            'slug' => $slug,
            'statusUrl' => rest_url('answer/v1/status/' . $slug),
            'saveUrl' => rest_url(AnswerAPI::SAVE_NAMESPACE . '/answers/' . $slug),
        ];
    }
}
