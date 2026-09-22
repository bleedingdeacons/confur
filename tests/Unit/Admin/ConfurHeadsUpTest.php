<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\WpState;
use Confur\Admin\ConfurHeadsUp;
use Confur\Config\Constants;
use ReflectionMethod;

/*
 * Tests for the "recent activity" dashboard widget.
 *
 * The widget builds its own AnswerRepository rather than taking one, so the
 * data is seeded into WpState — answer posts, their ACF fields and the meeting
 * titles — and the real repository is allowed to read it. That also means the
 * repository's field-name conventions are exercised end to end here rather
 * than mocked away.
 *
 * getRecentUpdates() is where the actual behaviour lives: a 24-hour window, a
 * committee/question grouping keyed off the c<n>_a<n> field names, and three
 * sorts. It is private and its public callers only echo, so it is driven
 * through reflection and asserted on directly; the rendering is checked
 * separately by capturing the echoed markup.
 */

covers(ConfurHeadsUp::class);

/** @return array<int, array<int, list<array<string, mixed>>>> */
function headsUpRecentUpdates(ConfurHeadsUp $widget): array
{
    $m = new ReflectionMethod(ConfurHeadsUp::class, 'getRecentUpdates');

    /** @var array<int, array<int, list<array<string, mixed>>>> $updates */
    $updates = $m->invoke($widget);

    return $updates;
}

beforeEach(function () {
    $_POST = [];
    $this->widget = new ConfurHeadsUp();

    /**
     * Seed one answer post: an updated timestamp, a meeting, and the answer
     * fields keyed the way ACF stores them.
     *
     * @param array<string, string> $answers Field name => answer text
     */
    $this->seedAnswer = function (int $postId, string $updated, int $meetingId, array $answers): void {
        WpState::$queryPosts[] = (object) [
            'ID'          => $postId,
            'post_type'   => Constants::ANSWER_CUSTOM_TYPE,
            'post_status' => 'publish',
        ];
        WpState::$postStatuses[$postId] = 'publish';

        $this->fields[$postId] = array_merge([
            Constants::UPDATED_FIELD => $updated,
            Constants::MEETING_FIELD => $meetingId,
            Constants::EMAIL_FIELD   => 'group@example.org',
            Constants::STATUS_FIELD  => Constants::STATUS_DRAFT,
        ], $answers);
    };
});

afterEach(function () {
    $_POST = [];
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('registers the widget and its refresh endpoint from init', function () {
        $this->widget->init();

        $this->assertActionAdded('wp_dashboard_setup', false, 'the widget should be registered on dashboard setup');
        $this->assertActionAdded('wp_ajax_confur_refresh_headsup', false, 'the refresh endpoint should be registered');
    });

    it('registers nothing on a front-end request', function () {
        WpState::$isAdmin = false;

        $this->widget->init();

        $this->assertActionNotAdded('wp_dashboard_setup');
        $this->assertActionNotAdded('wp_ajax_confur_refresh_headsup');
    });

    it('adds the widget to the dashboard under its own id', function () {
        $this->widget->registerWidget();

        expect(WpState::$widgets)->toHaveKey('confur_headsup_widget')
            ->and(WpState::$widgets['confur_headsup_widget']['name'])
            ->toBe('Questions for Conference - Recent Activity (24hrs)');
    });
});

// ── the 24-hour window ────────────────────────────────────────────
describe('the 24-hour window', function () {
    it('reports an answer updated inside the window', function () {
        $this->makePost(500, 'Monday Group');
        ($this->seedAnswer)(1, '2026-07-24 10:00:00', 500, ['c1_a1' => 'An answer']);

        $updates = headsUpRecentUpdates($this->widget);

        expect(array_keys($updates))->toBe([1], 'committee 1 should be present')
            ->and(array_keys($updates[1]))->toBe([1], 'question 1 should be present')
            ->and($updates[1][1][0]['group_name'])->toBe('Monday Group')
            ->and($updates[1][1][0]['url'])->toContain('page=confur-reporting#c1_a1');
    });

    // Anything older than 24 hours is the whole point of the widget's filter —
    // the dashboard is meant to show what moved since yesterday.
    it('ignores an answer updated before the window', function () {
        $this->makePost(500, 'Monday Group');
        ($this->seedAnswer)(1, '2026-07-20 10:00:00', 500, ['c1_a1' => 'Stale']);

        expect(headsUpRecentUpdates($this->widget))->toBe([]);
    });

    it('ignores an unparseable timestamp rather than treating it as now', function () {
        $this->makePost(500, 'Monday Group');
        ($this->seedAnswer)(1, 'not a date', 500, ['c1_a1' => 'An answer']);

        expect(headsUpRecentUpdates($this->widget))->toBe([]);
    });

    // Only fields shaped c<committee>_a<question> are committee answers.
    // Anything else with a c prefix reaches the widget through the repository
    // and has to fall out here.
    it('ignores a field that is not a committee answer', function () {
        $this->makePost(500, 'Monday Group');
        ($this->seedAnswer)(1, '2026-07-24 10:00:00', 500, [
            'c1_a1'        => 'An answer',
            'c1_notes'     => 'Some notes',
            'c_a1'         => 'Malformed',
        ]);

        $updates = headsUpRecentUpdates($this->widget);

        expect($updates[1])->toHaveCount(1, 'only the c1_a1 field should have produced a question');
    });
});

