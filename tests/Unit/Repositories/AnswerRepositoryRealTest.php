<?php

namespace Tests\Unit\Repositories;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real AnswerRepository methods (getValue, getAnswerStatus,
 * getAllAnswers, getRegisteredGroups, findDuplicate, getGroupAnswers) against
 * the controllable ACF/post stubs — as opposed to AnswerRepositoryTest, which
 * drives a re-implemented findDuplicate.
 *
 * @covers \Confur\Repositories\AnswerRepository
 */
class AnswerRepositoryRealTest extends TestCase
{
    private AnswerRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['confur_posts'] = [];
        $GLOBALS['confur_fields'] = [];
        $GLOBALS['confur_allfields'] = [];
        $GLOBALS['confur_poststatus'] = [];
        $this->repo = new AnswerRepository();
    }

    /** Register an answer post plus its ACF fields. */
    private function answer(int $id, array $fields, string $date = '2024-01-01 00:00:00'): void
    {
        $GLOBALS['confur_posts'][] = (object) [
            'ID' => $id,
            'post_type' => 'answer',
            'post_name' => "post-{$id}",
            'post_date' => $date,
        ];
        $GLOBALS['confur_fields'][$id] = $fields;
    }

    public function testGetValueSanitises(): void
    {
        $GLOBALS['confur_fields'][0]['c1_a1'] = "  <b>hi</b>  ";
        $this->assertSame('hi', $this->repo->getValue('c1_a1'));
    }

    public function testGetAnswerStatusReturnsExisting(): void
    {
        $GLOBALS['confur_fields'][5] = [Constants::STATUS_FIELD => 'Complete', Constants::UPDATED_FIELD => '2024-05-01'];
        $status = $this->repo->getAnswerStatus(5);
        $this->assertSame('Complete', $status['state']);
        $this->assertSame('2024-05-01', $status['updated']);
    }

    public function testGetAnswerStatusInitialisesWhenEmpty(): void
    {
        $GLOBALS['confur_fields'][6] = [];
        $status = $this->repo->getAnswerStatus(6);
        // update_field writes the draft status, which get_field then reads back.
        $this->assertSame(Constants::STATUS_DRAFT, $status['state']);
        $this->assertSame('N/A', $status['updated']);
    }

    public function testGetAllAnswersReturnsIds(): void
    {
        $this->answer(1, []);
        $this->answer(2, []);
        $this->assertSame([1, 2], array_values($this->repo->getAllAnswers()));
    }

    public function testGetRegisteredGroupsIncludesOnlyPostsWithAMeeting(): void
    {
        $this->answer(1, [Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'a@b.com']);
        $this->answer(2, [Constants::MEETING_FIELD => null, Constants::EMAIL_FIELD => 'c@d.com']);

        $groups = $this->repo->getRegisteredGroups();
        $this->assertCount(1, $groups);
        $this->assertSame(100, $groups[0]['meetingId']);
        $this->assertSame('a@b.com', $groups[0]['email']);
    }

    public function testGetRegisteredGroupsNormalisesVariedMeetingShapes(): void
    {
        $this->answer(1, [Constants::MEETING_FIELD => (object) ['ID' => 10], Constants::EMAIL_FIELD => 'a@b.com']);
        $this->answer(2, [Constants::MEETING_FIELD => ['ID' => 20], Constants::EMAIL_FIELD => 'b@b.com']);
        $this->answer(3, [Constants::MEETING_FIELD => 'not-an-id', Constants::EMAIL_FIELD => 'c@b.com']);

        $groups = $this->repo->getRegisteredGroups();

        // Object and array meetings normalise to their ID; the bogus string
        // yields null and is dropped.
        $ids = array_column($groups, 'meetingId');
        $this->assertContains(10, $ids);
        $this->assertContains(20, $ids);
        $this->assertCount(2, $groups);
    }

    // ── findDuplicate (real) ─────────────────────────────────────────────

    public function testFindDuplicateReturnsNullForEmptyInputs(): void
    {
        $this->assertNull($this->repo->findDuplicate(null, null, 'x@y.com'));
        $this->assertNull($this->repo->findDuplicate(100, null, ''));
    }

    public function testFindDuplicateMatchesSingleRegistration(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'TEST@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);

        $result = $this->repo->findDuplicate(100, null, 'test@example.com', 999);
        $this->assertNotNull($result);
        $this->assertSame(1, $result['post_id']);
        $this->assertSame('post-1', $result['slug']);
    }

    public function testFindDuplicateSkipsCancelled(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_CANCELLED,
        ]);
        $this->assertNull($this->repo->findDuplicate(100, null, 'test@example.com', 999));
    }

    public function testFindDuplicateMatchesPairedInSwappedOrder(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 200,
            Constants::FELLOW_MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        $result = $this->repo->findDuplicate(100, 200, 'test@example.com', 999);
        $this->assertSame(1, $result['post_id']);
    }

    public function testFindDuplicateDoesNotMatchPairedWithSingle(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => null,
            Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        $this->assertNull($this->repo->findDuplicate(100, 200, 'test@example.com', 999));
    }

    public function testFindDuplicateReturnsLatestByUpdated(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT, Constants::UPDATED_FIELD => '2024-01-01 10:00:00',
        ]);
        $this->answer(2, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT, Constants::UPDATED_FIELD => '2024-06-01 10:00:00',
        ]);
        $result = $this->repo->findDuplicate(100, null, 'test@example.com', 999);
        $this->assertSame(2, $result['post_id']);
    }

    public function testFindDuplicateFallsBackToPostDateWhenNoUpdated(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ], '2024-01-01 09:00:00');
        $this->answer(2, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ], '2024-09-01 09:00:00');
        $result = $this->repo->findDuplicate(100, null, 'test@example.com', 999);
        $this->assertSame(2, $result['post_id']);
    }

    public function testFindDuplicateSkipsDifferentEmail(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'other@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        $this->assertNull($this->repo->findDuplicate(100, null, 'test@example.com', 999));
    }

    public function testFindDuplicateSkipsDifferentMeeting(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 555, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        $this->assertNull($this->repo->findDuplicate(100, null, 'test@example.com', 999));
    }

    public function testFindDuplicateExcludesGivenPost(): void
    {
        $this->answer(5, [
            Constants::MEETING_FIELD => 100, Constants::EMAIL_FIELD => 'test@example.com',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        $this->assertNull($this->repo->findDuplicate(100, null, 'test@example.com', 5));
    }

    // ── getGroupAnswers ──────────────────────────────────────────────────

    public function testGetGroupAnswersCollectsAnsweredCommitteeFields(): void
    {
        $this->answer(1, [
            Constants::MEETING_FIELD => 100,
            Constants::EMAIL_FIELD => 'a@b.com',
            Constants::UPDATED_FIELD => '2024-01-01',
            Constants::STATUS_FIELD => Constants::STATUS_DRAFT,
        ]);
        $GLOBALS['confur_allfields'][1] = [
            'c1_a1' => 'An answer',
            'c1_a2' => '',          // empty → skipped
            'other' => 'ignored',   // not c\d+_ → skipped
        ];
        $GLOBALS['confur_titles'] = [100 => 'Monday Group'];

        $answers = $this->repo->getGroupAnswers();
        $this->assertArrayHasKey('c1_a1', $answers);
        $this->assertArrayNotHasKey('c1_a2', $answers);
        $this->assertSame('Monday Group', $answers['c1_a1'][0]['meetingName']);
    }

    public function testGetGroupAnswersSkipsTrashedPosts(): void
    {
        $this->answer(1, [Constants::MEETING_FIELD => 100, Constants::UPDATED_FIELD => '2024-01-01']);
        $GLOBALS['confur_poststatus'][1] = 'trash';
        $GLOBALS['confur_allfields'][1] = ['c1_a1' => 'x'];

        $this->assertSame([], $this->repo->getGroupAnswers());
    }
}
