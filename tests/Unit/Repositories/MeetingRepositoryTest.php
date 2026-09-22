<?php

namespace Tests\Unit\Repositories;

use BleedingDeacons\WpMocks\WpState;
use Confur\Repositories\MeetingRepository;

covers(MeetingRepository::class);

beforeEach(function () {
    WpState::$queryPosts = [];
    $this->repo = new MeetingRepository();
});

it('maps posts and meta in getMeetings', function () {
    WpState::$queryPosts = [
        (object) ['ID' => 10, 'post_type' => 'tsml_meeting', 'post_title' => 'Monday Group', 'post_name' => 'monday', 'post_parent' => 99],
        (object) ['ID' => 11, 'post_type' => 'tsml_meeting', 'post_title' => 'Online Group', 'post_name' => 'online', 'post_parent' => 0],
    ];
    WpState::$postMeta = [
        10 => ['day' => ['1'], 'time' => ['19:00'], 'end_time' => ['20:00'], 'types' => [serialize(['IPM'])]],
        11 => ['day' => ['3'], 'time' => ['18:00'], 'types' => [serialize(['ONL'])]],
    ];
    $this->seedTitles([99 => 'Church Hall']);
    $this->seedFields([10 => ['allocated_committee' => '3']]);

    $meetings = $this->repo->getMeetings();

    // array_reverse — the online meeting (id 11) comes first.
    expect($meetings)->toHaveCount(2)
        ->and($meetings[0]['id'])->toBe(11)
        ->and($meetings[0]['online'])->toBeTrue()
        ->and($meetings[1]['location'])->toBe('Church Hall')
        ->and($meetings[1]['online'])->toBeFalse()
        ->and($meetings[1]['allocated'])->toBe('3')
        ->and($meetings[1]['time'])->toBe('19:00');
});

it('handles corrupt types meta in getMeetings', function () {
    WpState::$queryPosts = [
        (object) ['ID' => 20, 'post_type' => 'tsml_meeting', 'post_title' => 'G', 'post_name' => 'g', 'post_parent' => 0],
    ];
    // A non-array unserialize result must be coerced back to an empty array.
    WpState::$postMeta = [20 => ['types' => [serialize('a-plain-string')]]];

    $meetings = $this->repo->getMeetings();
    expect($meetings[0]['online'])->toBeFalse();
});

it('collects populated rows in getMeetingContacts', function () {
    WpState::$postMeta = [
        30 => [
            'contact_1_name'  => ['Alice'],
            'contact_1_phone' => ['0111'],
            'contact_1_email' => ['a@b.com'],
            'contact_3_name'  => ['Carol'],
        ],
    ];

    $contacts = $this->repo->getMeetingContacts(30);
    expect($contacts)->toHaveCount(2)
        ->and($contacts[0]['name'])->toBe('Alice')
        ->and($contacts[0]['phone'])->toBe('0111')
        ->and($contacts[1]['name'])->toBe('Carol')
        ->and($contacts[1]['phone'])->toBe('');
});

it('returns no meeting contacts when none are named', function () {
    WpState::$postMeta = [40 => []];
    expect($this->repo->getMeetingContacts(40))->toBe([]);
});
