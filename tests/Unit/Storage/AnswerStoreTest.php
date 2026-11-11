<?php

namespace Tests\Unit\Storage;

use BleedingDeacons\WpMocks\WpState;
use Confur\Config\Constants;
use Confur\Domain\AnswerKey;
use Confur\Storage\AnswerStore;

covers(AnswerStore::class);

beforeEach(function () {
    WpState::$queryPosts = [];
    $this->store = new AnswerStore();

    $this->answerPost = function (int $id, array $fields, string $status = 'publish'): void {
        $post = (object) ['ID' => $id, 'post_type' => 'answer', 'post_status' => $status, 'post_name' => "p$id"];
        WpState::$queryPosts[] = $post;
        WpState::$posts[$id] = $post;
        WpState::$postStatuses[$id] = $status;
        $this->fields[$id] = $fields;
    };
});

it('loads a registration with normalised meetings', function () {
    ($this->answerPost)(1, [
        Constants::MEETING_FIELD => (object) ['ID' => 10],
        Constants::FELLOW_MEETING_FIELD => ['ID' => '20'],
        Constants::EMAIL_FIELD => 'a@b.c',
        Constants::UPDATED_FIELD => 'Monday, 2024-01-01 01:00:00 PM',
        Constants::STATUS_FIELD => 'Complete',
    ]);

    $answer = $this->store->load(1);

    expect($answer->meetingId)->toBe(10)
        ->and($answer->fellowMeetingId)->toBe(20)
        ->and($answer->email)->toBe('a@b.c')
        ->and($answer->status)->toBe('Complete')
        ->and($answer->answers)->toBe([]);
});

it('loads only non-empty answer fields when asked', function () {
    ($this->answerPost)(1, [
        Constants::EMAIL_FIELD => 'a@b.c',
        'c1_a1' => 'Yes',
        'c1_a2' => '',
        'c1_q1' => 'not an answer field',
    ]);

    expect($this->store->load(1, true)->answers)->toBe(['c1_a1' => 'Yes']);
});

it('reads answers to questions the ACF field group does not define', function () {
    // get_fields() only returns fields ACF knows about. A question added on
    // the Questions screen has no ACF field, so its answer is read by name.
    \Confur\Storage\QuestionSetStore::save(new \Confur\Domain\QuestionSet([1 => 1, 8 => 1]));
    ($this->answerPost)(1, ['c1_a1' => 'Known']);
    \Brain\Monkey\Functions\when('get_fields')->justReturn(['c1_a1' => 'Known']);
    update_field('c8_a1', 'Added later', 1);

    expect($this->store->load(1, true)->answers)->toBe(['c1_a1' => 'Known', 'c8_a1' => 'Added later']);
});

it('still reads an answer after its question is removed from the set', function () {
    // c8_a1 was added on the Questions screen, answered, then removed. It
    // has no ACF field and is no longer in the set, but its meta is still
    // there and the Results page promises to keep showing it.
    \Confur\Storage\QuestionSetStore::save(new \Confur\Domain\QuestionSet([1 => 1]));
    ($this->answerPost)(1, ['c1_a1' => 'Known']);
    \Brain\Monkey\Functions\when('get_fields')->justReturn(['c1_a1' => 'Known']);
    update_field('c8_a1', 'Answered before removal', 1);
    WpState::$postMeta[1] = ['c1_a1' => ['Known'], 'c8_a1' => ['Answered before removal'], 'email' => ['a@b.c']];

    expect($this->store->load(1, true)->answers)->toBe(['c1_a1' => 'Known', 'c8_a1' => 'Answered before removal']);
});

it('lists live registrations, leaving out the excluded one and late-trashed ones', function () {
    ($this->answerPost)(1, []);
    ($this->answerPost)(2, []);
    ($this->answerPost)(3, []);
    // Still returned by the query, but trashed by the time it is read.
    WpState::$postStatuses[3] = 'trash';

    $ids = array_map(fn ($a) => $a->postId, $this->store->all(false, 2));

    expect($ids)->toBe([1])
        ->and(WpState::$options['__last_get_posts_args']['post__not_in'])->toBe([2]);
});

it('returns ids from a single ids-only query', function () {
    ($this->answerPost)(5, []);
    ($this->answerPost)(6, []);

    expect($this->store->ids())->toBe([5, 6])
        ->and(WpState::$options['__last_get_posts_args']['fields'])->toBe('ids');
});

it('reads a sanitised value and saves an answer by key', function () {
    ($this->answerPost)(1, ['c2_a1' => "Line one\nLine two"]);

    expect($this->store->value('c2_a1', 1))->toBe("Line one\nLine two")
        ->and($this->store->saveAnswer(1, new AnswerKey(2, 1), 'Changed'))->toBeTrue()
        ->and(get_field('c2_a1', 1))->toBe('Changed');
});

it('normalises every shape ACF returns for a post', function (mixed $value, ?int $expected) {
    expect(AnswerStore::normalizePostId($value))->toBe($expected);
})->with([
    [123, 123],
    ['456', 456],
    [(object) ['ID' => 7], 7],
    [['ID' => 8], 8],
    [null, null],
    ['', null],
    [0, null],
    ['abc', null],
    [(object) ['post_title' => 'x'], null],
]);
