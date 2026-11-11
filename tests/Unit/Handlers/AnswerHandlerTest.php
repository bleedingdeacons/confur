<?php

namespace Tests\Unit\Handlers;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Config\Constants;
use Confur\Domain\QuestionSet;
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

/** Put an answer to every question in the set into $_POST. */
function answerEveryQuestion(): void
{
    foreach (QuestionSet::current()->fieldNames() as $field) {
        $_POST[$field] = "Answer to $field";
    }
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
        WpState::$postTypes[60] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[60] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = ($this->runSubmission)();
        expect($r->success)->toBeTrue()
            ->and($r->data['state'])->toBe(Constants::STATUS_DRAFT)
            // The answer field was written.
            ->and(get_field('c1_a1', 60))->toBe('My answer');
    });

    it('saves a completed submission and sends the email', function () {
        $_POST['submit_answers'] = Constants::STATUS_COMPLETED;
        answerEveryQuestion();
        Functions\when('url_to_postid')->justReturn(61);
        $this->statuses[61] = 'publish';
        WpState::$postTypes[61] = Constants::ANSWER_CUSTOM_TYPE;
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
        WpState::$postTypes[62] = Constants::ANSWER_CUSTOM_TYPE;
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
        WpState::$postTypes[63] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[63] = [Constants::EMAIL_FIELD => 'a@b.com'];
        // update_field returns false → the "failed to update field" branch runs.
        Functions\when('update_field')->justReturn(false);

        $r = ($this->runSubmission)();
        expect($r->success)->toBeTrue();
    });

    it('rejects a post that is not an answer', function () {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'Injected';
        Functions\when('url_to_postid')->justReturn(64);
        $this->statuses[64] = 'publish';
        WpState::$postTypes[64] = 'page';

        $r = ($this->runSubmission)();
        expect($r->success)->toBeFalse()
            ->and($r->status)->toBe(404)
            ->and(get_field('c1_a1', 64))->toBeNull();
    });

    it('rejects a cancelled registration', function () {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'Late edit';
        Functions\when('url_to_postid')->justReturn(65);
        $this->statuses[65] = 'publish';
        WpState::$postTypes[65] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[65] = [Constants::STATUS_FIELD => Constants::STATUS_CANCELLED];

        $r = ($this->runSubmission)();
        expect($r->success)->toBeFalse()
            ->and($r->status)->toBe(403)
            ->and(get_field('c1_a1', 65))->toBeNull()
            ->and(get_field(Constants::STATUS_FIELD, 65))->toBe(Constants::STATUS_CANCELLED);
    });

    it('compares against the submitted post, not the current one', function () {
        // admin-post.php has no current post. The change check used to read
        // there, so a value matching whatever it found was never saved.
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        $_POST['c1_a1'] = 'New';
        Functions\when('url_to_postid')->justReturn(66);
        $this->statuses[66] = 'publish';
        WpState::$postTypes[66] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[66] = ['c1_a1' => 'Old'];
        update_field('c1_a1', 'New');

        ($this->runSubmission)();
        expect(get_field('c1_a1', 66))->toBe('New');
    });

    it('stores the status and timestamp unescaped', function () {
        $_POST['submit_answers'] = Constants::STATUS_COMPLETED;
        answerEveryQuestion();
        Functions\when('url_to_postid')->justReturn(67);
        Functions\when('current_time')->justReturn('Monday, 2024-01-01 01:02:03 PM');
        $this->statuses[67] = 'publish';
        WpState::$postTypes[67] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[67] = [Constants::EMAIL_FIELD => 'a@b.com'];

        ($this->runSubmission)();
        expect(get_field(Constants::UPDATED_FIELD, 67))->toBe('Monday, 2024-01-01 01:02:03 PM')
            ->and(get_field(Constants::COMPLETION_FIELD, 67))->toBe('Monday, 2024-01-01 01:02:03 PM')
            ->and(get_field(Constants::STATUS_FIELD, 67))->toBe(Constants::STATUS_COMPLETED);
    });

    it('keeps an incomplete set as a draft when Complete is asked for', function () {
        $_POST['submit_answers'] = Constants::STATUS_COMPLETED;
        answerEveryQuestion();
        $_POST['c2_a2'] = '   '; // blank once trimmed
        unset($_POST['c7_a1']);
        Functions\when('url_to_postid')->justReturn(68);
        $this->statuses[68] = 'publish';
        WpState::$postTypes[68] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[68] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = ($this->runSubmission)();
        expect($r->success)->toBeTrue()
            ->and($r->data['state'])->toBe(Constants::STATUS_DRAFT)
            ->and($r->data['message'])->toBe('Answers Saved as Draft: 2 questions still need an answer.')
            // The answers given are still saved.
            ->and(get_field('c1_a1', 68))->toBe('Answer to c1_a1')
            ->and(get_field(Constants::STATUS_FIELD, 68))->toBe(Constants::STATUS_DRAFT)
            ->and(get_field(Constants::COMPLETION_FIELD, 68))->toBe('')
            // No completion email for a set that is not complete.
            ->and(WpState::$mail)->toHaveCount(1); // the backup copy only
    });

    it('completes when earlier saves supplied the remaining answers', function () {
        $_POST['submit_answers'] = Constants::STATUS_COMPLETED;
        $_POST['c7_a1'] = 'The last one';
        Functions\when('url_to_postid')->justReturn(69);
        $this->statuses[69] = 'publish';
        WpState::$postTypes[69] = Constants::ANSWER_CUSTOM_TYPE;

        $saved = [Constants::EMAIL_FIELD => 'a@b.com'];
        foreach (QuestionSet::current()->fieldNames() as $field) {
            if ($field !== 'c7_a1') {
                $saved[$field] = "Saved $field";
            }
        }
        $this->fields[69] = $saved;

        $r = ($this->runSubmission)();
        expect($r->data['state'])->toBe(Constants::STATUS_COMPLETED)
            ->and($r->data['message'])->toBe('Answers Saved as Complete');
    });

    it('counts an answer of "0" as answered', function () {
        $_POST['submit_answers'] = Constants::STATUS_COMPLETED;
        answerEveryQuestion();
        $_POST['c3_a1'] = '0';
        Functions\when('url_to_postid')->justReturn(72);
        $this->statuses[72] = 'publish';
        WpState::$postTypes[72] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[72] = [Constants::EMAIL_FIELD => 'a@b.com'];

        $r = ($this->runSubmission)();
        expect($r->data['state'])->toBe(Constants::STATUS_COMPLETED);
    });

    it('does not let a group mark itself cancelled', function () {
        $_POST['submit_answers'] = Constants::STATUS_CANCELLED;
        Functions\when('url_to_postid')->justReturn(70);
        $this->statuses[70] = 'publish';
        WpState::$postTypes[70] = Constants::ANSWER_CUSTOM_TYPE;

        $r = ($this->runSubmission)();
        expect($r->data['state'])->toBe(Constants::STATUS_DRAFT);
    });

    it('treats a lower-case cancelled state as cancelled', function () {
        $_POST['submit_answers'] = Constants::STATUS_DRAFT;
        Functions\when('url_to_postid')->justReturn(71);
        $this->statuses[71] = 'publish';
        WpState::$postTypes[71] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[71] = [Constants::STATUS_FIELD => 'cancelled'];

        $r = ($this->runSubmission)();
        expect($r->status)->toBe(403);
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
            ->and($this->trashedPostIds())->toBe([])
            // The initial state lands on the new post itself.
            ->and(get_field(Constants::STATUS_FIELD, $postId))->toBe(Constants::DEFAULT_STATUS);
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
