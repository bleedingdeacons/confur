<?php

namespace Tests\Unit\Handlers;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Config\Constants;
use Confur\Handlers\AnswerHandler;

covers(AnswerHandler::class);

/**
 * Seed an answer post through both stores get_posts() and get_post() read,
 * and return it so a test can put it in the query results itself.
 */
function seedAnswer(int $id, string $slug, string $date = '2024-01-01 00:00:00'): object
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

beforeEach(function () {
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

    // Run handleSubmission and return the JsonResponseException it terminates with.
    $this->runSubmission = function (): JsonResponseException {
        try {
            $this->handler->handleSubmission();
            $this->fail('Expected a wp_send_json response.');
        } catch (JsonResponseException $r) {
            return $r;
        }
    };
});

// ── handleSubmission ─────────────────────────────────────────────────
describe('handleSubmission', function () {
    it('rejects a non-POST request', function () {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $r = ($this->runSubmission)();
        expect($r->success)->toBeFalse();
    });

    it('rejects an unresolvable referer', function () {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        Functions\when('url_to_postid')->justReturn(0);
        $r = ($this->runSubmission)();
        expect($r->success)->toBeFalse();
    });

    it('rejects a missing post', function () {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        Functions\when('url_to_postid')->justReturn(50);
        $this->statuses[50] = false;
        $r = ($this->runSubmission)();
        expect($r->success)->toBeFalse();
    });

    it('saves a draft', function () {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'My answer';
        Functions\when('url_to_postid')->justReturn(60);
        $this->statuses[60] = 'publish';
        $this->fields[60] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = ($this->runSubmission)();
        expect($r->success)->toBeTrue()
            ->and($r->data['state'])->toBe(Constants::STATUS_DRAFT)
            // The answer field was written.
            ->and(get_field('c1_a1', 60))->toBe('My answer');
    });

    it('saves a completed submission and sends the email', function () {
        $_POST['submit_answers'] = Constants::STATUS_COMPLETED;
        Functions\when('url_to_postid')->justReturn(61);
        $this->statuses[61] = 'publish';
        $this->fields[61] = [Constants::EMAIL_FIELD => 'a@b.com'];
        $this->titles[61] = 'Answers from Group';

        $r = ($this->runSubmission)();
        expect($r->success)->toBeTrue()
            ->and($r->data['state'])->toBe(Constants::STATUS_COMPLETED)
            ->and(WpState::$mail)->not->toBeEmpty();
    });

    it('defaults an invalid status to draft', function () {
        $_POST['submit_answers'] = 'Bogus'; // not a valid status
        Functions\when('url_to_postid')->justReturn(62);
        $this->statuses[62] = 'publish';
        $this->fields[62] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = ($this->runSubmission)();
        expect($r->success)->toBeTrue()
            ->and($r->data['state'])->toBe(Constants::STATUS_DRAFT);
    });

    it('logs when a field update fails', function () {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'A new value';
        Functions\when('url_to_postid')->justReturn(63);
        $this->statuses[63] = 'publish';
        $this->fields[63] = [Constants::EMAIL_FIELD => 'a@b.com'];
        // update_field returns false → the "failed to update field" branch runs.
        Functions\when('update_field')->justReturn(false);

        $r = ($this->runSubmission)();
        expect($r->success)->toBeTrue();
    });
});

// ── handleRegistration ───────────────────────────────────────────────
describe('handleRegistration', function () {
    it('ignores other forms', function () {
        $this->handler->handleRegistration('some-other-form', 1);
        expect($this->trashedPostIds())->toBe([]);
    });

    it('confirms a new registration', function () {
        $postId = 70;
        $this->fields[$postId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];
        $this->titles[100] = 'Monday Group';
        $this->fields[100] = ['allocated_committee' => '3'];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        expect(WpState::$mail)->not->toBeEmpty()
            ->and($this->trashedPostIds())->toBe([]);
    });

    it('blocks a blocked email', function () {
        WpState::$options['confur_email_blocklist'] = ['blocked@x.com'];
        WpState::$options['confur_email_settings']['delete_blocked_posts'] = true;

        $postId = 71;
        $this->fields[$postId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'blocked@x.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        expect(WpState::$deletedPosts)->toContain($postId);
    });

    it('trashes a duplicate', function () {
        WpState::$options['confur_email_settings']['enable_duplicate_detection'] = true;

        // An existing answer with the same meeting/email. Seeded through
        // both stores: findDuplicate() lists them with get_posts() and then
        // reads each back with get_post().
        $existing = seedAnswer(80, 'existing');
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
        seedAnswer($newId, 'new');
        $this->fields[$newId] = [
            Constants::MEETING_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $newId);

        expect($this->trashedPostIds())->toContain($newId);
    });

    it('confirms a paired registration', function () {
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
        expect($sent['message'])->toContain('Monday Group and Tuesday Group');
    });

    it('normalises ACF object and array meetings', function () {
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
        expect(WpState::$mail)->not->toBeEmpty();
    });

    it('trashes a paired duplicate', function () {
        WpState::$options['confur_email_settings']['enable_duplicate_detection'] = true;

        WpState::$queryPosts = [seedAnswer(82, 'existing')];
        $this->fields[82] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ];
        $this->seedTitles([100 => 'Monday Group', 200 => 'Tuesday Group']);

        $newId = 83;
        seedAnswer($newId, 'new');
        $this->fields[$newId] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $newId);
        expect($this->trashedPostIds())->toContain($newId);
    });

    it('handles a missing meeting', function () {
        $postId = 90;
        $this->fields[$postId] = [
            Constants::MEETING_FIELD => null,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        // Sends the "missing meeting group" error email.
        expect(WpState::$mail)->not->toBeEmpty();
    });
});
