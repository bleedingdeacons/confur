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
		register_rest_route('answer/v1', '/status/(?P<name>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'getAnswerPostStatus'],
			'args' => [
				'name' => [
					'required' => true,
					'validate_callback' => function ($param) {
						return is_string($param) && preg_match('/^[a-zA-Z0-9_-]+$/', $param);
					},
				],
			],
		]);
	}

	/**
	 * Get answer post status
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function getAnswerPostStatus($request)
	{
		$postName = sanitize_text_field($request['name']);

		if (empty($postName)) {
			error_log('[AnswerAPI::getAnswerPostStatus] Empty post name received');
			return new WP_Error('invalid_request', 'Post name is required.', ['status' => 400]);
		}

		$post = get_page_by_path($postName, OBJECT, Constants::ANSWER_CUSTOM_TYPE);
		if (!$post) {
			error_log("[AnswerAPI::getAnswerPostStatus] Post not found: $postName");
			return new WP_Error('invalid_post', 'The specified post does not exist.', ['status' => 404]);
		}

		$status = $this->answerRepository->getAnswerStatus($post->ID);

		return new WP_REST_Response($status, 200);
	}
}