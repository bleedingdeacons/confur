<?php

namespace Tests\Unit\Handlers;

use BleedingDeacons\WpMocks\WpState;
use Confur\Config\Constants;
use Confur\Handlers\AnswerHandler;

covers(AnswerHandler::class);

/*
 * The registration hook, driven end to end through WordPress state. Saving
 * answers is the REST endpoint's job and is tested in AnswerAPITest and
 * SaveAnswersTest.
 */

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
    $this->handler = new AnswerHandler();
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
            Constants::GROUP_FIELD => 100,
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
            Constants::GROUP_FIELD => 100,
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
            Constants::GROUP_FIELD => 100,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ];
        $this->titles[100] = 'Monday Group';

        // The new (duplicate) registration. wp_trash_post() moves a post that
        // exists, so it has to be seeded to be trashable.
        $newId = 81;
        seedAnswer($newId, 'new');
        $this->fields[$newId] = [
            Constants::GROUP_FIELD => 100,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $newId);

        expect($this->trashedPostIds())->toContain($newId);
    });

    it('confirms a paired registration', function () {
        $postId = 72;
        $this->fields[$postId] = [
            Constants::GROUP_FIELD => 100,
            Constants::FELLOW_GROUP_FIELD => 200,
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
            Constants::GROUP_FIELD => (object) ['ID' => 100],
            Constants::FELLOW_GROUP_FIELD => ['ID' => 200],
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
            Constants::GROUP_FIELD => 100,
            Constants::FELLOW_GROUP_FIELD => 200,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ];
        $this->seedTitles([100 => 'Monday Group', 200 => 'Tuesday Group']);

        $newId = 83;
        seedAnswer($newId, 'new');
        $this->fields[$newId] = [
            Constants::GROUP_FIELD => 100,
            Constants::FELLOW_GROUP_FIELD => 200,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $newId);
        expect($this->trashedPostIds())->toContain($newId);
    });

    it('handles a missing meeting', function () {
        $postId = 90;
        $this->fields[$postId] = [
            Constants::GROUP_FIELD => null,
            Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com',
        ];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, $postId);

        // Sends the "missing meeting group" error email.
        expect(WpState::$mail)->not->toBeEmpty();
    });
});


// ── the error boundary ───────────────────────────────────────────────
describe('the error boundary', function () {
    it('emails the registrant when registration fails unexpectedly', function () {
        $register = \Mockery::mock(\Confur\Application\RegisterGroup::class);
        $register->shouldReceive('register')->andThrow(new \RuntimeException('boom'));
        $this->handler = new AnswerHandler($register);
        $this->fields[95] = [Constants::REGISTRATION_RECIPIENT_EMAIL => 'a@b.com'];

        $this->handler->handleRegistration(Constants::REGISTER_QUESTION_FORM, 95);

        $sent = end(WpState::$mail);
        expect($sent['to'])->toBe('a@b.com')
            ->and($sent['subject'])->toBe('Error: Registration Failed');
    });

    it('does not run registration for another form', function () {
        $register = \Mockery::mock(\Confur\Application\RegisterGroup::class);
        $register->shouldNotReceive('register');
        $this->handler = new AnswerHandler($register);

        $this->handler->handleRegistration('some-other-form', 96);
    });
});
