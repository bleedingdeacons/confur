<?php

namespace Confur\Services;

use Confur\Config\Constants;

/**
 * Handles email sending functionality
 */
class EmailService
{
	private const EMAIL_TEMPLATE = '
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .email-content { padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                .footer { margin-top: 20px; font-size: 12px; color: #777; }
            </style>
        </head>
        <body>
            {{content}}
        </body>
        </html>
    ';

	/**
	 * Send a custom email
	 *
	 * @param string $recipientEmail Recipient email address
	 * @param string $from From email address
	 * @param string $subject Email subject
	 * @param array $params Template parameters
	 * @return bool Success status
	 */
	public function sendCustomEmail(string $recipientEmail, string $from, string $subject, array $params = []): bool
	{
		error_log('EmailService::sendCustomEmail');

		$emailContent = $this->renderTemplate(self::EMAIL_TEMPLATE, $params);

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from
		];

		return wp_mail($recipientEmail, $subject, $emailContent, $headers);
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
	public function sendBackupEmail(string $recipientEmail, string $from, string $subject, string $body): bool
	{
		error_log($body);

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
	 * @param string $registeredUrl Registration URL
	 * @return bool Success status
	 */
	public function sendRegistrationConfirmation(string $recipient, string $meetingName, string $registeredUrl): bool
	{
		error_log('EmailService::sendRegistrationConfirmation');

		$recipient = sanitize_email($recipient);
		if (!is_email($recipient)) {
			error_log('EmailService::sendRegistrationConfirmation - Invalid email: ' . esc_html($recipient));
			return false;
		}

		$body = sprintf(
			'<h3>Welcome</h3><p>Hello %s, you are all set to enter your answers.</p><p>To get started <a href="%s" target="_blank" rel="noreferrer noopener">View Questions</a>. You do not need to start entering answers straight away.</p>',
			esc_html($meetingName),
			esc_url($registeredUrl)
		);

		$params = ['content' => $body];
		$from = 'Bristol and District <' . Constants::REGISTRATION_REPLY_EMAIL . '>';

		return $this->sendCustomEmail($recipient, $from, 'Registration Successful', $params);
	}

	/**
	 * Send completion thanks email
	 *
	 * @param string $recipient Recipient email
	 * @param string $meetingName Meeting name
	 * @return bool Success status
	 */
	public function sendCompletionThanks(string $recipient, string $meetingName): bool
	{
		error_log('EmailService::sendCompletionThanks');

		$recipient = sanitize_email($recipient);
		if (!is_email($recipient)) {
			error_log('EmailService::sendCompletionThanks - Invalid email: ' . esc_html($recipient));
			return false;
		}

		$body = sprintf(
			'<h3>Complete!</h3><p>Many thanks to %s for taking the time to give your feedback, the conference committee is very grateful.</p><p>If you have made a mistake or want to change something, you can still make alterations and Save Complete again.</p>',
			esc_html($meetingName)
		);

		$params = ['content' => $body];
		$from = 'Bristol and District <' . Constants::REGISTRATION_REPLY_EMAIL . '>';

		return $this->sendCustomEmail($recipient, $from, 'All Questions Completed', $params);
	}

	/**
	 * Render email template with parameters
	 *
	 * @param string $template Template string
	 * @param array $params Template parameters
	 * @return string Rendered template
	 */
	private function renderTemplate(string $template, array $params): string
	{
		foreach ($params as $key => $value) {
			$template = str_replace("{{{$key}}}", $value, $template);
		}

		return $template;
	}
}