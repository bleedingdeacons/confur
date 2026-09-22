<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Confur\Admin\ResultAdminPage;
use Confur\Config\Constants;
use ReflectionMethod;

/*
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
 */

covers(ResultAdminPage::class);

const RESULT_HOOK = 'questions-for-conference_page_confur-reporting';

/** @param array<string, mixed> $answers */
function resultAnswerTable(ResultAdminPage $page, array $answers): string
{
    $m = new ReflectionMethod(ResultAdminPage::class, 'generateAnswerTable');

    return (string) $m->invoke($page, $answers);
}

/** @param array<string, mixed> $answers */
function resultCoverageTable(ResultAdminPage $page, array $answers): string
{
    $m = new ReflectionMethod(ResultAdminPage::class, 'generateCoverageTable');

    return (string) $m->invoke($page, $answers);
}

/**
 * One answer row in the shape AnswerRepository::getGroupAnswers() returns.
 *
 * @return array<string, mixed>
 */
function resultRow(string $answer, string $status = Constants::STATUS_COMPLETED, array $overrides = []): array
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

/** @return array<int, string> */
function resultAnchorOrder(string $html): array
{
    preg_match_all('/href="(#c\d+_a\d+)"/', $html, $matches);

    return $matches[1];
}

beforeEach(function () {
    $this->page = new ResultAdminPage();

    /**
     * Seed one answer post: the metadata the report reads, plus the committee
     * answer fields keyed the way ACF stores them.
     *
     * @param array<string, string> $answers Field name => answer text
     */
    $this->seedAnswer = function (
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
    };
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('registers the menu and the assets from init', function () {
        $this->page->init();

        $this->assertActionAdded('admin_menu', false, 'the menu should be registered');
        $this->assertActionAdded('admin_enqueue_scripts', false, 'the assets should be registered');
    });

    // The report is readable by anyone who can reach wp-admin, unlike the
    // settings screens — the capability is 'read', not 'manage_options'.
    it('adds the page under the Confur menu for any logged-in user', function () {
        $this->page->registerAdminPage();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0]['parent'])->toBe('confur')
            ->and(WpState::$menus[0]['slug'])->toBe('confur-reporting')
            ->and(WpState::$menus[0]['cap'])->toBe('read');
    });

    it('only loads the report assets on this screen', function () {
        $this->page->enqueueAdminAssets('edit.php');

        expect(WpState::$enqueued)->toBe([]);
    });

    it('registers the report styles and scripts inline on this screen', function () {
        $this->page->enqueueAdminAssets(RESULT_HOOK);

        expect(array_column(WpState::$enqueued, 'fn'))->toBe([
            'wp_register_style',
            'wp_enqueue_style',
            'wp_add_inline_style',
            'wp_register_script',
            'wp_enqueue_script',
            'wp_add_inline_script',
        ]);
        foreach (WpState::$enqueued as $call) {
            expect($call['handle'])->toBe('confur-reporting-admin');
        }
    });
});

// ── the screen ────────────────────────────────────────────────────
describe('the screen', function () {
    it('refuses a user without the capability', function () {
        WpState::$userCan = false;

        $this->page->renderPage();
    })->throws(WpDieException::class);

    it('renders its three sections and its controls', function () {
        $html = captureOutput(fn () => $this->page->renderPage());

        expect($html)->toContain(
            'id="answer_table"',
            'id="coverage"',
            'id="answer_links"',
            'Print Report',
            'confurReportingRefresh()',
            'Report generated:',
        );
    });

    it('renders the answers it is given', function () {
        $this->makePost(500, 'Monday Group');
        ($this->seedAnswer)(1, 500, ['c1_a1' => 'A complete answer']);

        $html = captureOutput(fn () => $this->page->renderPage());

        expect($html)->toContain('A complete answer', 'Monday Group', 'group@example.org');
    });
});

// ── the navigation table ──────────────────────────────────────────
describe('the navigation table', function () {
    // The navigation table is a fixed shape: committees 1-6 by name with 3, 2,
    // 2, 2, 2 and 2 questions, then committee 7 rendered as "All Committees"
    // with one.
    it('lists every committee and question', function () {
        $m = new ReflectionMethod(ResultAdminPage::class, 'generateLinksTable');
        $html = (string) $m->invoke($this->page);

        expect($html)->toContain(
            '<strong>Committee 1</strong>',
            '<strong>Committee 6</strong>',
            '<strong>All Committees</strong>',
        )
            ->not->toContain('<strong>Committee 7</strong>')
            // Committee 1 has three questions, the rest two, the last one.
            ->toContain('<a href="#c1_a3">Answer 3</a>')
            ->not->toContain('#c2_a3')
            ->toContain('<a href="#c7_a1">Answer 1</a>')
            ->not->toContain('#c7_a2');
    });
});

