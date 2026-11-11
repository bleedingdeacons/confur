<?php

namespace Tests\Unit\Storage;

use BleedingDeacons\WpMocks\WpState;
use Confur\Domain\QuestionSet;
use Confur\Storage\QuestionSetStore;

covers(QuestionSetStore::class);

it('uses the default set until one is saved', function () {
    expect(QuestionSetStore::load()->equals(new QuestionSet(QuestionSet::DEFAULT)))->toBeTrue()
        ->and(QuestionSetStore::isCustomised())->toBeFalse();
});

it('saves and loads a set, including its All Committees choice', function () {
    $set = new QuestionSet([1 => 2, 2 => 3, 3 => 1], 2);

    expect(QuestionSetStore::save($set))->toBeTrue()
        ->and(WpState::$options[QuestionSetStore::OPTION_NAME])->toBe([
            'committees' => [1 => 2, 2 => 3, 3 => 1],
            'all_committees' => 2,
        ])
        ->and(QuestionSetStore::load()->equals($set))->toBeTrue()
        ->and(QuestionSetStore::isCustomised())->toBeTrue();
});

it('reports success when saving an unchanged set', function () {
    $set = new QuestionSet([1 => 1]);
    QuestionSetStore::save($set);

    expect(QuestionSetStore::save($set))->toBeTrue();
});

it('falls back to the default when the saved value is broken', function (mixed $stored) {
    WpState::$options[QuestionSetStore::OPTION_NAME] = $stored;

    expect(QuestionSetStore::load()->equals(new QuestionSet(QuestionSet::DEFAULT)))->toBeTrue();
})->with([
    'not an array' => ['nonsense'],
    'no committees' => [['all_committees' => 1]],
    'empty committees' => [['committees' => []]],
    'non-numeric count' => [['committees' => [1 => 'x']]],
    'fractional count' => [['committees' => [1 => '2.7']]],
    'float count' => [['committees' => [1 => 2.0]]],
    'negative count' => [['committees' => [1 => '-2']]],
    'fractional All Committees' => [['committees' => [1 => 1], 'all_committees' => '1.5']],
    'zero questions' => [['committees' => [1 => 0]]],
    'unknown All Committees' => [['committees' => [1 => 1], 'all_committees' => 9]],
]);

it('accepts numbers stored as strings', function () {
    WpState::$options[QuestionSetStore::OPTION_NAME] = ['committees' => ['1' => '2', '4' => '1'], 'all_committees' => '1'];

    $set = QuestionSetStore::load();

    expect($set->questionCounts())->toBe([1 => 2, 4 => 1])
        ->and($set->allCommittees())->toBe(1);
});

it('resets to the default', function () {
    QuestionSetStore::save(new QuestionSet([1 => 1]));

    expect(QuestionSetStore::reset())->toBeTrue()
        ->and(QuestionSetStore::isCustomised())->toBeFalse()
        ->and(QuestionSetStore::reset())->toBeTrue(); // nothing to reset is not a failure
});
