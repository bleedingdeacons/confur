<?php

namespace Confur\API;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;
use WP_Error;
use WP_REST_Response;

/**
 * REST API endpoints for answers
 */
class AnswerAPI
{
	private AnswerRepository $answerRepository;

	public function __construct()
	{
		$this->answerRepository = new AnswerRepository();
	}

	/**
	 * Register REST API routes
	 */
	public function registerRoutes(): void
	{
		try {
			register_rest_route('answer/v1', '/status/(?P<n>[a-zA-Z0-9_-]+)', [
				'methods' => 'GET',
				'callback' => [$this, 'getAnswerPostStatus'],
				'permission_callback' => '__return_true', // Public endpoint - status is non-sensitive
				'args' => [
					'n' => [
						'required' => true,
						'validate_callback' => function ($param) {
							return is_string($param) && preg_match('/^[a-zA-Z0-9_-]+$/', $param);
						},
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]);
		} catch (\Exception $e) {
			error_log("AnswerAPI::registerRoutes - Failed to register routes: " . $e->getMessage());
			error_log("AnswerAPI::registerRoutes - Stack trace: " . $e->getTraceAsString());
		}
	}

	/**
	 * Get answer post status
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function getAnswerPostStatus($request)
	{

		error_log('[AnswerAPI::getAnswerPostStatus');

		try {
			$postName = sanitize_text_field($request['n']);

			if (empty($postName)) {
				error_log('[AnswerAPI::getAnswerPostStatus] Empty post name received');
				return new WP_Error('invalid_request', 'Post name is required.', ['status' => 400]);
			}

			$post = get_page_by_path($postName, OBJECT, Constants::ANSWER_CUSTOM_TYPE);
			if (!$post) {
				error_log("[AnswerAPI::getAnswerPostStatus] Post not found: $postName");
				return new WP_Error('invalid_post', 'The specified post does not exist.', ['status' => 404]);
			}

			try {
				$status = $this->answerRepository->getAnswerStatus($post->ID);
			} catch (\Exception $e) {
				error_log("[AnswerAPI::getAnswerPostStatus] Failed to retrieve answer status for post ID {$post->ID}: " . $e->getMessage());
				return new WP_Error(
					'repository_error',
					'Failed to retrieve answer status.',
					['status' => 500]
				);
			}

			return new WP_REST_Response($status, 200);
		} catch (\Exception $e) {
			error_log("AnswerAPI::getAnswerPostStatus - Unexpected error: " . $e->getMessage());
			error_log("AnswerAPI::getAnswerPostStatus - Stack trace: " . $e->getTraceAsString());
			return new WP_Error(
				'internal_error',
				'An unexpected error occurred while processing your request.',
				['status' => 500]
			);
		}
	}
}
