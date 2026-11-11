<?php

namespace Confur\API;

use Confur\Application\SaveAnswers;
use Confur\Application\SubmissionRejected;
use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;
use Confur\Services\Mailer;
use Confur\Storage\AnswerStore;
use WP_Error;
use WP_REST_Response;

/**
 * REST endpoints for an answer page.
 *
 * Both are public. An answer page's slug has a random 16-character prefix and
 * is only ever sent to the group, so knowing the slug is what grants access —
 * the same model as viewing the page. There is deliberately no nonce: answer
 * pages are viewed logged out, often through a page cache, and a nonce baked
 * into a cached page expires and would make saves fail.
 *
 *   GET  answer/v1/status/{slug}      the page's state and last save time
 *   POST confur/v1/answers/{slug}     save answers: {"state": "Draft"|"Complete", "answers": {"c1_a1": "…"}}
 */
class AnswerAPI
{
    public const SAVE_NAMESPACE = 'confur/v1';
    public const SAVE_ROUTE = '/answers/(?P<slug>[a-zA-Z0-9_-]+)';

    private AnswerRepository $answerRepository;

    public function __construct(private ?SaveAnswers $saveAnswers = null)
    {
        $this->answerRepository = new AnswerRepository();
    }

    /**
     * Register REST API routes
     */
    public function registerRoutes(): void
    {
        $slugArg = [
            'required' => true,
            'validate_callback' => static fn ($param): bool => is_string($param) && preg_match('/^[a-zA-Z0-9_-]+$/', $param) === 1,
            'sanitize_callback' => 'sanitize_text_field',
        ];

        register_rest_route('answer/v1', '/status/(?P<n>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getAnswerPostStatus'],
            'permission_callback' => '__return_true',
            'args' => ['n' => $slugArg],
        ]);

        register_rest_route(self::SAVE_NAMESPACE, self::SAVE_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'saveAnswers'],
            // The slug is the credential; see the class comment.
            'permission_callback' => '__return_true',
            'args' => [
                'slug' => $slugArg,
                'state' => [
                    'type' => 'string',
                    'required' => false,
                ],
                'answers' => [
                    'type' => 'object',
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * GET answer/v1/status/{slug}
     *
     * @param \WP_REST_Request|array<string, mixed> $request
     * @return WP_REST_Response|WP_Error
     */
    public function getAnswerPostStatus($request)
    {
        try {
            $postId = $this->findAnswerPost((string) ($request['n'] ?? ''));

            if ($postId instanceof WP_Error) {
                return $postId;
            }

            try {
                $status = $this->answerRepository->getAnswerStatus($postId);
            } catch (\Exception $e) {
                error_log("AnswerAPI::getAnswerPostStatus - Failed to read status for post $postId: " . $e->getMessage());
                return new WP_Error('repository_error', 'Failed to retrieve answer status.', ['status' => 500]);
            }

            return new WP_REST_Response($status, 200);
        } catch (\Exception $e) {
            error_log('AnswerAPI::getAnswerPostStatus - Unexpected error: ' . $e->getMessage());
            return new WP_Error('internal_error', 'An unexpected error occurred while processing your request.', ['status' => 500]);
        }
    }

    /**
     * POST confur/v1/answers/{slug}
     *
     * @param \WP_REST_Request|array<string, mixed> $request
     * @return WP_REST_Response|WP_Error
     */
    public function saveAnswers($request)
    {
        try {
            $postId = $this->findAnswerPost((string) ($request['slug'] ?? ''));

            if ($postId instanceof WP_Error) {
                return $postId;
            }

            $answers = $request['answers'] ?? null;

            if (!is_array($answers)) {
                return new WP_Error('invalid_request', 'answers must be an object of field name to text.', ['status' => 400]);
            }

            $result = $this->saveService()->save($postId, $answers, $request['state'] ?? null);

            return new WP_REST_Response([
                'message' => $result->message,
                'updated' => $result->updated,
                'state' => $result->state->value,
            ], 200);
        } catch (SubmissionRejected $e) {
            return new WP_Error('submission_rejected', $e->getMessage(), ['status' => $e->status]);
        } catch (\Exception $e) {
            error_log('AnswerAPI::saveAnswers - Unexpected error: ' . $e->getMessage());
            error_log('AnswerAPI::saveAnswers - Stack trace: ' . $e->getTraceAsString());
            return new WP_Error('internal_error', 'An error occurred while saving your answers. Please try again.', ['status' => 500]);
        }
    }

    /** The id of the answer post a slug names, or a WP_Error to return as-is. */
    private function findAnswerPost(string $slug): int|WP_Error
    {
        $slug = sanitize_text_field($slug);

        if ($slug === '') {
            return new WP_Error('invalid_request', 'Post name is required.', ['status' => 400]);
        }

        $post = get_page_by_path($slug, OBJECT, Constants::ANSWER_CUSTOM_TYPE);

        if (!$post instanceof \WP_Post) {
            return new WP_Error('invalid_post', 'The specified post does not exist.', ['status' => 404]);
        }

        return $post->ID;
    }

    private function saveService(): SaveAnswers
    {
        return $this->saveAnswers ??= new SaveAnswers(new AnswerStore(), Mailer::fromOptions());
    }
}
