<?php

namespace Confur\Services;

use Confur\Config\Constants;
use Confur\Config\EmailSettings;

/**
 * Handles email sending functionality
 */
class EmailService
{
    /**
     * Send a custom email
     *
     * @param string $recipient Recipient email address
     * @param string $from From email address
     * @param string $subject Email subject
     * @return bool Success status
     */
    public function sendEmail(string $recipient, string $from, string $subject, $body): bool
    {

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from
        ];

        return wp_mail($recipient, $subject, $body, $headers);
    }

    /**
     * Send backup email
     *
     * @param string $recipientEmail Recipient email
     * @param string $from From email
     * @param string $subject Subject
     * @param string $body Email body
     * @return bool Success status
     */
    public function sendBackup(string $recipientEmail, string $from, string $subject, string $body): bool
    {

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from
        ];

        $isSent = wp_mail($recipientEmail, $subject, $body, $headers);

        if (!$isSent) {
            error_log('EmailService::sendBackupEmail failed!');
        }

        return $isSent;
    }

    /**
     * Send registration confirmation email
     *
     * @param string $recipient Recipient email
     * @param string $meetingName Meeting name
     * @param string $answerUrl Answer URL
     *
     * @return bool Success status
     */
    public function sendConfirmation(string $recipient, string $meetingName, string $answerUrl): bool
    {
        $recipient   = sanitize_email($recipient);
        $meetingName = sanitize_text_field($meetingName);
        $answerUrl   = sanitize_url($answerUrl);

        if (!is_email($recipient)) {
            error_log('EmailService::sendRegistrationConfirmation - Invalid email: ' . esc_html($recipient));
            return false;
        }

        $params = ["meetingName" => $meetingName, "registeredUrl" => $answerUrl];

        $body = $this->renderTemplate("RegistrationConfirmation", $params);

        $from = 'Bristol and District <' . EmailSettings::getRegistrationReplyEmail() . '>';

        return $this->sendEmail($recipient, $from, 'Registration Successful', $body);
    }

    /**
     * Send completion thanks email
     *
     * @param string $recipient Recipient email
     * @param string $meetingName Meeting name
     * @return bool Success status
     */
    public function sendCompletion(string $recipient, string $meetingName): bool
    {
        $recipient = sanitize_email($recipient);
        $meetingName = sanitize_text_field($meetingName);

        if (!is_email($recipient)) {
            error_log('EmailService::sendCompletionThanks - Invalid email: ' . esc_html($recipient));
            return false;
        }

        $params = ["meetingName" => $meetingName];

        $from = 'Region Representatives <' . EmailSettings::getRegistrationReplyEmail() . '>';

        return $this->sendEmail($recipient, $from, 'All Questions Completed :)', $params);
    }

    /**
     * Render email template with parameters
     *
     * @param string $template Template string
     * @param array $params Template parameters
     * @return string Rendered template
     */
    private function renderTemplate(string $name, array $params): string
    {
        $template = file_get_contents(get_template_directory() . "/emails/{$name}.html");

        foreach ($params as $key => $value) {
            $template = str_replace("{{{$key}}}", $value, $template);
        }

        return $template;
    }
}
