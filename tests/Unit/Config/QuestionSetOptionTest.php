<?php

namespace Tests\Unit\Config;

use BleedingDeacons\WpMocks\WpState;
use Confur\Config\QuestionSetOption;
use Confur\Domain\QuestionSet;

covers(QuestionSetOption::class);

it('uses the default set until one is saved', function () {
    expect(QuestionSetOption::load()->equals(new QuestionSet(QuestionSet::DEFAULT)))->toBeTrue()
        ->and(QuestionSetOption::isCustomised())->toBeFalse();
});

it('saves and loads a set, including its All Committees choice', function () {
    $set = new QuestionSet([1 => 2, 2 => 3, 3 => 1], 2);

    expect(QuestionSetOption::save($set))->toBeTrue()
        ->and(WpState::$options[QuestionSetOption::OPTION_NAME])->toBe([
            'committees' => [1 => 2, 2 => 3, 3 => 1],
            'all_committees' => 2,
        ])
        ->and(QuestionSetOption::load()->equals($set))->toBeTrue()
        ->and(QuestionSetOption::isCustomised())->toBeTrue();
});

it('reports success when saving an unchanged set', function () {
    $set = new QuestionSet([1 => 1]);
    QuestionSetOption::save($set);

    expect(QuestionSetOption::save($set))->toBeTrue();
});

it('falls back to the default when the saved value is broken', function (mixed $stored) {
    WpState::$options[QuestionSetOption::OPTION_NAME] = $stored;

    expect(QuestionSetOption::load()->equals(new QuestionSet(QuestionSet::DEFAULT)))->toBeTrue();
})->with([
    'not an array' => ['nonsense'],
    'no committees' => [['all_committees' => 1]],
    'empty committees' => [['committees' => []]],
    'non-numeric count' => [['committees' => [1 => 'x']]],
    'zero questions' => [['committees' => [1 => 0]]],
    'unknown All Committees' => [['committees' => [1 => 1], 'all_committees' => 9]],
]);

it('accepts numbers stored as strings', function () {
    WpState::$options[QuestionSetOption::OPTION_NAME] = ['committees' => ['1' => '2', '4' => '1'], 'all_committees' => '1'];

    $set = QuestionSetOption::load();

    expect($set->questionCounts())->toBe([1 => 2, 4 => 1])
        ->and($set->allCommittees())->toBe(1);
});

it('resets to the default', function () {
    QuestionSetOption::save(new QuestionSet([1 => 1]));

    expect(QuestionSetOption::reset())->toBeTrue()
        ->and(QuestionSetOption::isCustomised())->toBeFalse()
        ->and(QuestionSetOption::reset())->toBeTrue(); // nothing to reset is not a failure
});
