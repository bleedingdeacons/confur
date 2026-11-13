<?php

namespace Tests\Unit\Domain;

use Brain\Monkey\Filters;
use Confur\Domain\AnswerKey;
use Confur\Domain\QuestionSet;

covers(QuestionSet::class);

it('describes the current cycle by default', function () {
    $set = new QuestionSet(QuestionSet::DEFAULT);

    expect($set->committees())->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($set->questionCount(1))->toBe(3)
        ->and($set->questionCount(7))->toBe(1)
        ->and($set->questionCount(8))->toBe(0)
        ->and($set->fieldNames())->toBe([
            'c1_a1', 'c1_a2', 'c1_a3',
            'c2_a1', 'c2_a2',
            'c3_a1', 'c3_a2',
            'c4_a1', 'c4_a2',
            'c5_a1', 'c5_a2',
            'c6_a1', 'c6_a2',
            'c7_a1',
        ]);
});

it('has no questions for all committees unless told which committee holds them', function () {
    $set = new QuestionSet([2 => 1, 1 => 1, 3 => 1]);

    expect($set->committees())->toBe([1, 2, 3])
        ->and($set->hasAllCommittees())->toBeFalse()
        ->and($set->allCommittees())->toBeNull()
        ->and($set->isAllCommittees(3))->toBeFalse()
        ->and($set->committeeLabel(3))->toBe('Committee 3');
});

it('names the committee that holds them All Committees, whichever number it is', function () {
    $set = new QuestionSet([1 => 1, 2 => 1, 3 => 1], 1);

    expect($set->hasAllCommittees())->toBeTrue()
        ->and($set->isAllCommittees(1))->toBeTrue()
        ->and($set->isAllCommittees(3))->toBeFalse()
        ->and($set->committeeLabel(1))->toBe('All Committees')
        ->and($set->committeeLabel(3))->toBe('Committee 3');
});

it('keeps the bundled last question for all committees', function () {
    $set = QuestionSet::defaults();

    expect($set->questionCounts())->toBe(QuestionSet::DEFAULT)
        ->and($set->allCommittees())->toBe(QuestionSet::DEFAULT_ALL_COMMITTEES)
        ->and($set->committeeLabel(7))->toBe('All Committees')
        ->and($set->committeeLabel(6))->toBe('Committee 6');
});

it('rejects a malformed set', function (array $counts, ?int $all) {
    new QuestionSet($counts, $all);
})->throws(\InvalidArgumentException::class)->with([
    'empty' => [[], null],
    'zero questions' => [[1 => 0], null],
    'committee zero' => [[0 => 1], null],
    'non-integer count' => [[1 => '2'], null],
    'unknown All Committees' => [[1 => 1], 5],
]);

it('knows which keys belong to it', function () {
    $set = new QuestionSet(QuestionSet::DEFAULT);

    expect($set->contains(new AnswerKey(1, 3)))->toBeTrue()
        ->and($set->contains(new AnswerKey(1, 4)))->toBeFalse()
        ->and($set->contains(new AnswerKey(8, 1)))->toBeFalse()
        ->and($set->contains(new AnswerKey(1, 0)))->toBeFalse();
});

it('lists the questions without an answer', function () {
    $set = new QuestionSet([1 => 2, 2 => 1]);

    $unanswered = $set->unanswered(['c1_a1' => 'Yes', 'c1_a2' => "  \n ", 'c9_a9' => 'stray']);

    expect(array_map(fn (AnswerKey $k) => $k->fieldName(), $unanswered))->toBe(['c1_a2', 'c2_a1'])
        ->and($set->isComplete(['c1_a1' => 'a', 'c1_a2' => 'b', 'c2_a1' => 'c']))->toBeTrue();
});

it('uses the set saved in the admin', function () {
    \Confur\Storage\QuestionSetStore::save(new QuestionSet([1 => 2, 2 => 2, 3 => 1], 1));

    $set = QuestionSet::current();

    expect($set->committees())->toBe([1, 2, 3])
        ->and($set->committeeLabel(1))->toBe('All Committees')
        ->and($set->committeeLabel(3))->toBe('Committee 3');
});

it('keeps the saved All Committees choice when a filter adjusts the counts', function () {
    \Confur\Storage\QuestionSetStore::save(new QuestionSet([1 => 1, 2 => 1, 3 => 1], 2));
    Filters\expectApplied('confur_question_set')->once()->andReturn([1 => 1, 2 => 5, 3 => 1]);

    $set = QuestionSet::current();

    expect($set->questionCount(2))->toBe(5)
        ->and($set->allCommittees())->toBe(2);
});

it('drops the All Committees choice when a filter removes that committee', function () {
    \Confur\Storage\QuestionSetStore::save(new QuestionSet([1 => 1, 2 => 1, 3 => 1], 3));
    Filters\expectApplied('confur_question_set')->once()->andReturn([1 => 1, 2 => 1]);

    expect(QuestionSet::current()->hasAllCommittees())->toBeFalse();
});

it('ignores a filter that returns an invalid set', function () {
    Filters\expectApplied('confur_question_set')->once()->andReturn([1 => 0]);

    expect(QuestionSet::current()->equals(QuestionSet::defaults()))->toBeTrue();
});

it('lets a site replace the set through a filter', function () {
    Filters\expectApplied('confur_question_set')->once()->andReturn([1 => 1, 2 => 4]);

    $set = QuestionSet::current();

    expect($set->committees())->toBe([1, 2])
        ->and($set->questionCount(2))->toBe(4);
});

it('falls back to the default when the filter returns nonsense', function () {
    Filters\expectApplied('confur_question_set')->once()->andReturn('not an array');

    expect(QuestionSet::current()->fieldNames())->toBe((new QuestionSet(QuestionSet::DEFAULT))->fieldNames());
});

it('matches the bundled ACF field group', function () {
    // Every answer the set asks for needs an ACF field to be stored in, and
    // a field with no question is never shown. setup/Acf_Answers.json is
    // imported by hand, so this is the only thing keeping the two in step.
    $json = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/setup/Acf_Answers.json'), true);

    $names = [];
    array_walk_recursive($json, function ($value, $key) use (&$names) {
        if ($key === 'name' && is_string($value) && AnswerKey::parse($value) !== null) {
            $names[] = $value;
        }
    });

    $expected = (new QuestionSet(QuestionSet::DEFAULT))->fieldNames();
    sort($names);
    sort($expected);

    expect($names)->toBe($expected);
});
