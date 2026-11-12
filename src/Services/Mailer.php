<?php

namespace Confur\Services;

use Confur\Admin\EmailTemplateAdminPage;
use Confur\Config\Settings;
use Confur\Domain\QuestionSet;

/**
 * Sends the plugin's emails.
 *
 * An instance built from Settings — the addresses it sends from and copies to
 * are passed in, not looked up — so a service that sends mail takes a Mailer
 * in its constructor and a test can hand it one built from a Settings value.
 * The message bodies come from the templates on the Email Templates screen,
 * falling back to the files in emails/.
 */
class Mailer
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /** A Mailer for the settings saved in the admin. */
    public static function fromOptions(): self
    {
        return new self(Settings::fromOptions());
    }

    /**
     * Send an HTML email, blind-copied to the support address.
     */
    public function send(string $recipient, string $from, string $subject, string $body): bool
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'Bcc: ' . $this->settings->supportEmail,
            'From: ' . $from,
        ];

        return wp_mail($recipient, $subject, $body, $headers);
    }

    /**
     * Copy a submission to the backup address before it is saved, so answers
     * survive anything that goes wrong in the save.
     *
     * @param string $pageUrl The answer page the submission came from.
     * @param array<mixed> $submitted The submitted form, as received.
     */
    public function sendBackup(string $pageUrl, array $submitted): bool
    {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->settings->supportEmail,
        ];

        // The submission is member-written text going into an HTML body, so
        // it is escaped. It used to go in raw, which let an answer containing
        // markup render (or run) in the backup mailbox's mail client.
        $json = json_encode($submitted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $body = '<pre>' . esc_html($json) . '</pre>';

        $isSent = wp_mail($this->settings->backupEmail, $pageUrl, $body, $headers);

        if (!$isSent) {
            error_log('Mailer::sendBackup failed');
        }

        return $isSent;
    }

    /**
     * Send the registration confirmation with the group's answer page link.
     *
     * @param string $allocatedCommittee The committee the group was allocated, if any.
     * @param bool $isDuplicate The group had already registered; this resends its existing link.
     */
    public function sendConfirmation(string $recipient, string $groupName, string $answerUrl, string $allocatedCommittee = '', bool $isDuplicate = false): bool
    {
        $recipient = sanitize_email($recipient);
        $groupName = sanitize_text_field($groupName);
        $answerUrl = sanitize_url($answerUrl);
        $allocatedCommittee = sanitize_text_field($allocatedCommittee);

        if (!is_email($recipient)) {
            error_log('Mailer::sendConfirmation - Invalid recipient address');
            return false;
        }

        $allocationHtml = '';
        if ($allocatedCommittee !== '') {
            if (ctype_digit($allocatedCommittee) && QuestionSet::current()->isAllCommittees((int) $allocatedCommittee)) {
                $allocationText = "To start, your group has been allocated the Last Question (under All Committee's)";
            } else {
                $allocationText = 'To start, your group has been allocated Committee: ' . esc_html($allocatedCommittee);
            }

            $allocationHtml = '<div style="background-color: #e8f4fd; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <p style="margin: 0; font-size: 16px; color: #2c3e50;"><strong>' . $allocationText . '</strong></p>
        </div>';
        }

        $registrationStatusHtml = '';
        if ($isDuplicate) {
            $registrationStatusHtml = '<div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <p style="margin: 0; font-size: 16px; color: #856404;"><strong>Note:</strong> You have already registered for this group. This is a reminder with your existing registration link.</p>
        </div>';
        }

        $body = $this->renderTemplate('RegistrationConfirmation', [
            // {{MeetingName}} is kept as the placeholder name: administrators'
            // saved templates use it. It now holds the group's name.
            'MeetingName' => $groupName,
            'Url' => $answerUrl,
            'AllocationNotice' => $allocationHtml,
            'RegistrationStatus' => $registrationStatusHtml,
        ]);

        // Not logged: the recipient and the rendered body are personal data.
        return $this->send(
            $recipient,
            $this->settings->registrationReplyEmail,
            EmailTemplateAdminPage::getSubject('RegistrationConfirmation'),
            $body
        );
    }

    /** Thank a group for completing its answers. */
    public function sendCompletion(string $recipient, string $groupName): bool
    {
        $recipient = sanitize_email($recipient);

        if (!is_email($recipient)) {
            error_log('Mailer::sendCompletion - Invalid recipient address');
            return false;
        }

        return $this->send(
            $recipient,
            'Region Representatives <' . $this->settings->registrationReplyEmail . '>',
            EmailTemplateAdminPage::getSubject('AnswersComplete'),
            $this->renderTemplate('AnswersComplete', ['MeetingName' => sanitize_text_field($groupName)])
        );
    }

    /** Tell a blocked address its registration was not accepted. */
    public function sendRegistrationBlocked(string $recipient): bool
    {
        $recipient = sanitize_email($recipient);

        if (!is_email($recipient)) {
            error_log('Mailer::sendRegistrationBlocked - Invalid recipient address');
            return false;
        }

        return $this->send(
            $recipient,
            $this->settings->supportEmail,
            EmailTemplateAdminPage::getSubject('RegistrationBlocked'),
            $this->renderTemplate('RegistrationBlocked', [])
        );
    }

    /** Tell a registrant something went wrong, from the support address. */
    public function sendRegistrationError(string $recipient, string $subject, string $message): bool
    {
        return $this->send($recipient, $this->settings->supportEmail, $subject, '<p>' . esc_html($message) . '</p>');
    }

    /**
     * Render an email template with its parameters.
     *
     * @param array<string, string> $params
     */
    private function renderTemplate(string $name, array $params): string
    {
        // Sanitize template name to prevent path traversal
        $name = sanitize_file_name($name);

        // Only allow alphanumeric characters and hyphens/underscores
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            error_log('Mailer::renderTemplate - Invalid template name: ' . $name);
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

            // $emailsDir has to be checked too. realpath() returns false, and
            // strpos($realPath, false) compares against '' and returns 0 — so
            // an emails directory that failed to resolve would have *satisfied*
            // this containment test rather than failing it, leaving the
            // traversal guard open.
            if ($realPath === false || $emailsDir === false || strpos($realPath, $emailsDir) !== 0) {
                error_log('Mailer::renderTemplate - Template not found or path traversal attempt: ' . $name);
                return '';
            }

            $template = file_get_contents($realPath);
            if ($template === false) {
                error_log('Mailer::renderTemplate - Template could not be read: ' . $name);
                return '';
            }
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
            // AllocationNotice and RegistrationStatus are HTML built above;
            // everything else is text and is escaped.
            $replacement = ($key === 'AllocationNotice' || $key === 'RegistrationStatus') ? $value : esc_html($value);
            $template = str_replace("{{{$key}}}", $replacement, $template);
        }

        return $template;
    }
}
