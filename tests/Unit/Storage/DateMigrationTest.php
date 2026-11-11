<?php

namespace Tests\Unit\Storage;

use BleedingDeacons\WpMocks\WpState;
use Confur\Cli\MigrateDatesCommand;
use Confur\Storage\DateMigration;

covers(DateMigration::class, MigrateDatesCommand::class);

beforeEach(function () {
    WpState::$queryPosts = [];
    \WP_CLI::$printed = [];

    // An answer post with raw stored times (post meta, as ACF keeps it).
    $this->answer = function (int $id, array $meta, string $status = 'publish'): void {
        WpState::$queryPosts[] = (object) ['ID' => $id, 'post_type' => 'answer', 'post_status' => $status];
        WpState::$postMeta[$id] = $meta;
    };
});

it('converts old-format times and leaves everything else alone', function () {
    ($this->answer)(1, ['updated' => 'Monday, 2024-01-01 01:02:03 PM', 'completed' => 'Monday, 2024-01-01 01:02:03 PM']);
    ($this->answer)(2, ['updated' => '2024-02-01 10:00:00', 'completed' => '']);
    ($this->answer)(3, ['updated' => 'whenever']);
    ($this->answer)(4, ['updated' => 'Friday, 2024-03-01 09:00:00 AM'], 'trash');

    $report = (new DateMigration())->run(false);

    expect(WpState::$postMeta[1])->toBe(['updated' => '2024-01-01 13:02:03', 'completed' => '2024-01-01 13:02:03'])
        ->and(WpState::$postMeta[2]['updated'])->toBe('2024-02-01 10:00:00')
        ->and(WpState::$postMeta[3]['updated'])->toBe('whenever')        // unreadable: untouched
        ->and(WpState::$postMeta[4]['updated'])->toBe('2024-03-01 09:00:00') // the trash too
        ->and($report['posts'])->toBe(4)
        ->and(count($report['converted']))->toBe(3)
        ->and($report['current'])->toBe(1)
        ->and($report['empty'])->toBe(3)
        ->and($report['unreadable'])->toBe([['post' => 3, 'field' => 'updated', 'value' => 'whenever']])
        ->and(WpState::$options)->toHaveKey(DateMigration::DONE_OPTION);
});

it('writes nothing on a dry run, but reports what it would do', function () {
    ($this->answer)(1, ['updated' => 'Monday, 2024-01-01 01:02:03 PM']);

    $report = (new DateMigration())->run(true);

    expect(WpState::$postMeta[1]['updated'])->toBe('Monday, 2024-01-01 01:02:03 PM')
        ->and($report['converted'])->toBe([[
            'post' => 1, 'field' => 'updated', 'from' => 'Monday, 2024-01-01 01:02:03 PM', 'to' => '2024-01-01 13:02:03',
        ]])
        ->and(WpState::$options)->not->toHaveKey(DateMigration::DONE_OPTION);
});

it('changes nothing when run a second time', function () {
    ($this->answer)(1, ['updated' => 'Monday, 2024-01-01 01:02:03 PM']);
    (new DateMigration())->run(false);

    $again = (new DateMigration())->run(false);

    expect($again['converted'])->toBe([])
        ->and($again['current'])->toBe(1);
});

describe('wp confur migrate-dates', function () {
    it('lists each change and sums up, without writing on --dry-run', function () {
        ($this->answer)(7, ['updated' => 'Monday, 2024-01-01 01:02:03 PM', 'completed' => 'oops']);

        (new MigrateDatesCommand())([], ['dry-run' => true]);

        expect(\WP_CLI::$printed)->toBe([
            ['log', 'post 7 updated: "Monday, 2024-01-01 01:02:03 PM" -> "2024-01-01 13:02:03"'],
            ['warning', 'post 7 completed: "oops" is not a time this can read; left as it is'],
            ['success', 'Dry run, nothing written. 1 answer posts: 1 would be converted, 0 already in the new format, 0 empty, 1 unreadable.'],
        ])
            ->and(WpState::$postMeta[7]['updated'])->toBe('Monday, 2024-01-01 01:02:03 PM');
    });

    it('converts when run for real', function () {
        ($this->answer)(8, ['updated' => 'Monday, 2024-01-01 01:02:03 PM']);

        (new MigrateDatesCommand())([], []);

        expect(end(\WP_CLI::$printed))->toBe(['success', '1 answer posts: 1 converted, 0 already in the new format, 1 empty, 0 unreadable.'])
            ->and(WpState::$postMeta[8]['updated'])->toBe('2024-01-01 13:02:03');
    });
});
