<?php

namespace Confur\Handlers;

use Confur\Application\DuplicateFinder;
use Confur\Application\RegisterGroup;
use Confur\Application\SaveAnswers;
use Confur\Application\SubmissionRejected;
use Confur\Config\Constants;
use Confur\Config\Settings;
use Confur\Services\Mailer;
use Confur\Storage\AnswerStore;

/**
 * The WordPress entry points for answers: the admin-post form submission and
 * the registration form's after-insert hook.
 *
 * Only the HTTP side lives here — which post a request is about, and turning
 * the outcome into a JSON response. The rules are in SaveAnswers and
 * RegisterGroup. This is also where unexpected errors are caught: each entry
 * point is a boundary, and nothing past it needs its own try/catch.
 */
class AnswerHandler
{
    /**
     * Services are built on first use, from the settings in force at that
     * point, unless they are passed in.
     */
    public function __construct(
        private ?SaveAnswers $saveAnswers = null,
        private ?RegisterGroup $registerGroup = null,
    ) {
    }

    /**
     * admin-post.php?action=answer_submission
     *
     * Superseded by POST confur/v1/answers/{slug} (AnswerAPI::saveAnswers),
     * which names its post explicitly instead of trusting the Referer. Kept
     * for one release so a page whose old script is still cached in a
     * browser can save; each use is logged so its removal can be confirmed
     * safe.
     */
    public function handleSubmission(): void
    {
        error_log('AnswerHandler::handleSubmission - Deprecated admin-post submission received; the answer page script should be using the REST endpoint');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['submit_answers'])) {
                error_log('AnswerHandler::handleSubmission - Invalid request method or missing submit_answers field');
                wp_send_json_error(['message' => 'Unrecognized Action.'], 400);
            }

            // Submissions are constrained to same-site requests: the target
            // post is resolved from the referer, and url_to_postid() only
            // matches URLs on this site, so a cross-site post cannot reach a
            // valid post ID.
            $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $postId = url_to_postid($referer);

            if ($postId === 0) {
                error_log("AnswerHandler::handleSubmission - Could not determine post ID from URI: $referer");
                wp_send_json_error(['message' => 'Invalid referer URL.'], 400);
            }

            $result = $this->saveAnswers()->save($postId, $_POST, $_POST['submit_answers']);

            wp_send_json_success([
                'message' => $result->message,
                'updated' => $result->updated,
                'state' => $result->state->value,
            ]);
        } catch (SubmissionRejected $e) {
            error_log('AnswerHandler::handleSubmission - Rejected: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()], $e->status);
        } catch (\Exception $e) {
            error_log('AnswerHandler::handleSubmission - Unexpected error: ' . $e->getMessage());
            error_log('AnswerHandler::handleSubmission - Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => 'An error occurred while processing your submission. Please try again.',
            ], 500);
        }
    }

    /**
     * df_after_insert_post, fired by the form plugin after it creates a post.
     */
    public function handleRegistration(string $formId, int $postId): void
    {
        if ($formId !== Constants::REGISTER_QUESTION_FORM) {
            return;
        }

        try {
            $this->registerGroup()->register($postId);
        } catch (\Exception $e) {
            error_log('AnswerHandler::handleRegistration - Unexpected error: ' . $e->getMessage());
            error_log('AnswerHandler::handleRegistration - Stack trace: ' . $e->getTraceAsString());

            $email = (string) get_field(Constants::REGISTRATION_RECIPIENT_EMAIL, $postId);

            if ($email !== '') {
                try {
                    Mailer::fromOptions()->sendRegistrationError(
                        $email,
                        'Error: Registration Failed',
                        'There was an unexpected error during your registration. Please try again or contact support.'
                    );
                } catch (\Exception $mailError) {
                    error_log('AnswerHandler::handleRegistration - Failed to send error email: ' . $mailError->getMessage());
                }
            }
        }
    }

    private function saveAnswers(): SaveAnswers
    {
        return $this->saveAnswers ??= new SaveAnswers(new AnswerStore(), Mailer::fromOptions());
    }

    private function registerGroup(): RegisterGroup
    {
        if ($this->registerGroup === null) {
            $store = new AnswerStore();
            $settings = Settings::fromOptions();
            $this->registerGroup = new RegisterGroup($store, new DuplicateFinder($store), new Mailer($settings), $settings);
        }

        return $this->registerGroup;
    }
}
