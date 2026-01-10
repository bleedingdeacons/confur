<?php

namespace Confur\Services;

use Confur\Config\Constants;
use Confur\Config\ConfurSettings;

/**
 * Handles email sending functionality
 */
class EmailService
{
	private function __construct() {}

	/**
	 * Send a custom email
	 *
	 * @param string $recipient Recipient email address
	 * @param string $from From email address
	 * @param string $subject Email subject
	 * @param string $body Email body
	 * @return bool Success status
	 */
	public static function sendEmail(string $recipient, string $from, string $subject, string $body): bool
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
	public static function sendBackup(string $recipientEmail, string $from, string $subject, string $body): bool
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
	 * @return bool Success status
	 */
	public static function sendConfirmation(string $recipient, string $meetingName, string $answerUrl): bool
	{
		$recipient   = sanitize_email($recipient);
		$meetingName = sanitize_text_field($meetingName);
		$answerUrl   = sanitize_url($answerUrl);

		if (!is_email($recipient)) {
			error_log('EmailService::sendRegistrationConfirmation - Invalid email: ' . esc_html($recipient));
			return false;
		}

		$params = ["MeetingName" => $meetingName, "Url" => $answerUrl];

		$body = self::renderTemplate("RegistrationConfirmation", $params);

		$from = ConfurSettings::getRegistrationReplyEmail();

		return self::sendEmail($recipient, $from, 'Registration Successful', $body);
	}

	/**
	 * Send completion thanks email
	 *
	 * @param string $recipient Recipient email
	 * @param string $meetingName Meeting name
	 * @return bool Success status
	 */
	public static function sendCompletion(string $recipient, string $meetingName): bool
	{
		$recipient = sanitize_email($recipient);
		$meetingName = sanitize_text_field($meetingName);

		if (!is_email($recipient)) {
			error_log('EmailService::sendCompletionThanks - Invalid email: ' . esc_html($recipient));
			return false;
		}

		$params = ["MeetingName" => $meetingName];

		$body = self::renderTemplate("AnswersComplete", $params);

		$from = 'Region Representatives <' . ConfurSettings::getRegistrationReplyEmail() . '>';

		return self::sendEmail($recipient, $from, 'All Questions Completed :)', $body);
	}

	/**
	 * Send registration blocked email (for blacklisted emails)
	 *
	 * @param string $recipient Recipient email
	 * @return bool Success status
	 */
	public static function sendRegistrationBlocked(string $recipient): bool
	{
		$recipient = sanitize_email($recipient);

		if (!is_email($recipient)) {
			error_log('EmailService::sendRegistrationBlocked - Invalid email: ' . esc_html($recipient));
			return false;
		}

		$body = self::renderTemplate("RegistrationBlocked", []);

		$from = ConfurSettings::getSupportEmail();

		return self::sendEmail($recipient, $from, 'Registration Could Not Be Completed', $body);
	}

	/**
	 * Render email template with parameters
	 *
	 * @param string $name Template name
	 * @param array $params Template parameters
	 * @return string Rendered template
	 */
	private static function renderTemplate(string $name, array $params): string
	{
		// Sanitize template name to prevent path traversal
		$name = sanitize_file_name($name);
		
		// Only allow alphanumeric characters and hyphens/underscores
		if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
			error_log('EmailService::renderTemplate - Invalid template name: ' . $name);
			return '';
		}
		
		$templatePath = CONFUR_PLUGIN_DIR . "/emails/{$name}.html";
		
		// Verify the file exists and is within the emails directory
		$realPath = realpath($templatePath);
		$emailsDir = realpath(CONFUR_PLUGIN_DIR . "/emails");
		
		if ($realPath === false || strpos($realPath, $emailsDir) !== 0) {
			error_log('EmailService::renderTemplate - Template not found or path traversal attempt: ' . $name);
			return '';
		}
		
		$template = file_get_contents($realPath);

		foreach ($params as $key => $value) {
			// Escape values to prevent XSS in emails
			$safeValue = esc_html($value);
			$template = str_replace("{{{$key}}}", $safeValue, $template);
		}

		return $template;
	}
}