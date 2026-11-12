<?php

namespace Tests\Unit\Repositories;

use BleedingDeacons\WpMocks\WpState;
use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;

/*
 * Exercises the AnswerRepository methods (getValue, getAnswerStatus,
 * getAllAnswers, getRegisteredGroups, getGroupAnswers) against the
 * controllable ACF/post stubs. Duplicate detection moved to DuplicateFinder
 * and is tested there.
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

it('reads the given post in getValue', function () {
    update_field('c1_a1', 'current', false);
    $this->fields[7] = ['c1_a1' => 'seven'];
    expect($this->repo->getValue('c1_a1', 7))->toBe('seven');
});

it('reports draft from getAnswerStatus when empty without writing it', function () {
    $this->fields[6] = [];
    $status = $this->repo->getAnswerStatus(6);
    expect($status['state'])->toBe(Constants::STATUS_DRAFT)
        ->and($status['updated'])->toBe('N/A')
        // A read must not write: the public REST endpoint calls this.
        ->and(get_field(Constants::STATUS_FIELD, 6))->toBeNull();
});

it('returns ids from getAllAnswers', function () {
    ($this->answer)(1, []);
    ($this->answer)(2, []);
    expect(array_values($this->repo->getAllAnswers()))->toBe([1, 2]);
});

it('includes only posts with a meeting in getRegisteredGroups', function () {
    ($this->answer)(1, [Constants::GROUP_FIELD => 100, Constants::EMAIL_FIELD => 'a@b.com']);
    ($this->answer)(2, [Constants::GROUP_FIELD => null, Constants::EMAIL_FIELD => 'c@d.com']);

    $groups = $this->repo->getRegisteredGroups();
    expect($groups)->toHaveCount(1)
        ->and($groups[0]['groupId'])->toBe(100)
        ->and($groups[0]['email'])->toBe('a@b.com');
});

it('normalises varied meeting shapes in getRegisteredGroups', function () {
    ($this->answer)(1, [Constants::GROUP_FIELD => (object) ['ID' => 10], Constants::EMAIL_FIELD => 'a@b.com']);
    ($this->answer)(2, [Constants::GROUP_FIELD => ['ID' => 20], Constants::EMAIL_FIELD => 'b@b.com']);
    ($this->answer)(3, [Constants::GROUP_FIELD => 'not-an-id', Constants::EMAIL_FIELD => 'c@b.com']);

    $groups = $this->repo->getRegisteredGroups();

    // Object and array meetings normalise to their ID; the bogus string
    // yields null and is dropped.
    $ids = array_column($groups, 'groupId');
    expect($ids)->toContain(10, 20)
        ->and($groups)->toHaveCount(2);
});

// ── getGroupAnswers ──────────────────────────────────────────────────
describe('getGroupAnswers', function () {
    it('collects answered committee fields', function () {
        ($this->answer)(1, [
            Constants::GROUP_FIELD => 100,
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
            ->and($answers['c1_a1'][0]['groupName'])->toBe('Monday Group');
    });

    it('skips trashed posts', function () {
        // getAllAnswers() already excludes the trash by asking get_posts() for
        // publish/draft/pending/private, so the loop's own trash check is the
        // guard for a post trashed *after* that query ran. Reproduce that: the
        // query still returns it, get_post_status() reports it trashed.
        ($this->answer)(1, [Constants::GROUP_FIELD => 100, Constants::UPDATED_FIELD => '2024-01-01']);
        $this->addFields(1, ['c1_a1' => 'x']);

        WpState::$postStatuses[1] = 'trash';

        expect($this->repo->getGroupAnswers())->toBe([]);
    });
});
