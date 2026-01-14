<?php

namespace Confur\Services;

use Confur\Config\Constants;
use Confur\Config\ConfurSettings;
use Confur\Admin\EmailTemplateAdminPage;

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
			'Bcc: ' . ConfurSettings::getSupportEmail(),
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
	 * @param string $allocatedCommittee Allocated committee number (optional)
	 * @return bool Success status
	 */
	public static function sendConfirmation(string $recipient, string $meetingName, string $answerUrl, string $allocatedCommittee = ''): bool
	{
		error_log('EmailService::sendConfirmation email begin');

		$recipient   = sanitize_email($recipient);
		$meetingName = sanitize_text_field($meetingName);
		$answerUrl   = sanitize_url($answerUrl);
		$allocatedCommittee = sanitize_text_field($allocatedCommittee);

		if (!is_email($recipient)) {
			error_log('EmailService::sendRegistrationConfirmation - Invalid email: ' . esc_html($recipient));
			return false;
		}

		$allocationHtml = '';
		if (!empty($allocatedCommittee)) {
			if ($allocatedCommittee === '7') {
				$allocationText = "To start, your group has been allocated the Last Question (under All Committee's)";
			} else {
				$allocationText = 'To start, your group has been allocated Committee: ' . esc_html($allocatedCommittee);
			}

			$allocationHtml = '<div style="background-color: #e8f4fd; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <p style="margin: 0; font-size: 16px; color: #2c3e50;"><strong>' . $allocationText . '</strong></p>
        </div>';
		}

		$params = [
			"MeetingName" => $meetingName,
			"Url" => $answerUrl,
			"AllocationNotice" => $allocationHtml
		];

		$body = self::renderTemplate("RegistrationConfirmation", $params);
		$subject = EmailTemplateAdminPage::getSubject("RegistrationConfirmation");

		$from = ConfurSettings::getRegistrationReplyEmail();

		error_log('EmailService::sendConfirmation send email - ' . $recipient . ' ' . $from . ' ' . $subject . ' ' . $body);

		return self::sendEmail($recipient, $from, $subject, $body);
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
		$subject = EmailTemplateAdminPage::getSubject("AnswersComplete");

		$from = 'Region Representatives <' . ConfurSettings::getRegistrationReplyEmail() . '>';

		return self::sendEmail($recipient, $from, $subject, $body);
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
		$subject = EmailTemplateAdminPage::getSubject("RegistrationBlocked");

		$from = ConfurSettings::getSupportEmail();

		return self::sendEmail($recipient, $from, $subject, $body);
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

		// Get template body from admin settings (falls back to file-based default if not customized)
		$bodyContent = EmailTemplateAdminPage::getBody($name);

		// If no body content found from admin, fall back to file
		if (empty($bodyContent)) {
			$templatePath = CONFUR_PLUGIN_DIR . "/emails/{$name}.html";

			// Verify the file exists and is within the emails directory
			$realPath = realpath($templatePath);
			$emailsDir = realpath(CONFUR_PLUGIN_DIR . "/emails");

			if ($realPath === false || strpos($realPath, $emailsDir) !== 0) {
				error_log('EmailService::renderTemplate - Template not found or path traversal attempt: ' . $name);
				return '';
			}

			$template = file_get_contents($realPath);
		} else {
			// Wrap the body content in HTML structure
			$template = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email</title>
</head>
<body>
' . $bodyContent . '
</body>
</html>';
		}

		foreach ($params as $key => $value) {
			// Skip HTML content (like AllocationNotice) from escaping
			if ($key === 'AllocationNotice') {
				$template = str_replace("{{{$key}}}", $value, $template);
			} else {
				// Escape values to prevent XSS in emails
				$safeValue = esc_html($value);
				$template = str_replace("{{{$key}}}", $safeValue, $template);
			}
		}

		return $template;
	}
}