// ── grouping and sorting ──────────────────────────────────────────
describe('grouping and sorting', function () {
    // Committees and questions are keyed by integer and sorted numerically, so
    // committee 10 comes after committee 2 rather than between 1 and 2.
    it('orders committees and questions numerically', function () {
        $this->makePost(500, 'Monday Group');
        ($this->seedAnswer)(1, '2026-07-24 10:00:00', 500, [
            'c10_a1' => 'Ten',
            'c2_a3'  => 'Two, three',
            'c2_a1'  => 'Two, one',
        ]);

        $updates = headsUpRecentUpdates($this->widget);

        expect(array_keys($updates))->toBe([2, 10])
            ->and(array_keys($updates[2]))->toBe([1, 3]);
    });

    // Within a question the most recently updated group is listed first — the
    // widget is read top-down as "what just happened".
    it('lists groups within a question most recent first', function () {
        $this->makePost(500, 'Earlier Group');
        $this->makePost(501, 'Later Group');
        ($this->seedAnswer)(1, '2026-07-24 06:00:00', 500, ['c1_a1' => 'Earlier']);
        ($this->seedAnswer)(2, '2026-07-24 11:00:00', 501, ['c1_a1' => 'Later']);

        $groups = headsUpRecentUpdates($this->widget)[1][1];

        expect(array_column($groups, 'group_name'))->toBe(['Later Group', 'Earlier Group']);
    });
});

// ── rendering ─────────────────────────────────────────────────────
describe('rendering', function () {
    it('renders an explicit empty state for a quiet 24 hours', function () {
        $html = captureOutput(fn () => $this->widget->renderWidget());

        expect($html)->toContain('No updates in the last 24 hours')
            // The class name also appears in the stylesheet, so look for the list itself.
            ->not->toContain('<ul class="confur-updates-list">');
    });

    it('renders its styles, script and refresh control', function () {
        $html = captureOutput(fn () => $this->widget->renderWidget());

        expect($html)->toContain('<style>')
            // the script should post to the refresh action
            ->toContain('confur_refresh_headsup')
            ->toContain('id="confur-refresh-btn"', 'id="confur-update-time"');
    });

    it('renders each group as a link under its committee and question', function () {
        $this->makePost(500, 'Monday Group');
        ($this->seedAnswer)(1, '2026-07-24 10:00:00', 500, ['c3_a2' => 'An answer']);

        $html = captureOutput(fn () => $this->widget->renderWidget());

        expect($html)->toContain('data-committee="3"', 'Committee 3', 'Question 2', 'Monday Group', '#c3_a2')
            // the relative time should be rendered
            ->toContain('ago</span>');
    });
});

// ── the AJAX refresh ──────────────────────────────────────────────
describe('the AJAX refresh', function () {
    it('refuses a request without a nonce', function () {
        try {
            $this->widget->ajaxRefreshWidget();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeFalse()
                ->and($e->data['message'])->toBe('Invalid security token');
        }
    });

    it('refuses a stale nonce', function () {
        $_POST['nonce'] = 'nonce-something-else';

        $this->widget->ajaxRefreshWidget();
    })->throws(JsonResponseException::class);

    // The refresh returns the same partial the widget rendered inline, so the
    // script can swap innerHTML without reloading the dashboard.
    it('returns the rendered content and a timestamp', function () {
        $_POST['nonce'] = 'nonce-confur_headsup_refresh';
        $this->makePost(500, 'Monday Group');
        ($this->seedAnswer)(1, '2026-07-24 10:00:00', 500, ['c1_a1' => 'An answer']);

        try {
            $this->widget->ajaxRefreshWidget();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeTrue()
                ->and($e->data['content'])->toContain('Monday Group')
                ->and($e->data['updated'])->toBe(WpState::$now);
        }
    });

    // The refresh must not re-emit the widget's <style> and <script> blocks —
    // they are already on the page, and the response replaces the content
    // div only.
    it('returns content without the wrapper styles and script', function () {
        $_POST['nonce'] = 'nonce-confur_headsup_refresh';

        try {
            $this->widget->ajaxRefreshWidget();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['content'])->not->toContain('<style>')
                ->not->toContain('<script>');
        }
    });
});
