<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\WpState;
use Confur\Admin\ConfurHeadsUp;
use Confur\Config\Constants;
use ReflectionMethod;
use Tests\ConfurTestCase;

/**
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
 *
 * @covers \Confur\Admin\ConfurHeadsUp
 */
final class ConfurHeadsUpTest extends ConfurTestCase
{
    private ConfurHeadsUp $widget;

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];
        $this->widget = new ConfurHeadsUp();
    }

    protected function tearDown(): void
    {
        $_POST = [];

        parent::tearDown();
    }

    /**
     * Seed one answer post: an updated timestamp, a meeting, and the answer
     * fields keyed the way ACF stores them.
     *
     * @param array<string, string> $answers Field name => answer text
     */
    private function seedAnswer(int $postId, string $updated, int $meetingId, array $answers): void
    {
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
    }

    /** @return array<int, array<int, list<array<string, mixed>>>> */
    private function recentUpdates(): array
    {
        $m = new ReflectionMethod(ConfurHeadsUp::class, 'getRecentUpdates');

        /** @var array<int, array<int, list<array<string, mixed>>>> $updates */
        $updates = $m->invoke($this->widget);

        return $updates;
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

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_registers_the_widget_and_its_refresh_endpoint(): void
    {
        $this->widget->init();

        $this->assertActionAdded('wp_dashboard_setup', false, 'the widget should be registered on dashboard setup');
        $this->assertActionAdded('wp_ajax_confur_refresh_headsup', false, 'the refresh endpoint should be registered');
    }

    /** @test */
    public function nothing_is_registered_on_a_front_end_request(): void
    {
        WpState::$isAdmin = false;

        $this->widget->init();

        $this->assertActionNotAdded('wp_dashboard_setup');
        $this->assertActionNotAdded('wp_ajax_confur_refresh_headsup');
    }

    /** @test */
    public function the_widget_is_added_to_the_dashboard_under_its_own_id(): void
    {
        $this->widget->registerWidget();

        $this->assertArrayHasKey('confur_headsup_widget', WpState::$widgets);
        $this->assertSame(
            'Questions for Conference - Recent Activity (24hrs)',
            WpState::$widgets['confur_headsup_widget']['name']
        );
    }

    // ── the 24-hour window ────────────────────────────────────────────

    /** @test */
    public function an_answer_updated_inside_the_window_is_reported(): void
    {
        $this->makePost(500, 'Monday Group');
        $this->seedAnswer(1, '2026-07-24 10:00:00', 500, ['c1_a1' => 'An answer']);

        $updates = $this->recentUpdates();

        $this->assertSame([1], array_keys($updates), 'committee 1 should be present');
        $this->assertSame([1], array_keys($updates[1]), 'question 1 should be present');
        $this->assertSame('Monday Group', $updates[1][1][0]['group_name']);
        $this->assertStringContainsString('page=confur-reporting#c1_a1', $updates[1][1][0]['url']);
    }

    /**
     * Anything older than 24 hours is the whole point of the widget's filter —
     * the dashboard is meant to show what moved since yesterday.
     *
     * @test
     */
    public function an_answer_updated_before_the_window_is_ignored(): void
    {
        $this->makePost(500, 'Monday Group');
        $this->seedAnswer(1, '2026-07-20 10:00:00', 500, ['c1_a1' => 'Stale']);

        $this->assertSame([], $this->recentUpdates());
    }

    /** @test */
    public function an_unparseable_timestamp_is_ignored_rather_than_treated_as_now(): void
    {
        $this->makePost(500, 'Monday Group');
        $this->seedAnswer(1, 'not a date', 500, ['c1_a1' => 'An answer']);

        $this->assertSame([], $this->recentUpdates());
    }

    /**
     * Only fields shaped c<committee>_a<question> are committee answers.
     * Anything else with a c prefix reaches the widget through the repository
     * and has to fall out here.
     *
     * @test
     */
    public function a_field_that_is_not_a_committee_answer_is_ignored(): void
    {
        $this->makePost(500, 'Monday Group');
        $this->seedAnswer(1, '2026-07-24 10:00:00', 500, [
            'c1_a1'        => 'An answer',
            'c1_notes'     => 'Some notes',
            'c_a1'         => 'Malformed',
        ]);

        $updates = $this->recentUpdates();

        $this->assertCount(1, $updates[1], 'only the c1_a1 field should have produced a question');
    }

    // ── grouping and sorting ──────────────────────────────────────────

    /**
     * Committees and questions are keyed by integer and sorted numerically, so
     * committee 10 comes after committee 2 rather than between 1 and 2.
     *
     * @test
     */
    public function committees_and_questions_are_ordered_numerically(): void
    {
        $this->makePost(500, 'Monday Group');
        $this->seedAnswer(1, '2026-07-24 10:00:00', 500, [
            'c10_a1' => 'Ten',
            'c2_a3'  => 'Two, three',
            'c2_a1'  => 'Two, one',
        ]);

        $updates = $this->recentUpdates();

        $this->assertSame([2, 10], array_keys($updates));
        $this->assertSame([1, 3], array_keys($updates[2]));
    }

    /**
     * Within a question the most recently updated group is listed first — the
     * widget is read top-down as "what just happened".
     *
     * @test
     */
    public function groups_within_a_question_are_listed_most_recent_first(): void
    {
        $this->makePost(500, 'Earlier Group');
        $this->makePost(501, 'Later Group');
        $this->seedAnswer(1, '2026-07-24 06:00:00', 500, ['c1_a1' => 'Earlier']);
        $this->seedAnswer(2, '2026-07-24 11:00:00', 501, ['c1_a1' => 'Later']);

        $groups = $this->recentUpdates()[1][1];

        $this->assertSame(['Later Group', 'Earlier Group'], array_column($groups, 'group_name'));
    }

    // ── rendering ─────────────────────────────────────────────────────

    /** @test */
    public function a_quiet_24_hours_renders_an_explicit_empty_state(): void
    {
        $html = $this->capture(fn () => $this->widget->renderWidget());

        $this->assertStringContainsString('No updates in the last 24 hours', $html);
        // The class name also appears in the stylesheet, so look for the list itself.
        $this->assertStringNotContainsString('<ul class="confur-updates-list">', $html);
    }

    /** @test */
    public function the_widget_renders_its_styles_script_and_refresh_control(): void
    {
        $html = $this->capture(fn () => $this->widget->renderWidget());

        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('confur_refresh_headsup', $html, 'the script should post to the refresh action');
        $this->assertStringContainsString('id="confur-refresh-btn"', $html);
        $this->assertStringContainsString('id="confur-update-time"', $html);
    }

    /** @test */
    public function each_group_is_rendered_as_a_link_under_its_committee_and_question(): void
    {
        $this->makePost(500, 'Monday Group');
        $this->seedAnswer(1, '2026-07-24 10:00:00', 500, ['c3_a2' => 'An answer']);

        $html = $this->capture(fn () => $this->widget->renderWidget());

        $this->assertStringContainsString('data-committee="3"', $html);
        $this->assertStringContainsString('Committee 3', $html);
        $this->assertStringContainsString('Question 2', $html);
        $this->assertStringContainsString('Monday Group', $html);
        $this->assertStringContainsString('#c3_a2', $html);
        $this->assertStringContainsString('ago</span>', $html, 'the relative time should be rendered');
    }

    // ── the AJAX refresh ──────────────────────────────────────────────

    /** @test */
    public function the_refresh_endpoint_refuses_a_request_without_a_nonce(): void
    {
        try {
            $this->widget->ajaxRefreshWidget();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
            $this->assertSame('Invalid security token', $e->data['message']);
        }
    }

    /** @test */
    public function the_refresh_endpoint_refuses_a_stale_nonce(): void
    {
        $_POST['nonce'] = 'nonce-something-else';

        $this->expectException(JsonResponseException::class);
        $this->widget->ajaxRefreshWidget();
    }

    /**
     * The refresh returns the same partial the widget rendered inline, so the
     * script can swap innerHTML without reloading the dashboard.
     *
     * @test
     */
    public function the_refresh_endpoint_returns_the_rendered_content_and_a_timestamp(): void
    {
        $_POST['nonce'] = 'nonce-confur_headsup_refresh';
        $this->makePost(500, 'Monday Group');
        $this->seedAnswer(1, '2026-07-24 10:00:00', 500, ['c1_a1' => 'An answer']);

        try {
            $this->widget->ajaxRefreshWidget();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertStringContainsString('Monday Group', $e->data['content']);
            $this->assertSame(WpState::$now, $e->data['updated']);
        }
    }

    /**
     * The refresh must not re-emit the widget's <style> and <script> blocks —
     * they are already on the page, and the response replaces the content
     * div only.
     *
     * @test
     */
    public function the_refresh_returns_content_without_the_wrapper_styles_and_script(): void
    {
        $_POST['nonce'] = 'nonce-confur_headsup_refresh';

        try {
            $this->widget->ajaxRefreshWidget();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertStringNotContainsString('<style>', $e->data['content']);
            $this->assertStringNotContainsString('<script>', $e->data['content']);
        }
    }
}
