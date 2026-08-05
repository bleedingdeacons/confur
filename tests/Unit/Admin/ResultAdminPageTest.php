<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Confur\Admin\ResultAdminPage;
use Confur\Config\Constants;
use ReflectionMethod;
use Tests\ConfurTestCase;

/**
 * Tests for the results report screen.
 *
 * This is the least glue-like page in the layer: it groups every submitted
 * answer by committee and question, tracks anchor ids so each question is
 * linkable exactly once, and computes a coverage table of response and word
 * counts. All of that is private behind renderPage(), so the aggregation is
 * driven through reflection and the markup is checked separately by capturing
 * the render.
 *
 * The page builds its own AnswerRepository, so answers are seeded into WpState
 * and the real repository reads them.
 *
 * @covers \Confur\Admin\ResultAdminPage
 */
final class ResultAdminPageTest extends ConfurTestCase
{
    private const HOOK = 'questions-for-conference_page_confur-reporting';

    private ResultAdminPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = new ResultAdminPage();
    }

    /**
     * Seed one answer post: the metadata the report reads, plus the committee
     * answer fields keyed the way ACF stores them.
     *
     * @param array<string, string> $answers Field name => answer text
     */
    private function seedAnswer(
        int $postId,
        int $meetingId,
        array $answers,
        string $status = Constants::STATUS_COMPLETED,
        ?int $fellowMeetingId = null
    ): void {
        WpState::$queryPosts[] = (object) [
            'ID'          => $postId,
            'post_type'   => Constants::ANSWER_CUSTOM_TYPE,
            'post_status' => 'publish',
        ];
        WpState::$postStatuses[$postId] = 'publish';

        $this->fields[$postId] = array_merge([
            Constants::UPDATED_FIELD        => '2026-07-24 10:00:00',
            Constants::MEETING_FIELD        => $meetingId,
            Constants::FELLOW_MEETING_FIELD => $fellowMeetingId,
            Constants::EMAIL_FIELD          => 'group@example.org',
            Constants::STATUS_FIELD         => $status,
        ], $answers);
    }

    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @param array<string, mixed> $answers */
    private function answerTable(array $answers): string
    {
        $m = new ReflectionMethod(ResultAdminPage::class, 'generateAnswerTable');

        return (string) $m->invoke($this->page, $answers);
    }

    /** @param array<string, mixed> $answers */
    private function coverageTable(array $answers): string
    {
        $m = new ReflectionMethod(ResultAdminPage::class, 'generateCoverageTable');

        return (string) $m->invoke($this->page, $answers);
    }

    /**
     * One answer row in the shape AnswerRepository::getGroupAnswers() returns.
     *
     * @return array<string, mixed>
     */
    private function row(string $answer, string $status = Constants::STATUS_COMPLETED, array $overrides = []): array
    {
        return array_merge([
            'meetingId'       => 500,
            'fellowMeetingId' => null,
            'meetingName'     => 'Monday Group',
            'resultUrl'       => 'https://example.test/?p=1',
            'email'           => 'group@example.org',
            'updated'         => '2026-07-24 10:00:00',
            'answer'          => $answer,
            'status'          => $status,
        ], $overrides);
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_registers_the_menu_and_the_assets(): void
    {
        $this->page->init();

        $this->assertActionAdded('admin_menu', false, 'the menu should be registered');
        $this->assertActionAdded('admin_enqueue_scripts', false, 'the assets should be registered');
    }

    /**
     * The report is readable by anyone who can reach wp-admin, unlike the
     * settings screens — the capability is 'read', not 'manage_options'.
     *
     * @test
     */
    public function the_page_is_added_under_the_confur_menu_for_any_logged_in_user(): void
    {
        $this->page->registerAdminPage();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame('confur', WpState::$menus[0]['parent']);
        $this->assertSame('confur-reporting', WpState::$menus[0]['slug']);
        $this->assertSame('read', WpState::$menus[0]['cap']);
    }

    /** @test */
    public function the_report_assets_are_only_loaded_on_this_screen(): void
    {
        $this->page->enqueueAdminAssets('edit.php');

        $this->assertSame([], WpState::$enqueued);
    }

    /** @test */
    public function the_report_styles_and_scripts_are_registered_inline_on_this_screen(): void
    {
        $this->page->enqueueAdminAssets(self::HOOK);

        $this->assertSame(
            [
                'wp_register_style',
                'wp_enqueue_style',
                'wp_add_inline_style',
                'wp_register_script',
                'wp_enqueue_script',
                'wp_add_inline_script',
            ],
            array_column(WpState::$enqueued, 'fn')
        );
        foreach (WpState::$enqueued as $call) {
            $this->assertSame('confur-reporting-admin', $call['handle']);
        }
    }

    // ── the screen ────────────────────────────────────────────────────

    /** @test */
    public function the_screen_refuses_a_user_without_the_capability(): void
    {
        WpState::$userCan = false;

        $this->expectException(WpDieException::class);
        $this->page->renderPage();
    }

    /** @test */
    public function the_screen_renders_its_three_sections_and_its_controls(): void
    {
        $html = $this->capture(fn () => $this->page->renderPage());

        $this->assertStringContainsString('id="answer_table"', $html);
        $this->assertStringContainsString('id="coverage"', $html);
        $this->assertStringContainsString('id="answer_links"', $html);
        $this->assertStringContainsString('Print Report', $html);
        $this->assertStringContainsString('confurReportingRefresh()', $html);
        $this->assertStringContainsString('Report generated:', $html);
    }

    /** @test */
    public function the_screen_renders_the_answers_it_is_given(): void
    {
        $this->makePost(500, 'Monday Group');
        $this->seedAnswer(1, 500, ['c1_a1' => 'A complete answer']);

        $html = $this->capture(fn () => $this->page->renderPage());

        $this->assertStringContainsString('A complete answer', $html);
        $this->assertStringContainsString('Monday Group', $html);
        $this->assertStringContainsString('group@example.org', $html);
    }

    // ── the navigation table ──────────────────────────────────────────

    /**
     * The navigation table is a fixed shape: committees 1-6 by name with 3, 2,
     * 2, 2, 2 and 2 questions, then committee 7 rendered as "All Committees"
     * with one.
     *
     * @test
     */
    public function the_navigation_table_lists_every_committee_and_question(): void
    {
        $m = new ReflectionMethod(ResultAdminPage::class, 'generateLinksTable');
        $html = (string) $m->invoke($this->page);

        $this->assertStringContainsString('<strong>Committee 1</strong>', $html);
        $this->assertStringContainsString('<strong>Committee 6</strong>', $html);
        $this->assertStringContainsString('<strong>All Committees</strong>', $html);
        $this->assertStringNotContainsString('<strong>Committee 7</strong>', $html);

        // Committee 1 has three questions, the rest two, the last one.
        $this->assertStringContainsString('<a href="#c1_a3">Answer 3</a>', $html);
        $this->assertStringNotContainsString('#c2_a3', $html);
        $this->assertStringContainsString('<a href="#c7_a1">Answer 1</a>', $html);
        $this->assertStringNotContainsString('#c7_a2', $html);
    }

    // ── the answer table ──────────────────────────────────────────────

    /** @test */
    public function committees_are_ordered_and_labelled(): void
    {
        $html = $this->answerTable([
            'c2_a1' => [$this->row('Second')],
            'c1_a1' => [$this->row('First')],
        ]);

        $this->assertLessThan(
            strpos($html, 'Committee 2'),
            strpos($html, 'Committee 1'),
            'committee 1 should come first'
        );
    }

    /**
     * Committee 7 is the "last question", asked of every committee, so it is
     * labelled differently from the numbered ones.
     *
     * @test
     */
    public function committee_seven_is_labelled_all_committees(): void
    {
        $html = $this->answerTable(['c7_a1' => [$this->row('The last question')]]);

        $this->assertStringContainsString('All Committees', $html);
        $this->assertStringNotContainsString('Committee 7 <', $html);
    }

    /**
     * The anchor is what the navigation links and the dashboard widget jump
     * to, so it must appear exactly once per question however many groups
     * answered it.
     *
     * @test
     */
    public function each_question_is_anchored_exactly_once(): void
    {
        $html = $this->answerTable([
            'c1_a1' => [
                $this->row('First group'),
                $this->row('Second group', Constants::STATUS_COMPLETED, ['meetingName' => 'Tuesday Group']),
            ],
        ]);

        $this->assertSame(1, substr_count($html, "id='c1_a1'"));
        $this->assertStringContainsString('First group', $html);
        $this->assertStringContainsString('Second group', $html);
    }

    /**
     * Only draft and completed answers belong in the report — a cancelled
     * registration's text must not appear.
     *
     * @test
     */
    public function only_draft_and_completed_answers_are_reported(): void
    {
        $html = $this->answerTable([
            'c1_a1' => [
                $this->row('A completed answer', Constants::STATUS_COMPLETED),
                $this->row('A draft answer', Constants::STATUS_DRAFT),
                $this->row('A cancelled answer', Constants::STATUS_CANCELLED),
                $this->row('An unstarted answer', ''),
            ],
        ]);

        $this->assertStringContainsString('A completed answer', $html);
        $this->assertStringContainsString('A draft answer', $html);
        $this->assertStringNotContainsString('A cancelled answer', $html);
        $this->assertStringNotContainsString('An unstarted answer', $html);
    }

    /**
     * A paired registration answers on behalf of two groups, and the header
     * has to name both so the report is not read as one group's answer.
     *
     * @test
     */
    public function a_paired_registration_names_both_meetings_in_its_header(): void
    {
        $this->makePost(501, 'Tuesday Group');

        $html = $this->answerTable([
            'c1_a1' => [$this->row('A shared answer', Constants::STATUS_COMPLETED, ['fellowMeetingId' => 501])],
        ]);

        $this->assertStringContainsString('Monday Group & Tuesday Group', $html);
    }

    /** @test */
    public function a_fellow_meeting_that_no_longer_exists_falls_back_to_the_primary_name(): void
    {
        $html = $this->answerTable([
            'c1_a1' => [$this->row('A shared answer', Constants::STATUS_COMPLETED, ['fellowMeetingId' => 999])],
        ]);

        $this->assertStringContainsString('Monday Group - Complete', $html);
    }

    /** @test */
    public function a_fellow_meeting_equal_to_the_primary_is_not_repeated(): void
    {
        $html = $this->answerTable([
            'c1_a1' => [$this->row('An answer', Constants::STATUS_COMPLETED, ['fellowMeetingId' => 500])],
        ]);

        $this->assertStringContainsString('Monday Group - Complete', $html);
        $this->assertStringNotContainsString(' & ', $html, 'the same meeting should not be named twice');
    }

    /** @test */
    public function an_empty_report_still_renders_a_table(): void
    {
        $html = $this->answerTable([]);

        $this->assertStringContainsString('<table id="all_answers"', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    // ── the coverage table ────────────────────────────────────────────

    /**
     * The coverage table is the only arithmetic on the page: response count,
     * mean word count to two places, and the shortest and longest answers.
     *
     * @test
     */
    public function the_coverage_table_counts_responses_and_words(): void
    {
        $html = $this->coverageTable([
            'c1_a1' => [
                $this->row('one two three'),
                $this->row('one two three four five six'),
            ],
        ]);

        $this->assertStringContainsString('<td>Committee 1</td>', $html);
        $this->assertStringContainsString('<td>2</td>', $html, 'two responses');
        $this->assertStringContainsString('<td>4.5</td>', $html, 'the mean of 3 and 6');
        $this->assertStringContainsString('<td>3</td>', $html, 'the shortest');
        $this->assertStringContainsString('<td>6</td>', $html, 'the longest');
    }

    /**
     * Cancelled answers are excluded from the answer table but not from the
     * coverage arithmetic, which counts every response the repository
     * returned. Asserted as-is: this change covers the page, it does not
     * change what it reports.
     *
     * @test
     */
    public function the_coverage_table_counts_every_response_including_cancelled_ones(): void
    {
        $html = $this->coverageTable([
            'c1_a1' => [
                $this->row('one two three', Constants::STATUS_COMPLETED),
                $this->row('four five six', Constants::STATUS_CANCELLED),
            ],
        ]);

        $this->assertStringContainsString('<td>2</td>', $html);
    }

    /** @test */
    public function each_coverage_row_links_to_its_question_anchor(): void
    {
        $html = $this->coverageTable(['c3_a2' => [$this->row('An answer')]]);

        $this->assertStringContainsString('<a href="#c3_a2">Answer 2</a>', $html);
    }

    /**
     * Rows are sorted by committee then by question, so the table reads in the
     * same order as the report above it.
     *
     * @test
     */
    public function coverage_rows_are_sorted_by_committee_then_question(): void
    {
        $html = $this->coverageTable([
            'c2_a1'  => [$this->row('b')],
            'c1_a2'  => [$this->row('a')],
            'c1_a1'  => [$this->row('a')],
        ]);

        $this->assertSame(
            ['#c1_a1', '#c1_a2', '#c2_a1'],
            $this->anchorOrder($html)
        );
    }

    /** @test */
    public function an_empty_coverage_table_still_renders_its_header_row(): void
    {
        $html = $this->coverageTable([]);

        $this->assertStringContainsString('<th>Committee</th>', $html);
        $this->assertStringContainsString('<th>Highest Word Count</th>', $html);
    }

    /** @return array<int, string> */
    private function anchorOrder(string $html): array
    {
        preg_match_all('/href="(#c\d+_a\d+)"/', $html, $matches);

        return $matches[1];
    }
}
