<?php

namespace Tests\Unit\Services;

use Confur\Services\EmailService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Confur\Services\EmailService
 */
class EmailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['confur_options'] = [
            'confur_email_templates' => [
                'RegistrationConfirmation' => ['subject' => 'Confirmed', 'body' => 'Hello {{MeetingName}} {{Url}} {{AllocationNotice}} {{RegistrationStatus}}'],
                'AnswersComplete' => ['subject' => 'Done', 'body' => 'Thanks {{MeetingName}}'],
                'RegistrationBlocked' => ['subject' => 'Blocked', 'body' => 'You are blocked'],
            ],
        ];
        $GLOBALS['confur_sent_mail'] = [];
        unset($GLOBALS['confur_wp_mail_result']);
    }

    public function testSendEmailPassesThroughWpMail(): void
    {
        $this->assertTrue(EmailService::sendEmail('a@b.com', 'from@b.com', 'Sub', '<p>Body</p>'));
        $this->assertCount(1, $GLOBALS['confur_sent_mail']);
    }

    public function testSendBackupSuccessAndFailure(): void
    {
        $this->assertTrue(EmailService::sendBackup('a@b.com', 'from@b.com', 'S', 'B'));

        $GLOBALS['confur_wp_mail_result'] = false;
        $this->assertFalse(EmailService::sendBackup('a@b.com', 'from@b.com', 'S', 'B'));
    }

    public function testSendConfirmationRejectsInvalidEmail(): void
    {
        $this->assertFalse(EmailService::sendConfirmation('not-an-email', 'Group', 'http://x'));
    }

    public function testSendConfirmationWithLastQuestionAllocation(): void
    {
        $this->assertTrue(EmailService::sendConfirmation('a@b.com', 'Group', 'http://x/a', '7'));
        $sent = end($GLOBALS['confur_sent_mail']);
        $this->assertStringContainsString('Last Question', $sent['message']);
    }

    public function testSendConfirmationWithCommitteeAllocationAndDuplicateFlag(): void
    {
        $this->assertTrue(EmailService::sendConfirmation('a@b.com', 'Group', 'http://x/a', '3', true));
        $sent = end($GLOBALS['confur_sent_mail']);
        $this->assertStringContainsString('Committee: 3', $sent['message']);
        $this->assertStringContainsString('already registered', $sent['message']);
    }

    public function testSendCompletionRejectsInvalidEmail(): void
    {
        $this->assertFalse(EmailService::sendCompletion('bad', 'Group'));
    }

    public function testSendCompletionSucceeds(): void
    {
        $this->assertTrue(EmailService::sendCompletion('a@b.com', 'Group'));
        $sent = end($GLOBALS['confur_sent_mail']);
        $this->assertStringContainsString('Thanks Group', $sent['message']);
    }

    public function testSendRegistrationBlockedRejectsInvalidEmail(): void
    {
        $this->assertFalse(EmailService::sendRegistrationBlocked('bad'));
    }

    public function testSendRegistrationBlockedSucceeds(): void
    {
        $this->assertTrue(EmailService::sendRegistrationBlocked('a@b.com'));
        $sent = end($GLOBALS['confur_sent_mail']);
        $this->assertStringContainsString('blocked', strtolower($sent['message']));
    }

    public function testRenderFallsBackToTheBundledTemplateFile(): void
    {
        // No admin-customised template → renderTemplate() reads the packaged
        // emails/AnswersComplete.html file instead.
        $GLOBALS['confur_options'] = [];

        $this->assertTrue(EmailService::sendCompletion('a@b.com', 'Group'));
        $sent = end($GLOBALS['confur_sent_mail']);
        $this->assertNotSame('', $sent['message']);
    }
}
