<?php

namespace Tests\Unit\Repositories;

use Confur\Repositories\MeetingRepository;
use BleedingDeacons\WpMocks\WpState;
use Tests\ConfurTestCase;

/**
 * @covers \Confur\Repositories\MeetingRepository
 */
class MeetingRepositoryTest extends ConfurTestCase
{
    private MeetingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        WpState::$queryPosts = [];
        $this->repo = new MeetingRepository();
    }

    public function testGetMeetingsMapsPostsAndMeta(): void
    {
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
        $this->assertCount(2, $meetings);
        $this->assertSame(11, $meetings[0]['id']);
        $this->assertTrue($meetings[0]['online']);
        $this->assertSame('Church Hall', $meetings[1]['location']);
        $this->assertFalse($meetings[1]['online']);
        $this->assertSame('3', $meetings[1]['allocated']);
        $this->assertSame('19:00', $meetings[1]['time']);
    }

    public function testGetMeetingsHandlesCorruptTypesMeta(): void
    {
        WpState::$queryPosts = [
            (object) ['ID' => 20, 'post_type' => 'tsml_meeting', 'post_title' => 'G', 'post_name' => 'g', 'post_parent' => 0],
        ];
        // A non-array unserialize result must be coerced back to an empty array.
        WpState::$postMeta = [20 => ['types' => [serialize('a-plain-string')]]];

        $meetings = $this->repo->getMeetings();
        $this->assertFalse($meetings[0]['online']);
    }

    public function testGetMeetingContactsCollectsPopulatedRows(): void
    {
        WpState::$postMeta = [
            30 => [
                'contact_1_name'  => ['Alice'],
                'contact_1_phone' => ['0111'],
                'contact_1_email' => ['a@b.com'],
                'contact_3_name'  => ['Carol'],
            ],
        ];

        $contacts = $this->repo->getMeetingContacts(30);
        $this->assertCount(2, $contacts);
        $this->assertSame('Alice', $contacts[0]['name']);
        $this->assertSame('0111', $contacts[0]['phone']);
        $this->assertSame('Carol', $contacts[1]['name']);
        $this->assertSame('', $contacts[1]['phone']);
    }

    public function testGetMeetingContactsEmptyWhenNoneNamed(): void
    {
        WpState::$postMeta = [40 => []];
        $this->assertSame([], $this->repo->getMeetingContacts(40));
    }
}
