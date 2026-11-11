<?php

namespace Tests\Unit\Domain;

use Confur\Domain\SaveTime;

covers(SaveTime::class);

it('reads both the stored and the old format into the stored one', function (string $value, string $expected) {
    expect(SaveTime::normalise($value))->toBe($expected);
})->with([
    'stored' => ['2024-01-01 13:02:03', '2024-01-01 13:02:03'],
    'old, afternoon' => ['Monday, 2024-01-01 01:02:03 PM', '2024-01-01 13:02:03'],
    'old, morning' => ['Tuesday, 2024-01-02 09:00:00 AM', '2024-01-02 09:00:00'],
    'old, midnight' => ['Tuesday, 2024-01-02 12:00:00 AM', '2024-01-02 00:00:00'],
    'old, noon' => ['Tuesday, 2024-01-02 12:30:00 PM', '2024-01-02 12:30:00'],
    'surrounding space' => ['  2024-01-01 13:02:03 ', '2024-01-01 13:02:03'],
]);

it('reads nothing from an empty or unrecognised value', function (mixed $value) {
    expect(SaveTime::normalise($value))->toBeNull();
})->with([
    'empty' => [''],
    'space' => ['   '],
    'null' => [null],
    'N/A' => ['N/A'],
    'date only' => ['2024-01-01'],
    'impossible date' => ['2024-02-30 10:00:00'],
    'ACF display format' => ['Monday, January 1, 2024 1:02 PM'],
    'not a string' => [[2024]],
]);

it('displays a stored time as the ACF field does', function () {
    expect(SaveTime::display('2024-01-01 13:02:03'))->toBe('Monday, January 1, 2024 1:02 PM')
        ->and(SaveTime::display('not a time'))->toBe('not a time');
});

it('sorts correctly as a string once stored, unlike the old format', function () {
    $old = ['Monday, 2024-01-08 09:00:00 AM', 'Friday, 2024-01-05 09:00:00 AM'];
    $stored = array_map(fn ($v) => SaveTime::normalise($v), $old);

    sort($old);
    sort($stored);

    expect($old[0])->toStartWith('Friday') // alphabetical by weekday, not by date…
        ->and($old[0])->toContain('2024-01-05')
        ->and($stored)->toBe(['2024-01-05 09:00:00', '2024-01-08 09:00:00']);
});
