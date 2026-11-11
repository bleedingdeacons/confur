<?php

namespace Confur\Handlers;

use Confur\Application\DuplicateFinder;
use Confur\Application\RegisterGroup;
use Confur\Config\Constants;
use Confur\Config\Settings;
use Confur\Services\Mailer;
use Confur\Storage\AnswerStore;

/**
 * The registration form's WordPress entry point: the form plugin's
 * after-insert hook.
 *
 * The rules are in RegisterGroup. This is the boundary where an unexpected
 * error is caught and the registrant told something went wrong. Saving
 * answers is the REST endpoint's job (AnswerAPI::saveAnswers).
 */
class AnswerHandler
{
    /**
     * RegisterGroup is built on first use, from the settings in force at
     * that point, unless it is passed in.
     */
    public function __construct(private ?RegisterGroup $registerGroup = null)
    {
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
