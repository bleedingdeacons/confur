<?php

namespace Tests\Unit\Repositories;

use BleedingDeacons\WpMocks\WpState;
use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;

/*
 * Exercises the real AnswerRepository methods (getValue, getAnswerStatus,
 * getAllAnswers, getRegisteredGroups, findDuplicate, getGroupAnswers) against
 * the controllable ACF/post stubs — as opposed to AnswerRepositoryTest, which
 * drives a re-implemented findDuplicate.
 */

covers(AnswerRepository::class);

beforeEach(function () {
    WpState::$queryPosts = [];
    $this->repo = new AnswerRepository();

    // Register an answer post plus its ACF fields.
    $this->answer = function (int $id, array $fields, string $date = '2024-01-01 00:00:00'): void {
        // Seed both: get_posts() reads $queryPosts, get_post() reads $posts,
        // and findDuplicate() goes through each in turn.
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

        $this->fields[$id] = $fields;
    };
});

it('sanitises in getValue', function () {
    update_field('c1_a1', '  <b>hi</b>  ', 0);
    expect($this->repo->getValue('c1_a1'))->toBe('hi');
});

it('returns the existing status from getAnswerStatus', function () {
    $this->fields[5] = [Constants::STATUS_FIELD => 'Complete', Constants::UPDATED_FIELD => '2024-05-01'];
    $status = $this->repo->getAnswerStatus(5);
    expect($status['state'])->toBe('Complete')
        ->and($status['updated'])->toBe('2024-05-01');
});

it('initialises the status in getAnswerStatus when empty', function () {
    $this->fields[6] = [];
    $status = $this->repo->getAnswerStatus(6);
    // update_field writes the draft status, which get_field then reads back.
    expect($status['state'])->toBe(Constants::STATUS_DRAFT)
        ->and($status['updated'])->toBe('N/A');
});

it('returns ids from getAllAnswers', function () {
    ($this->answer)(1, []);
    ($this->answer)(2, []);
    expect(array_values($this->repo->getAllAnswers()))->toBe([1, 2]);
});

it('includes only posts with a meeting in getRegisteredGroups', function () {
    ($this->answer)(1, [Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'a@b.com']);
    ($this->answer)(2, [Constants::MEETING_FIELD => null, Constants::EMAIL_FIELD => 'c@d.com']);

    $groups = $this->repo->getRegisteredGroups();
    expect($groups)->toHaveCount(1)
        ->and($groups[0]['meetingId'])->toBe(100)
        ->and($groups[0]['email'])->toBe('a@b.com');
});

it('normalises varied meeting shapes in getRegisteredGroups', function () {
    ($this->answer)(1, [Constants::MEETING_FIELD => (object) ['ID' => 10], Constants::EMAIL_FIELD => 'a@b.com']);
    ($this->answer)(2, [Constants::MEETING_FIELD => ['ID' => 20], Constants::EMAIL_FIELD => 'b@b.com']);
    ($this->answer)(3, [Constants::MEETING_FIELD => 'not-an-id', Constants::EMAIL_FIELD => 'c@b.com']);

    $groups = $this->repo->getRegisteredGroups();

    // Object and array meetings normalise to their ID; the bogus string
    // yields null and is dropped.
    $ids = array_column($groups, 'meetingId');
    expect($ids)->toContain(10, 20)
        ->and($groups)->toHaveCount(2);
});

// ── findDuplicate (real) ─────────────────────────────────────────────
describe('findDuplicate (real)', function () {
    it('returns null for empty inputs', function () {
        expect($this->repo->findDuplicate(null, null, 'x@y.com'))->toBeNull()
            ->and($this->repo->findDuplicate(100, null, ''))->toBeNull();
    });

    it('matches a single registration', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'TEST@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);

        $result = $this->repo->findDuplicate(100, null, 'test@example.com', 999);
        expect($result)->not->toBeNull()
            ->and($result['post_id'])->toBe(1)
            ->and($result['slug'])->toBe('post-1');
    });

    it('skips cancelled registrations', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_CANCELLED,
        ]);
        expect($this->repo->findDuplicate(100, null, 'test@example.com', 999))->toBeNull();
    });

    it('matches a paired registration in swapped order', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 200,
            Constants::FELLOW_MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        $result = $this->repo->findDuplicate(100, 200, 'test@example.com', 999);
        expect($result['post_id'])->toBe(1);
    });

    it('does not match a paired registration with a single one', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => null,
            Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        expect($this->repo->findDuplicate(100, 200, 'test@example.com', 999))->toBeNull();
    });

    it('returns the latest by updated', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT, Constants::UPDATED_FIELD => '2024-01-01 10:00:00',
        ]);
        ($this->answer)(2, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT, Constants::UPDATED_FIELD => '2024-06-01 10:00:00',
        ]);
        $result = $this->repo->findDuplicate(100, null, 'test@example.com', 999);
        expect($result['post_id'])->toBe(2);
    });

    it('falls back to the post date when there is no updated', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ], '2024-01-01 09:00:00');
        ($this->answer)(2, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ], '2024-09-01 09:00:00');
        $result = $this->repo->findDuplicate(100, null, 'test@example.com', 999);
        expect($result['post_id'])->toBe(2);
    });

    it('skips a different email', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'other@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        expect($this->repo->findDuplicate(100, null, 'test@example.com', 999))->toBeNull();
    });

    it('skips a different meeting', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 555, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        expect($this->repo->findDuplicate(100, null, 'test@example.com', 999))->toBeNull();
    });

    it('excludes the given post', function () {
        ($this->answer)(5, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        expect($this->repo->findDuplicate(100, null, 'test@example.com', 5))->toBeNull();
    });
});

// ── getGroupAnswers ──────────────────────────────────────────────────
describe('getGroupAnswers', function () {
    it('collects answered committee fields', function () {
        ($this->answer)(1, [
            Constants::MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::UPDATED_FIELD => '2024-01-01',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        // Adds to what answer() seeded rather than replacing it: the old
        // harness had get_field() and get_fields() reading separate stores,
        // and real ACF (like WpState) has only one.
        $this->addFields(1, [
            'c1_a1' => 'An answer',
            'c1_a2' => '',          // empty → skipped
            'other' => 'ignored',   // not c\d+_ → skipped
        ]);
        $this->seedTitles([100 => 'Monday Group']);

        $answers = $this->repo->getGroupAnswers();
        expect($answers)->toHaveKey('c1_a1')
            ->not->toHaveKey('c1_a2')
            ->and($answers['c1_a1'][0]['meetingName'])->toBe('Monday Group');
    });

    it('skips trashed posts', function () {
        // getAllAnswers() already excludes the trash by asking get_posts() for
        // publish/draft/pending/private, so the loop's own trash check is the
        // guard for a post trashed *after* that query ran. Reproduce that: the
        // query still returns it, get_post_status() reports it trashed.
        ($this->answer)(1, [Constants::MEETING_FIELD => 100, Constants::UPDATED_FIELD => '2024-01-01']);
        $this->addFields(1, ['c1_a1' => 'x']);

        WpState::$postStatuses[1] = 'trash';

        expect($this->repo->getGroupAnswers())->toBe([]);
    });
});
