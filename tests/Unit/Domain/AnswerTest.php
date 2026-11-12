<?php

namespace Tests\Unit\Domain;

use Confur\Domain\Answer;
use Confur\Domain\AnswerKey;
use Confur\Domain\AnswerState;

covers(Answer::class, AnswerKey::class, AnswerState::class);

describe('AnswerKey', function () {
    it('parses an answer field name', function () {
        $key = AnswerKey::parse('c12_a3');

        expect($key)->not->toBeNull()
            ->and($key->committee)->toBe(12)
            ->and($key->question)->toBe(3)
            ->and($key->fieldName())->toBe('c12_a3')
            ->and($key->equals(new AnswerKey(12, 3)))->toBeTrue()
            ->and($key->equals(new AnswerKey(12, 4)))->toBeFalse();
    });

    it('rejects anything else', function (string $name) {
        expect(AnswerKey::parse($name))->toBeNull();
    })->with(['c1_q1', 'c1_a', 'c_a1', 'c1_a1_x', 'xc1_a1', 'email', '']);
});

describe('AnswerState', function () {
    it('reads stored values regardless of case', function (mixed $stored, ?AnswerState $expected) {
        expect(AnswerState::fromStored($stored))->toBe($expected);
    })->with([
        ['Draft', AnswerState::Draft],
        ['complete', AnswerState::Complete],
        [' CANCELLED ', AnswerState::Cancelled],
        ['', null],
        [null, null],
        ['Pending', null],
        [['Draft'], null],
    ]);

    it('treats anything unrecognised as a draft', function () {
        expect(AnswerState::orDraft(null))->toBe(AnswerState::Draft)
            ->and(AnswerState::orDraft('Complete'))->toBe(AnswerState::Complete);
    });
});

describe('Answer', function () {
    it('sorts its meetings so a pair matches either way round', function () {
        $ab = new Answer(1, 20, 10, 'a@b.c', null, null);
        $ba = new Answer(2, 10, 20, 'a@b.c', null, null);
        $single = new Answer(3, 10, null, 'a@b.c', null, null);

        expect($ab->groupIds())->toBe([10, 20])
            ->and($ab->groupIds())->toBe($ba->groupIds())
            ->and($ab->isPaired())->toBeTrue()
            ->and($single->isPaired())->toBeFalse()
            ->and($single->groupIds())->toBe([10]);
    });

    it('interprets its stored status', function () {
        expect((new Answer(1, null, null, null, null, 'cancelled'))->isCancelled())->toBeTrue()
            ->and((new Answer(1, null, null, null, null, null))->state())->toBe(AnswerState::Draft);
    });

    it('returns an empty string for an unanswered question', function () {
        $answer = new Answer(1, null, null, null, null, null, ['c1_a1' => 'Yes']);

        expect($answer->answer(new AnswerKey(1, 1)))->toBe('Yes')
            ->and($answer->answer(new AnswerKey(1, 2)))->toBe('');
    });
});