// ── the answer table ──────────────────────────────────────────────
describe('the answer table', function () {
    it('orders and labels committees', function () {
        $html = resultAnswerTable($this->page, [
            'c2_a1' => [resultRow('Second')],
            'c1_a1' => [resultRow('First')],
        ]);

        expect(strpos($html, 'Committee 1'))->toBeLessThan(
            strpos($html, 'Committee 2'),
            'committee 1 should come first'
        );
    });

    // Committee 7 is the "last question", asked of every committee, so it is
    // labelled differently from the numbered ones.
    it('labels committee seven All Committees', function () {
        $html = resultAnswerTable($this->page, ['c7_a1' => [resultRow('The last question')]]);

        expect($html)->toContain('All Committees')
            ->not->toContain('Committee 7 <');
    });

    // The anchor is what the navigation links and the dashboard widget jump
    // to, so it must appear exactly once per question however many groups
    // answered it.
    it('anchors each question exactly once', function () {
        $html = resultAnswerTable($this->page, [
            'c1_a1' => [
                resultRow('First group'),
                resultRow('Second group', Constants::STATUS_COMPLETED, ['meetingName' => 'Tuesday Group']),
            ],
        ]);

        expect(substr_count($html, "id='c1_a1'"))->toBe(1)
            ->and($html)->toContain('First group', 'Second group');
    });

    // Only draft and completed answers belong in the report — a cancelled
    // registration's text must not appear.
    it('reports only draft and completed answers', function () {
        $html = resultAnswerTable($this->page, [
            'c1_a1' => [
                resultRow('A completed answer', Constants::STATUS_COMPLETED),
                resultRow('A draft answer', Constants::STATUS_DRAFT),
                resultRow('A cancelled answer', Constants::STATUS_CANCELLED),
                resultRow('An unstarted answer', ''),
            ],
        ]);

        expect($html)->toContain('A completed answer', 'A draft answer')
            ->not->toContain('A cancelled answer')
            ->not->toContain('An unstarted answer');
    });

    // A paired registration answers on behalf of two groups, and the header
    // has to name both so the report is not read as one group's answer.
    it('names both meetings in the header of a paired registration', function () {
        $this->makePost(501, 'Tuesday Group');

        $html = resultAnswerTable($this->page, [
            'c1_a1' => [resultRow('A shared answer', Constants::STATUS_COMPLETED, ['fellowMeetingId' => 501])],
        ]);

        expect($html)->toContain('Monday Group & Tuesday Group');
    });

    it('falls back to the primary name when the fellow meeting no longer exists', function () {
        $html = resultAnswerTable($this->page, [
            'c1_a1' => [resultRow('A shared answer', Constants::STATUS_COMPLETED, ['fellowMeetingId' => 999])],
        ]);

        expect($html)->toContain('Monday Group - Complete');
    });

    it('does not repeat a fellow meeting equal to the primary', function () {
        $html = resultAnswerTable($this->page, [
            'c1_a1' => [resultRow('An answer', Constants::STATUS_COMPLETED, ['fellowMeetingId' => 500])],
        ]);

        expect($html)->toContain('Monday Group - Complete')
            // the same meeting should not be named twice
            ->not->toContain(' & ');
    });

    it('still renders a table for an empty report', function () {
        $html = resultAnswerTable($this->page, []);

        expect($html)->toContain('<table id="all_answers"', '</table>');
    });
});

// ── the coverage table ────────────────────────────────────────────
describe('the coverage table', function () {
    // The coverage table is the only arithmetic on the page: response count,
    // mean word count to two places, and the shortest and longest answers.
    it('counts responses and words', function () {
        $html = resultCoverageTable($this->page, [
            'c1_a1' => [
                resultRow('one two three'),
                resultRow('one two three four five six'),
            ],
        ]);

        expect($html)->toContain(
            '<td>Committee 1</td>',
            '<td>2</td>',   // two responses
            '<td>4.5</td>', // the mean of 3 and 6
            '<td>3</td>',   // the shortest
            '<td>6</td>',   // the longest
        );
    });

    // Cancelled answers are excluded from the answer table but not from the
    // coverage arithmetic, which counts every response the repository
    // returned. Asserted as-is: this change covers the page, it does not
    // change what it reports.
    it('counts every response including cancelled ones', function () {
        $html = resultCoverageTable($this->page, [
            'c1_a1' => [
                resultRow('one two three', Constants::STATUS_COMPLETED),
                resultRow('four five six', Constants::STATUS_CANCELLED),
            ],
        ]);

        expect($html)->toContain('<td>2</td>');
    });

    it('links each row to its question anchor', function () {
        $html = resultCoverageTable($this->page, ['c3_a2' => [resultRow('An answer')]]);

        expect($html)->toContain('<a href="#c3_a2">Answer 2</a>');
    });

    // Rows are sorted by committee then by question, so the table reads in the
    // same order as the report above it.
    it('sorts rows by committee then question', function () {
        $html = resultCoverageTable($this->page, [
            'c2_a1'  => [resultRow('b')],
            'c1_a2'  => [resultRow('a')],
            'c1_a1'  => [resultRow('a')],
        ]);

        expect(resultAnchorOrder($html))->toBe(['#c1_a1', '#c1_a2', '#c2_a1']);
    });

    it('still renders its header row when empty', function () {
        $html = resultCoverageTable($this->page, []);

        expect($html)->toContain('<th>Committee</th>', '<th>Highest Word Count</th>');
    });
});
