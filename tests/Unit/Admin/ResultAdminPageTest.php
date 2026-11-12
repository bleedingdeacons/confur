<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Confur\Admin\ResultAdminPage;
use Confur\Domain\QuestionSet;
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
function resultAnswerTable(ResultAdminPage $page, array $answers, ?QuestionSet $questions = null): string
{
    $m = new ReflectionMethod(ResultAdminPage::class, 'generateAnswerTable');

    return (string) $m->invoke($page, $questions ?? QuestionSet::current(), $answers);
}

/** @param array<string, mixed> $answers */
function resultCoverageTable(ResultAdminPage $page, array $answers, ?QuestionSet $questions = null): string
{
    $m = new ReflectionMethod(ResultAdminPage::class, 'generateCoverageTable');

    return (string) $m->invoke($page, $questions ?? QuestionSet::current(), $answers);
}

/**
 * One answer row in the shape AnswerRepository::getGroupAnswers() returns.
 *
 * @return array<string, mixed>
 */
function resultRow(string $answer, string $status = Constants::STATUS_COMPLETED, array $overrides = []): array
{
    return array_merge([
        'groupId'       => 500,
        'fellowGroupId' => null,
        'groupName'     => 'Monday Group',
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
        int $groupId,
        array $answers,
        string $status = Constants::STATUS_COMPLETED,
        ?int $fellowGroupId = null
    ): void {
        WpState::$queryPosts[] = (object) [
            'ID'          => $postId,
            'post_type'   => Constants::ANSWER_CUSTOM_TYPE,
            'post_status' => 'publish',
        ];
        WpState::$postStatuses[$postId] = 'publish';

        $this->fields[$postId] = array_merge([
            Constants::UPDATED_FIELD        => '2026-07-24 10:00:00',
            Constants::GROUP_FIELD        => $groupId,
            Constants::FELLOW_GROUP_FIELD => $fellowGroupId,
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

    it('loads the report styles and copy-button script on this screen', function () {
        $this->page->enqueueAdminAssets(RESULT_HOOK);

        expect(WpState::$enqueued)->toBe([
            ['fn' => 'wp_enqueue_style', 'handle' => 'confur-admin-results'],
            ['fn' => 'wp_enqueue_script', 'handle' => 'confur-admin-results'],
        ]);
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
        $html = (string) $m->invoke($this->page, QuestionSet::current());

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

    it('follows the question set', function () {
        \Brain\Monkey\Filters\expectApplied('confur_question_set')->andReturn([1 => 1, 2 => 4, 3 => 1]);

        $m = new ReflectionMethod(ResultAdminPage::class, 'generateLinksTable');
        $html = (string) $m->invoke($this->page, QuestionSet::current());

        // The filter dropped committee 7, which held the All Committees
        // question, so this set has none and committee 3 is just that.
        expect($html)->toContain('<a href="#c2_a4">Answer 4</a>', '<strong>Committee 2</strong>', '<strong>Committee 3</strong>')
            ->not->toContain('All Committees', '#c1_a2', 'c4_', 'c7_');
    });
});

// ── the answer table ──────────────────────────────────────────────
describe('the answer table', function () {
    // Answers and names are written by members, and this is an admin screen:
    // nothing they type may break out of its attribute or run as markup.
    it('escapes member-written answers, names and emails', function () {
        $escape = static fn (mixed $t): string => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        \Brain\Monkey\Functions\when('esc_html')->alias($escape);
        \Brain\Monkey\Functions\when('esc_attr')->alias($escape);

        $html = resultAnswerTable($this->page, [
            'c1_a1' => [resultRow("x' onmouseover='alert(1)<script>y</script>", Constants::STATUS_COMPLETED, [
                'groupName' => "Bad' Group<b>",
                'email' => '<i>a@b.c</i>',
            ])],
        ]);

        expect($html)->not->toContain('<script>', "onmouseover='", '<b>', '<i>')
            ->toContain('&lt;script&gt;', 'Bad&#039; Group&lt;b&gt;', '&lt;i&gt;a@b.c&lt;/i&gt;');
    });

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

    // Committee 7 holds the bundled "last question", asked of every
    // committee together. It is a section of its own, and named so throughout:
    // the header, its copy button, each answer, and what the buttons copy.
    it('gives the All Committees questions a section named for them', function () {
        $html = resultAnswerTable($this->page, ['c7_a1' => [resultRow('The last question')]]);

        expect($html)->toContain(
            "class='committee-header'>All Committees <button",
            '📋 Copy All Committees</button>',
            "<div id='committee_7' data-label='All Committees'>",
            '<strong>All Committees - Question 1</strong>',
            'The last question',
        )
            ->not->toContain('Committee 7');
    });

    it('names whichever committee holds them All Committees', function () {
        $html = resultAnswerTable($this->page, [
            'c1_a1' => [resultRow('Shared')],
            'c7_a1' => [resultRow('Seventh')],
        ], new QuestionSet([1 => 1, 7 => 1], 1));

        expect($html)->toContain('<strong>All Committees - Question 1</strong>', '<strong>Committee 7 - Question 1</strong>')
            ->not->toContain('Committee 1');
    });

    it('names every section a committee when the set has no All Committees questions', function () {
        $html = resultAnswerTable($this->page, ['c7_a1' => [resultRow('Just a committee')]], new QuestionSet([1 => 1, 7 => 1]));

        expect($html)->toContain(
            "class='committee-header'>Committee 7 <button",
            '📋 Copy Committee 7</button>',
            "data-label='Committee 7'",
            '<strong>Committee 7 - Question 1</strong>',
        )
            ->not->toContain('All Committees');
    });

    // The anchor is what the navigation links and the dashboard widget jump
    // to, so it must appear exactly once per question however many groups
    // answered it.
    it('anchors each question exactly once', function () {
        $html = resultAnswerTable($this->page, [
            'c1_a1' => [
                resultRow('First group'),
                resultRow('Second group', Constants::STATUS_COMPLETED, ['groupName' => 'Tuesday Group']),
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
    it('names both groups in the header of a paired registration', function () {
        $this->makePost(501, 'Tuesday Group');

        $html = resultAnswerTable($this->page, [
            'c1_a1' => [resultRow('A shared answer', Constants::STATUS_COMPLETED, ['fellowGroupId' => 501])],
        ]);

        // Escaped: the browser shows it as 'Monday Group & Tuesday Group'.
        expect($html)->toContain('Monday Group &amp; Tuesday Group');
    });

    it('falls back to the primary name when the fellow group no longer exists', function () {
        $html = resultAnswerTable($this->page, [
            'c1_a1' => [resultRow('A shared answer', Constants::STATUS_COMPLETED, ['fellowGroupId' => 999])],
        ]);

        expect($html)->toContain('Monday Group - Complete');
    });

    it('does not repeat a fellow meeting equal to the primary', function () {
        $html = resultAnswerTable($this->page, [
            'c1_a1' => [resultRow('An answer', Constants::STATUS_COMPLETED, ['fellowGroupId' => 500])],
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

    it('names the All Committees questions in the coverage table', function () {
        $html = resultCoverageTable($this->page, [
            'c7_a1' => [resultRow('one two')],
            'c2_a1' => [resultRow('three')],
        ]);

        expect($html)->toContain('<td>All Committees</td>', '<td>Committee 2</td>')
            ->not->toContain('Committee 7');
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
