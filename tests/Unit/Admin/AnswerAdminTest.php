<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Admin\AnswerAdmin;
use Confur\Config\Constants;
use Tests\ConfurTestCase;
use WP_Query;

/**
 * Tests for the answers list-table customisations.
 *
 * src/Admin was excluded from the coverage source set until now as
 * "render/callback and menu glue exercised through the admin UI at runtime".
 * Amber covers its whole src/Admin on the same tooling, so the exclusion was
 * habit rather than necessity.
 *
 * Everything here is driven for real. The column callbacks echo, so they are
 * captured with ob_start()/ob_get_clean() and asserted on as HTML; the hook
 * registration in the constructor is asserted against Brain Monkey's hook
 * store via assertActionAdded()/assertFilterAdded().
 *
 * @covers \Confur\Admin\AnswerAdmin
 */
final class AnswerAdminTest extends ConfurTestCase
{
    private const COLUMNS_HOOK  = 'manage_answer_posts_columns';
    private const COLUMN_HOOK   = 'manage_answer_posts_custom_column';
    private const SORTABLE_HOOK = 'manage_edit-answer_sortable_columns';
    private const BULK_HOOK     = 'bulk_actions-edit-answer';

    protected function setUp(): void
    {
        parent::setUp();

        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];

        parent::tearDown();
    }

    /** Capture whatever a callback echoes. */
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
    public function the_constructor_registers_every_list_table_hook(): void
    {
        new AnswerAdmin();

        $this->assertFilterAdded(self::COLUMNS_HOOK, false, 'the columns filter should be registered');
        $this->assertActionAdded(self::COLUMN_HOOK, false, 'the column renderer should be registered');
        $this->assertFilterAdded(self::SORTABLE_HOOK, false, 'the sortable columns filter should be registered');
        $this->assertFilterAdded('pre_get_posts', false, 'the sorting handler should be registered');
        $this->assertFilterAdded(self::BULK_HOOK, false, 'the bulk actions filter should be registered');
        $this->assertFilterAdded('handle_bulk_actions-edit-answer', false, 'the bulk handler should be registered');
        $this->assertActionAdded('admin_notices', false, 'the bulk notice should be registered');
        $this->assertActionAdded('admin_head', false, 'the column styles should be registered');
    }

    /**
     * The whole class is admin-only, and says so by bailing out of its own
     * constructor rather than by being conditionally instantiated.
     *
     * @test
     */
    public function nothing_is_registered_on_a_front_end_request(): void
    {
        WpState::$isAdmin = false;

        new AnswerAdmin();

        $this->assertFilterNotAdded(self::COLUMNS_HOOK);
        $this->assertActionNotAdded('admin_head');
    }

    // ── columns ───────────────────────────────────────────────────────

    /**
     * The three columns are inserted immediately after the title rather than
     * appended, so they land before the date column WordPress supplies.
     *
     * @test
     */
    public function the_custom_columns_are_inserted_directly_after_the_title(): void
    {
        $columns = (new AnswerAdmin())->addCustomColumns([
            'cb'    => '<input type="checkbox" />',
            'title' => 'Title',
            'date'  => 'Date',
        ]);

        $this->assertSame(
            ['cb', 'title', 'answer_status', 'answer_email', 'answer_updated', 'date'],
            array_keys($columns)
        );
        $this->assertSame('Date', $columns['date'], 'the original columns should be preserved');
    }

    /** @test */
    public function a_column_set_without_a_title_is_returned_untouched(): void
    {
        $original = ['cb' => 'x', 'date' => 'Date'];

        $this->assertSame($original, (new AnswerAdmin())->addCustomColumns($original));
    }

    /** @test */
    public function the_custom_columns_are_marked_sortable(): void
    {
        $columns = (new AnswerAdmin())->makeColumnsSortable(['title' => 'title']);

        $this->assertSame(
            ['title', 'answer_status', 'answer_email', 'answer_updated'],
            array_keys($columns)
        );
        $this->assertSame('answer_status', $columns['answer_status']);
    }

    // ── column contents ───────────────────────────────────────────────

    /**
     * The badge class is what colours the cell, and several spellings of each
     * status reach it — the ACF constant, the human form and the lowercase
     * form all have to land on the same class.
     *
     * @test
     * @dataProvider statuses
     */
    public function the_status_cell_renders_a_badge_for_each_spelling(mixed $stored, string $class, string $label): void
    {
        WpState::$fields['7|' . Constants::STATUS_FIELD] = $stored;

        $html = $this->capture(fn () => (new AnswerAdmin())->populateCustomColumns('answer_status', 7));

        $this->assertStringContainsString('class="answer-status-badge status-' . $class . '"', $html);
        $this->assertStringContainsString('>' . $label . '<', $html);
    }

    /** @return array<string, array{0: mixed, 1: string, 2: string}> */
    public static function statuses(): array
    {
        return [
            'the completed constant' => [Constants::STATUS_COMPLETED, 'completed', 'Complete'],
            'lowercase completed'    => ['completed', 'completed', 'completed'],
            'the draft constant'     => [Constants::STATUS_DRAFT, 'draft', 'Draft'],
            'lowercase draft'        => ['draft', 'draft', 'draft'],
            'the cancelled constant' => [Constants::STATUS_CANCELLED, 'cancelled', 'Cancelled'],
            'lowercase cancelled'    => ['cancelled', 'cancelled', 'cancelled'],
            'anything else'          => ['Sideways', 'not-started', 'Sideways'],
        ];
    }

    /**
     * An answer that has never been opened has no status field at all, and
     * reads as "Not Started" rather than as a blank cell.
     *
     * @test
     */
    public function a_missing_status_reads_as_not_started(): void
    {
        $html = $this->capture(fn () => (new AnswerAdmin())->populateCustomColumns('answer_status', 7));

        $this->assertStringContainsString('status-not-started', $html);
        $this->assertStringContainsString('Not Started', $html);
    }

    /** @test */
    public function the_email_cell_is_a_mailto_link(): void
    {
        WpState::$fields['8|' . Constants::EMAIL_FIELD] = 'group@example.org';

        $html = $this->capture(fn () => (new AnswerAdmin())->populateCustomColumns('answer_email', 8));

        $this->assertSame('<a href="mailto:group@example.org">group@example.org</a>', $html);
    }

    /** @test */
    public function an_absent_email_renders_a_dash(): void
    {
        $this->assertSame(
            '-',
            $this->capture(fn () => (new AnswerAdmin())->populateCustomColumns('answer_email', 8))
        );
    }

    /** @test */
    public function the_updated_cell_shows_the_stored_timestamp(): void
    {
        WpState::$fields['9|' . Constants::UPDATED_FIELD] = '2026-07-24 11:00:00';

        $this->assertSame(
            '2026-07-24 11:00:00',
            $this->capture(fn () => (new AnswerAdmin())->populateCustomColumns('answer_updated', 9))
        );
    }

    /** @test */
    public function an_absent_updated_date_renders_a_dash(): void
    {
        $this->assertSame(
            '-',
            $this->capture(fn () => (new AnswerAdmin())->populateCustomColumns('answer_updated', 9))
        );
    }

    /**
     * The callback is hooked for every column in the table, so it has to stay
     * silent on the ones it does not own.
     *
     * @test
     */
    public function a_column_the_plugin_does_not_own_renders_nothing(): void
    {
        $this->assertSame(
            '',
            $this->capture(fn () => (new AnswerAdmin())->populateCustomColumns('date', 9))
        );
    }

    // ── sorting ───────────────────────────────────────────────────────

    private function sortQuery(string $orderby, string $postType = Constants::ANSWER_CUSTOM_TYPE): WP_Query
    {
        return new WP_Query(['post_type' => $postType, 'orderby' => $orderby]);
    }

    /**
     * pre_get_posts fires for every query on the page, so the handler has to
     * establish it is looking at the answers list table before touching
     * anything.
     *
     * @test
     * @dataProvider sortableColumns
     */
    public function sorting_by_a_custom_column_orders_by_its_meta_value(string $orderby, string $metaKey): void
    {
        $query = (new AnswerAdmin())->handleCustomColumnSorting($this->sortQuery($orderby));

        $this->assertSame($metaKey, $query->get('meta_key'));
        $this->assertSame('meta_value', $query->get('orderby'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function sortableColumns(): array
    {
        return [
            'status'  => ['answer_status', Constants::STATUS_FIELD],
            'email'   => ['answer_email', Constants::EMAIL_FIELD],
            'updated' => ['answer_updated', Constants::UPDATED_FIELD],
        ];
    }

    /** @test */
    public function sorting_by_a_built_in_column_is_left_alone(): void
    {
        $query = (new AnswerAdmin())->handleCustomColumnSorting($this->sortQuery('title'));

        $this->assertSame('', $query->get('meta_key'));
        $this->assertSame('title', $query->get('orderby'));
    }

    /** @test */
    public function another_post_types_query_is_left_alone(): void
    {
        $query = (new AnswerAdmin())->handleCustomColumnSorting(
            $this->sortQuery('answer_status', 'tsml_meeting')
        );

        $this->assertSame('', $query->get('meta_key'));
        $this->assertSame('answer_status', $query->get('orderby'), 'orderby should be untouched');
    }

    /** @test */
    public function a_secondary_query_is_left_alone(): void
    {
        $query = $this->sortQuery('answer_status');
        $query->isMainQuery = false;

        $this->assertSame('', (new AnswerAdmin())->handleCustomColumnSorting($query)->get('meta_key'));
    }

    /** @test */
    public function a_front_end_query_is_left_alone(): void
    {
        $admin = new AnswerAdmin();
        WpState::$isAdmin = false;

        $this->assertSame(
            '',
            $admin->handleCustomColumnSorting($this->sortQuery('answer_status'))->get('meta_key')
        );
    }

    // ── bulk actions ──────────────────────────────────────────────────

    /** @test */
    public function the_cancel_bulk_action_is_offered_alongside_the_built_in_ones(): void
    {
        $actions = (new AnswerAdmin())->addBulkActions(['trash' => 'Move to Bin']);

        $this->assertSame(['trash' => 'Move to Bin', 'mark_cancelled' => 'Mark as Cancelled'], $actions);
    }

    /**
     * The count travels back to the notice through the redirect URL, so it has
     * to be the number of answers actually changed rather than the number
     * selected.
     *
     * @test
     */
    public function cancelling_in_bulk_updates_each_answer_and_reports_the_count(): void
    {
        $redirect = (new AnswerAdmin())->handleBulkActions('https://example.test/edit.php', 'mark_cancelled', [11, 12]);

        $this->assertSame(Constants::STATUS_CANCELLED, WpState::$fields['11|' . Constants::STATUS_FIELD]);
        $this->assertSame(Constants::STATUS_CANCELLED, WpState::$fields['12|' . Constants::STATUS_FIELD]);
        $this->assertStringContainsString('bulk_cancelled=2', $redirect);
    }

    /** @test */
    public function a_failed_update_is_not_counted(): void
    {
        Functions\when('update_field')->justReturn(false);

        $redirect = (new AnswerAdmin())->handleBulkActions('https://example.test/edit.php', 'mark_cancelled', [11, 12]);

        $this->assertStringContainsString('bulk_cancelled=0', $redirect);
    }

    /** @test */
    public function no_answers_selected_still_produces_a_zero_count(): void
    {
        $redirect = (new AnswerAdmin())->handleBulkActions('https://example.test/edit.php', 'mark_cancelled', []);

        $this->assertStringContainsString('bulk_cancelled=0', $redirect);
    }

    /**
     * The filter runs for every bulk action, including the ones core owns, so
     * an unrecognised action must hand the redirect straight back untouched.
     *
     * @test
     */
    public function another_bulk_action_passes_its_redirect_through_unchanged(): void
    {
        $redirect = (new AnswerAdmin())->handleBulkActions('https://example.test/edit.php', 'trash', [11]);

        $this->assertSame('https://example.test/edit.php', $redirect);
        $this->assertSame([], WpState::$fields, 'no answer should have been touched');
    }

    // ── the notice ────────────────────────────────────────────────────

    /** @test */
    public function no_notice_is_shown_on_an_ordinary_page_load(): void
    {
        $this->assertSame('', $this->capture(fn () => (new AnswerAdmin())->displayBulkActionNotice()));
    }

    /**
     * @test
     * @dataProvider noticeCounts
     */
    public function the_notice_agrees_with_itself_about_singular_and_plural(string $raw, string $expected): void
    {
        $_REQUEST['bulk_cancelled'] = $raw;

        $html = $this->capture(fn () => (new AnswerAdmin())->displayBulkActionNotice());

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString($expected, $html);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function noticeCounts(): array
    {
        return [
            'one'  => ['1', '1 answer marked as cancelled.'],
            'none' => ['0', '0 answers marked as cancelled.'],
            'many' => ['4', '4 answers marked as cancelled.'],
        ];
    }

    // ── column styles ─────────────────────────────────────────────────

    /** @test */
    public function the_column_styles_are_printed_on_the_answers_screen(): void
    {
        WpState::$screen = (object) ['post_type' => Constants::ANSWER_CUSTOM_TYPE];

        $html = $this->capture(fn () => (new AnswerAdmin())->addAdminColumnStyles());

        $this->assertStringContainsString('.answer-status-badge', $html);
        $this->assertStringContainsString('.column-answer_updated', $html);
    }

    /** @test */
    public function the_column_styles_are_not_printed_on_another_post_types_screen(): void
    {
        WpState::$screen = (object) ['post_type' => 'post'];

        $this->assertSame('', $this->capture(fn () => (new AnswerAdmin())->addAdminColumnStyles()));
    }

    /**
     * admin_head fires on screens where get_current_screen() has nothing to
     * report — the styles must not fatal there.
     *
     * @test
     */
    public function the_column_styles_are_not_printed_without_a_screen(): void
    {
        WpState::$screen = null;

        $this->assertSame('', $this->capture(fn () => (new AnswerAdmin())->addAdminColumnStyles()));
    }
}
