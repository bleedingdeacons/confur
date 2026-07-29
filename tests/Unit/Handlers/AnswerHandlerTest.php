<?php

namespace Tests\Unit\Handlers;

use ConfurJsonResponse;
use Confur\Config\Constants;
use Confur\Handlers\AnswerHandler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Confur\Handlers\AnswerHandler
 */
class AnswerHandlerTest extends TestCase
{
    private AnswerHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['confur_options'] = [
            'confur_email_settings' => [
                'registration_reply' => 'reply@x.com',
                'support' => 'support@x.com',
                'backup' => 'backup@x.com',
                'delete_blocked_posts' => false,
                'enable_duplicate_detection' => false,
            ],
            'confur_email_blocklist' => [],
            'confur_email_templates' => [
                'RegistrationConfirmation' => ['subject' => 'C', 'body' => 'Hi {{MeetingName}}'],
                'RegistrationBlocked' => ['subject' => 'B', 'body' => 'Blocked'],
            ],
        ];
        $GLOBALS['confur_posts'] = [];
        $GLOBALS['confur_fields'] = [];
        $GLOBALS['confur_poststatus'] = [];
        $GLOBALS['confur_titles'] = [];
        $GLOBALS['confur_permalinks'] = [];
        $GLOBALS['confur_trashed_posts'] = [];
        $GLOBALS['confur_deleted_posts'] = [];
        $GLOBALS['confur_url_to_postid'] = 0;
        $GLOBALS['confur_sent_mail'] = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_REFERER'] = 'http://example.test/answer/x';
        $this->handler = new AnswerHandler();
    }

    /** Run handleSubmission and return the ConfurJsonResponse it terminates with. */
    private function runSubmission(): ConfurJsonResponse
    {
        try {
            $this->handler->handleSubmission();
            $this->fail('Expected a wp_send_json response.');
        } catch (ConfurJsonResponse $r) {
            return $r;
        }
    }

    // ── handleSubmission ─────────────────────────────────────────────────

    public function testSubmissionRejectsNonPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $r = $this->runSubmission();
        $this->assertFalse($r->success);
    }

    public function testSubmissionRejectsUnresolvableReferer(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $GLOBALS['confur_url_to_postid'] = 0;
        $r = $this->runSubmission();
        $this->assertFalse($r->success);
    }

    public function testSubmissionRejectsMissingPost(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $GLOBALS['confur_url_to_postid'] = 50;
        $GLOBALS['confur_poststatus'][50] = false;
        $r = $this->runSubmission();
        $this->assertFalse($r->success);
    }

    public function testSubmissionSavesDraft(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'My answer';
        $GLOBALS['confur_url_to_postid'] = 60;
        $GLOBALS['confur_poststatus'][60] = 'publish';
        $GLOBALS['confur_fields'][60] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = $this->runSubmission();
        $this->assertTrue($r->success);
        $this->assertSame(Constants::STATUS_DRAFT, $r->payload['state']);
        // The answer field was written.
        $this->assertSame('My answer', $GLOBALS['confur_fields'][60]['c1_a1']);
    }

    public function testSubmissionSavesCompleteAndSendsEmail(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_COMPLETED;
        $GLOBALS['confur_url_to_postid'] = 61;
        $GLOBALS['confur_poststatus'][61] = 'publish';
        $GLOBALS['confur_fields'][61] = [Constants::EMAIL_FIELD => 'a@b.com'];
        $GLOBALS['confur_titles'][61] = 'Answers from Group';

        $r = $this->runSubmission();
        $this->assertTrue($r->success);
        $this->assertSame(Constants::STATUS_COMPLETED, $r->payload['state']);
        $this->assertNotEmpty($GLOBALS['confur_sent_mail']);
    }

    public function testSubmissionInvalidStatusDefaultsToDraft(): void
    {
        $_POST['submit_answers'] = 'Bogus'; // not a valid status
        $GLOBALS['confur_url_to_postid'] = 62;
        $GLOBALS['confur_poststatus'][62] = 'publish';
        $GLOBALS['confur_fields'][62] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = $this->runSubmission();
        $this->assertTrue($r->success);
        $this->assertSame(Constants::STATUS_DRAFT, $r->payload['state']);
    }

    public function testSubmissionLogsWhenFieldUpdateFails(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'A new value';
        $GLOBALS['confur_url_to_postid'] = 63;
        $GLOBALS['confur_poststatus'][63] = 'publish';
        $GLOBALS['confur_fields'][63] = [Constants::EMAIL_FIELD => 'a@b.com'];
        // update_field returns false → the "failed to update field" branch runs.
        $GLOBALS['confur_update_field_result'] = false;

        $r = $this->runSubmission();
        $this->assertTrue($r->success);
        unset($GLOBALS['confur_update_field_result']);
    }

    // ── handleRegistration ───────────────────────────────────────────────

    public function testRegistrationIgnoresOtherForms(): void
    {
        $this->handler->handleRegistration('some-other-form', 1);
        $this->assertSame([], $GLOBALS['confur_trashed_posts']);
    }

    public function testRegistrationConfirmsNewRegistration(): void
    {
        $postId = 70;
        $GLOBALS['confur_fields'][$postId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];
        $GLOBALS['confur_titles'][100] = 'Monday Group';
        $GLOBALS['confur_fields'][100] = ['allocated_committee' => '3'];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        $this->assertNotEmpty($GLOBALS['confur_sent_mail']);
        $this->assertSame([], $GLOBALS['confur_trashed_posts']);
    }

    public function testRegistrationBlocksBlockedEmail(): void
    {
        $GLOBALS['confur_options']['confur_email_blocklist'] = ['blocked@x.com'];
        $GLOBALS['confur_options']['confur_email_settings']['delete_blocked_posts'] = true;

        $postId = 71;
        $GLOBALS['confur_fields'][$postId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'blocked@x.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        $this->assertContains($postId, $GLOBALS['confur_deleted_posts']);
    }

    public function testRegistrationTrashesDuplicate(): void
    {
        $GLOBALS['confur_options']['confur_email_settings']['enable_duplicate_detection'] = true;

        // An existing answer with the same meeting/email.
        $GLOBALS['confur_posts'] = [
            (object) ['ID' => 80, 'post_type' => 'answer', 'post_name' => 'existing', 'post_date' => '2024-01-01 00:00:00'],
        ];
        $GLOBALS['confur_fields'][80] = [
            Constants::MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ];
        $GLOBALS['confur_titles'][100] = 'Monday Group';

        // The new (duplicate) registration.
        $newId = 81;
        $GLOBALS['confur_fields'][$newId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $newId);

        $this->assertContains($newId, $GLOBALS['confur_trashed_posts']);
    }

    public function testRegistrationConfirmsPairedRegistration(): void
    {
        $postId = 72;
        $GLOBALS['confur_fields'][$postId] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];
        $GLOBALS['confur_titles'] = [100 => 'Monday Group', 200 => 'Tuesday Group'];
        $GLOBALS['confur_fields'][100] = ['allocated_committee' => '5'];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        $sent = end($GLOBALS['confur_sent_mail']);
        $this->assertStringContainsString('Monday Group and Tuesday Group', $sent['message']);
    }

    public function testRegistrationNormalisesAcfObjectAndArrayMeetings(): void
    {
        $postId = 73;
        // ACF may return the meeting as an object or array rather than an int;
        // normalizePostId() must handle both.
        $GLOBALS['confur_fields'][$postId] = [
            Constants::MEETING_FIELD => (object) ['ID' => 100],
            Constants::FELLOW_MEETING_FIELD => ['ID' => 200],
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];
        $GLOBALS['confur_titles'] = [100 => 'Monday Group', 200 => 'Tuesday Group'];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);
        $this->assertNotEmpty($GLOBALS['confur_sent_mail']);
    }

    public function testRegistrationTrashesPairedDuplicate(): void
    {
        $GLOBALS['confur_options']['confur_email_settings']['enable_duplicate_detection'] = true;

        $GLOBALS['confur_posts'] = [
            (object) ['ID' => 82, 'post_type' => 'answer', 'post_name' => 'existing', 'post_date' => '2024-01-01 00:00:00'],
        ];
        $GLOBALS['confur_fields'][82] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ];
        $GLOBALS['confur_titles'] = [100 => 'Monday Group', 200 => 'Tuesday Group'];

        $newId = 83;
        $GLOBALS['confur_fields'][$newId] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $newId);
        $this->assertContains($newId, $GLOBALS['confur_trashed_posts']);
    }

    public function testRegistrationHandlesMissingMeeting(): void
    {
        $postId = 90;
        $GLOBALS['confur_fields'][$postId] = [
            Constants::MEETING_FIELD => null,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        // Sends the "missing meeting group" error email.
        $this->assertNotEmpty($GLOBALS['confur_sent_mail']);
    }
}
