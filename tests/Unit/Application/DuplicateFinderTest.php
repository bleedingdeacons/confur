<?php

namespace Tests\Unit\Application;

use BleedingDeacons\WpMocks\WpState;
use Confur\Application\DuplicateFinder;
use Confur\Config\Constants;
use Confur\Storage\AnswerStore;

covers(DuplicateFinder::class);

beforeEach(function () {
    WpState::$queryPosts = [];
    $this->finder = new DuplicateFinder(new AnswerStore());

    // An existing registration: get_posts() lists it, get_post() reads its date.
    $this->answer = function (int $id, array $fields, string $date = '2024-01-01 00:00:00'): void {
        $post = (object) [
            'ID' => $id,
            'post_type' => 'answer',
            'post_name' => "post-{$id}",
            'post_date' => $date,
            'post_status' => 'publish',
        ];

        WpState::$queryPosts[] = $post;
        WpState::$posts[$id] = $post;
        WpState::$postTypes[$id] = 'answer';
        WpState::$postStatuses[$id] = 'publish';

        $this->fields[$id] = $fields + [Constants::STATUS_FIELD => Constants::STATUS_DRAFT];
    };
});

it('finds nothing without a meeting or an email', function () {
    expect($this->finder->find(null, null, 'x@y.com'))->toBeNull()
        ->and($this->finder->find(100, null, ''))->toBeNull()
        ->and($this->finder->find(100, null, '   '))->toBeNull();
});

it('matches a single registration, ignoring email case', function () {
    ($this->answer)(1, [Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'TEST@example.com']);

    expect($this->finder->find(100, null, 'test@example.com', 999)?->postId)->toBe(1);
});

it('skips cancelled registrations, however the state is spelled', function (string $state) {
    ($this->answer)(1, [
        Constants::MEETING_FIELD => 100,
        Constants::EMAIL_FIELD => 'test@example.com',
        Constants::STATUS_FIELD => $state,
    ]);

    expect($this->finder->find(100, null, 'test@example.com', 999))->toBeNull();
})->with(['Cancelled', 'cancelled']);

it('matches a paired registration in either order', function () {
    ($this->answer)(1, [
        Constants::MEETING_FIELD => 200,
        Constants::FELLOW_MEETING_FIELD => 100,
        Constants::EMAIL_FIELD => 'test@example.com',
    ]);

    expect($this->finder->find(100, 200, 'test@example.com', 999)?->postId)->toBe(1);
});

it('does not match paired with single, either way round', function () {
    ($this->answer)(1, [Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com']);
    ($this->answer)(2, [
        Constants::MEETING_FIELD => 300,
        Constants::FELLOW_MEETING_FIELD => 400,
        Constants::EMAIL_FIELD => 'test@example.com',
    ]);

    expect($this->finder->find(100, 200, 'test@example.com', 999))->toBeNull()
        ->and($this->finder->find(300, null, 'test@example.com', 999))->toBeNull();
});

it('skips a different email or meeting', function () {
    ($this->answer)(1, [Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'other@example.com']);
    ($this->answer)(2, [Constants::MEETING_FIELD => 555, Constants::EMAIL_FIELD => 'test@example.com']);

    expect($this->finder->find(100, null, 'test@example.com', 999))->toBeNull();
});

it('excludes the new registration itself', function () {
    ($this->answer)(5, [Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com']);

    expect($this->finder->find(100, null, 'test@example.com', 5))->toBeNull();
});

describe('choosing among several', function () {
    it('returns the latest by updated date', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::UPDATED_FIELD => '2024-01-01 10:00:00',
        ]);
        ($this->answer)(2, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::UPDATED_FIELD => '2024-06-01 10:00:00',
        ]);

        expect($this->finder->find(100, null, 'test@example.com', 999)?->postId)->toBe(2);
    });

    it('prefers one with an updated date over one without', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
        ], '2025-01-01 00:00:00');
        ($this->answer)(2, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::UPDATED_FIELD => '2024-06-01 10:00:00',
        ]);

        expect($this->finder->find(100, null, 'test@example.com', 999)?->postId)->toBe(2);
    });

    it('falls back to the post date when neither has been updated', function () {
        ($this->answer)(1, [Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com'], '2024-01-01 09:00:00');
        ($this->answer)(2, [Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com'], '2024-09-01 09:00:00');

        expect($this->finder->find(100, null, 'test@example.com', 999)?->postId)->toBe(2);
    });
});
