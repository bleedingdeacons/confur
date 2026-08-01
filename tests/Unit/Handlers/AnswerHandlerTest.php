<?php

namespace Tests\Unit\Handlers;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use Confur\Config\Constants;
use Confur\Handlers\AnswerHandler;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Tests\ConfurTestCase;

/**
 * @covers \Confur\Handlers\AnswerHandler
 */
class AnswerHandlerTest extends ConfurTestCase
{
    private AnswerHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        WpState::$options = [
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
        // parent::setUp() has cleared WpState, so the queries, deletions and
        // sent mail all start empty.
        //
        // url_to_postid() is deliberately NOT stubbed here. Brain Monkey keeps
        // one stub per function per test and the first registered answers every
        // call, so a default set in setUp would silently shadow every per-test
        // override — the failure being a wrong post id several assertions
        // later, not an error. Each test states the id it means.
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_REFERER'] = 'http://example.test/answer/x';
        $this->handler = new AnswerHandler();
    }

    /** Run handleSubmission and return the JsonResponseException it terminates with. */
    private function runSubmission(): JsonResponseException
    {
        try {
            $this->handler->handleSubmission();
            $this->fail('Expected a wp_send_json response.');
        } catch (JsonResponseException $r) {
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
        Functions\when('url_to_postid')->justReturn(0);
        $r = $this->runSubmission();
        $this->assertFalse($r->success);
    }

    public function testSubmissionRejectsMissingPost(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        Functions\when('url_to_postid')->justReturn(50);
        $this->statuses[50] = false;
        $r = $this->runSubmission();
        $this->assertFalse($r->success);
    }

    public function testSubmissionSavesDraft(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'My answer';
        Functions\when('url_to_postid')->justReturn(60);
        $this->statuses[60] = 'publish';
        $this->fields[60] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = $this->runSubmission();
        $this->assertTrue($r->success);
        $this->assertSame(Constants::STATUS_DRAFT, $r->data['state']);
        // The answer field was written.
        $this->assertSame('My answer', get_field('c1_a1', 60));
    }

    public function testSubmissionSavesCompleteAndSendsEmail(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_COMPLETED;
        Functions\when('url_to_postid')->justReturn(61);
        $this->statuses[61] = 'publish';
        $this->fields[61] = [Constants::EMAIL_FIELD => 'a@b.com'];
        $this->titles[61] = 'Answers from Group';

        $r = $this->runSubmission();
        $this->assertTrue($r->success);
        $this->assertSame(Constants::STATUS_COMPLETED, $r->data['state']);
        $this->assertNotEmpty(WpState::$mail);
    }

    public function testSubmissionInvalidStatusDefaultsToDraft(): void
    {
        $_POST['submit_answers'] = 'Bogus'; // not a valid status
        Functions\when('url_to_postid')->justReturn(62);
        $this->statuses[62] = 'publish';
        $this->fields[62] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = $this->runSubmission();
        $this->assertTrue($r->success);
        $this->assertSame(Constants::STATUS_DRAFT, $r->data['state']);
    }

    public function testSubmissionLogsWhenFieldUpdateFails(): void
    {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'A new value';
        Functions\when('url_to_postid')->justReturn(63);
        $this->statuses[63] = 'publish';
        $this->fields[63] = [Constants::EMAIL_FIELD => 'a@b.com'];
        // update_field returns false → the "failed to update field" branch runs.
        Functions\when('update_field')->justReturn(false);

        $r = $this->runSubmission();
        $this->assertTrue($r->success);
    }

    // ── handleRegistration ───────────────────────────────────────────────

    public function testRegistrationIgnoresOtherForms(): void
    {
        $this->handler->handleRegistration('some-other-form', 1);
        $this->assertSame([], $this->trashedPostIds());
    }

    public function testRegistrationConfirmsNewRegistration(): void
    {
        $postId = 70;
        $this->fields[$postId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];
        $this->titles[100] = 'Monday Group';
        $this->fields[100] = ['allocated_committee' => '3'];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        $this->assertNotEmpty(WpState::$mail);
        $this->assertSame([], $this->trashedPostIds());
    }

    public function testRegistrationBlocksBlockedEmail(): void
    {
        WpState::$options['confur_email_blocklist'] = ['blocked@x.com'];
        WpState::$options['confur_email_settings']['delete_blocked_posts'] = true;

        $postId = 71;
        $this->fields[$postId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'blocked@x.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        $this->assertContains($postId, WpState::$deletedPosts);
    }

    public function testRegistrationTrashesDuplicate(): void
    {
        WpState::$options['confur_email_settings']['enable_duplicate_detection'] = true;

        // An existing answer with the same meeting/email. Seeded through
        // both stores: findDuplicate() lists them with get_posts() and then
        // reads each back with get_post().
        $existing = $this->seedAnswer(80, 'existing');
        WpState::$queryPosts = [$existing];
        $this->fields[80] = [
            Constants::MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ];
        $this->titles[100] = 'Monday Group';

        // The new (duplicate) registration. wp_trash_post() moves a post that
        // exists, so it has to be seeded to be trashable.
        $newId = 81;
        $this->seedAnswer($newId, 'new');
        $this->fields[$newId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $newId);

        $this->assertContains($newId, $this->trashedPostIds());
    }

    public function testRegistrationConfirmsPairedRegistration(): void
    {
        $postId = 72;
        $this->fields[$postId] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];
        $this->seedTitles([100 => 'Monday Group', 200 => 'Tuesday Group']);
        $this->fields[100] = ['allocated_committee' => '5'];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        $sent = end(WpState::$mail);
        $this->assertStringContainsString('Monday Group and Tuesday Group', $sent['message']);
    }

    public function testRegistrationNormalisesAcfObjectAndArrayMeetings(): void
    {
        $postId = 73;
        // ACF may return the meeting as an object or array rather than an int;
        // normalizePostId() must handle both.
        $this->fields[$postId] = [
            Constants::MEETING_FIELD => (object) ['ID' => 100],
            Constants::FELLOW_MEETING_FIELD => ['ID' => 200],
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];
        $this->seedTitles([100 => 'Monday Group', 200 => 'Tuesday Group']);

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);
        $this->assertNotEmpty(WpState::$mail);
    }

    public function testRegistrationTrashesPairedDuplicate(): void
    {
        WpState::$options['confur_email_settings']['enable_duplicate_detection'] = true;

        WpState::$queryPosts = [$this->seedAnswer(82, 'existing')];
        $this->fields[82] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ];
        $this->seedTitles([100 => 'Monday Group', 200 => 'Tuesday Group']);

        $newId = 83;
        $this->seedAnswer($newId, 'new');
        $this->fields[$newId] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $newId);
        $this->assertContains($newId, $this->trashedPostIds());
    }

    public function testRegistrationHandlesMissingMeeting(): void
    {
        $postId = 90;
        $this->fields[$postId] = [
            Constants::MEETING_FIELD => null,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        // Sends the "missing meeting group" error email.
        $this->assertNotEmpty(WpState::$mail);
    }

    /**
     * Seed an answer post through both stores get_posts() and get_post() read,
     * and return it so a test can put it in the query results itself.
     */
    private function seedAnswer(int $id, string $slug, string $date = '2024-01-01 00:00:00'): object
    {
        $post = (object) [
            'ID' => $id,
            'post_type' => 'answer',
            'post_name' => $slug,
            'post_date' => $date,
            'post_status' => 'publish',
        ];

        WpState::$posts[$id] = $post;
        WpState::$postTypes[$id] = 'answer';
        WpState::$postStatuses[$id] = 'publish';

        return $post;
    }
}
