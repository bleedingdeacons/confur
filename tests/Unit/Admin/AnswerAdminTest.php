<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Admin\AnswerAdmin;
use Confur\Config\Constants;
use WP_Query;

/*
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
 */

covers(AnswerAdmin::class);

const ANSWER_COLUMNS_HOOK  = 'manage_answer_posts_columns';
const ANSWER_COLUMN_HOOK   = 'manage_answer_posts_custom_column';
const ANSWER_SORTABLE_HOOK = 'manage_edit-answer_sortable_columns';
const ANSWER_BULK_HOOK     = 'bulk_actions-edit-answer';

function answerSortQuery(string $orderby, string $postType = Constants::ANSWER_CUSTOM_TYPE): WP_Query
{
    return new WP_Query(['post_type' => $postType, 'orderby' => $orderby]);
}

beforeEach(function () {
    $_REQUEST = [];
});

afterEach(function () {
    $_REQUEST = [];
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('registers every list table hook in the constructor', function () {
        new AnswerAdmin();

        $this->assertFilterAdded(ANSWER_COLUMNS_HOOK, false, 'the columns filter should be registered');
        $this->assertActionAdded(ANSWER_COLUMN_HOOK, false, 'the column renderer should be registered');
        $this->assertFilterAdded(ANSWER_SORTABLE_HOOK, false, 'the sortable columns filter should be registered');
        $this->assertFilterAdded('pre_get_posts', false, 'the sorting handler should be registered');
        $this->assertFilterAdded(ANSWER_BULK_HOOK, false, 'the bulk actions filter should be registered');
        $this->assertFilterAdded('handle_bulk_actions-edit-answer', false, 'the bulk handler should be registered');
        $this->assertActionAdded('admin_notices', false, 'the bulk notice should be registered');
        $this->assertActionAdded('admin_head', false, 'the column styles should be registered');
    });

    // The whole class is admin-only, and says so by bailing out of its own
    // constructor rather than by being conditionally instantiated.
    it('registers nothing on a front-end request', function () {
        WpState::$isAdmin = false;

        new AnswerAdmin();

        $this->assertFilterNotAdded(ANSWER_COLUMNS_HOOK);
        $this->assertActionNotAdded('admin_head');
    });
});

// ── columns ───────────────────────────────────────────────────────
describe('columns', function () {
    // The three columns are inserted immediately after the title rather than
    // appended, so they land before the date column WordPress supplies.
    it('inserts the custom columns directly after the title', function () {
        $columns = (new AnswerAdmin())->addCustomColumns([
            'cb'    => '<input type="checkbox" />',
            'title' => 'Title',
            'date'  => 'Date',
        ]);

        expect(array_keys($columns))->toBe(['cb', 'title', 'answer_status', 'answer_email', 'answer_updated', 'date'])
            ->and($columns['date'])->toBe('Date', 'the original columns should be preserved');
    });

    it('returns a column set without a title untouched', function () {
        $original = ['cb' => 'x', 'date' => 'Date'];

        expect((new AnswerAdmin())->addCustomColumns($original))->toBe($original);
    });

    it('marks the custom columns sortable', function () {
        $columns = (new AnswerAdmin())->makeColumnsSortable(['title' => 'title']);

        expect(array_keys($columns))->toBe(['title', 'answer_status', 'answer_email', 'answer_updated'])
            ->and($columns['answer_status'])->toBe('answer_status');
    });
});

// ── column contents ───────────────────────────────────────────────
describe('column contents', function () {
    // The badge class is what colours the cell, and several spellings of each
    // status reach it — the ACF constant, the human form and the lowercase
    // form all have to land on the same class.
    it('renders a status badge for each spelling', function (mixed $stored, string $class, string $label) {
        WpState::$fields['7|' . Constants::STATUS_FIELD] = $stored;

        $html = captureOutput(fn () => (new AnswerAdmin())->populateCustomColumns('answer_status', 7));

        expect($html)->toContain(
            'class="answer-status-badge status-' . $class . '"',
            '>' . $label . '<',
        );
    })->with([
        'the completed constant' => [Constants::STATUS_COMPLETED, 'completed', 'Complete'],
        'lowercase completed'    => ['completed', 'completed', 'completed'],
        'the draft constant'     => [Constants::STATUS_DRAFT, 'draft', 'Draft'],
        'lowercase draft'        => ['draft', 'draft', 'draft'],
        'the cancelled constant' => [Constants::STATUS_CANCELLED, 'cancelled', 'Cancelled'],
        'lowercase cancelled'    => ['cancelled', 'cancelled', 'cancelled'],
        'anything else'          => ['Sideways', 'not-started', 'Sideways'],
    ]);

    // An answer that has never been opened has no status field at all, and
    // reads as "Not Started" rather than as a blank cell.
    it('reads a missing status as Not Started', function () {
        $html = captureOutput(fn () => (new AnswerAdmin())->populateCustomColumns('answer_status', 7));

        expect($html)->toContain('status-not-started', 'Not Started');
    });

    it('renders the email cell as a mailto link', function () {
        WpState::$fields['8|' . Constants::EMAIL_FIELD] = 'group@example.org';

        $html = captureOutput(fn () => (new AnswerAdmin())->populateCustomColumns('answer_email', 8));

        expect($html)->toBe('<a href="mailto:group@example.org">group@example.org</a>');
    });

    it('renders an absent email as a dash', function () {
        expect(captureOutput(fn () => (new AnswerAdmin())->populateCustomColumns('answer_email', 8)))
            ->toBe('-');
    });

    it('shows the stored timestamp in the updated cell', function () {
        WpState::$fields['9|' . Constants::UPDATED_FIELD] = '2026-07-24 11:00:00';

        expect(captureOutput(fn () => (new AnswerAdmin())->populateCustomColumns('answer_updated', 9)))
            ->toBe('2026-07-24 11:00:00');
    });

    it('renders an absent updated date as a dash', function () {
        expect(captureOutput(fn () => (new AnswerAdmin())->populateCustomColumns('answer_updated', 9)))
            ->toBe('-');
    });

    // The callback is hooked for every column in the table, so it has to stay
    // silent on the ones it does not own.
    it('renders nothing for a column the plugin does not own', function () {
        expect(captureOutput(fn () => (new AnswerAdmin())->populateCustomColumns('date', 9)))
            ->toBe('');
    });
});

// ── sorting ───────────────────────────────────────────────────────
describe('sorting', function () {
    // pre_get_posts fires for every query on the page, so the handler has to
    // establish it is looking at the answers list table before touching
    // anything.
    it('orders by the meta value when sorting by a custom column', function (string $orderby, string $metaKey) {
        $query = (new AnswerAdmin())->handleCustomColumnSorting(answerSortQuery($orderby));

        expect($query->get('meta_key'))->toBe($metaKey)
            ->and($query->get('orderby'))->toBe('meta_value');
    })->with([
        'status'  => ['answer_status', Constants::STATUS_FIELD],
        'email'   => ['answer_email', Constants::EMAIL_FIELD],
        'updated' => ['answer_updated', Constants::UPDATED_FIELD],
    ]);

    it('leaves sorting by a built-in column alone', function () {
        $query = (new AnswerAdmin())->handleCustomColumnSorting(answerSortQuery('title'));

        expect($query->get('meta_key'))->toBe('')
            ->and($query->get('orderby'))->toBe('title');
    });

    it("leaves another post type's query alone", function () {
        $query = (new AnswerAdmin())->handleCustomColumnSorting(
            answerSortQuery('answer_status', 'tsml_meeting')
        );

        expect($query->get('meta_key'))->toBe('')
            ->and($query->get('orderby'))->toBe('answer_status', 'orderby should be untouched');
    });

    it('leaves a secondary query alone', function () {
        $query = answerSortQuery('answer_status');
        $query->isMainQuery = false;

        expect((new AnswerAdmin())->handleCustomColumnSorting($query)->get('meta_key'))->toBe('');
    });

    it('leaves a front-end query alone', function () {
        $admin = new AnswerAdmin();
        WpState::$isAdmin = false;

        expect($admin->handleCustomColumnSorting(answerSortQuery('answer_status'))->get('meta_key'))
            ->toBe('');
    });
});

// ── bulk actions ──────────────────────────────────────────────────
describe('bulk actions', function () {
    it('offers the cancel bulk action alongside the built-in ones', function () {
        $actions = (new AnswerAdmin())->addBulkActions(['trash' => 'Move to Bin']);

        expect($actions)->toBe(['trash' => 'Move to Bin', 'mark_cancelled' => 'Mark as Cancelled']);
    });

    // The count travels back to the notice through the redirect URL, so it has
    // to be the number of answers actually changed rather than the number
    // selected.
    it('updates each answer and reports the count when cancelling in bulk', function () {
        $redirect = (new AnswerAdmin())->handleBulkActions('https://example.test/edit.php', 'mark_cancelled', [11, 12]);

        expect(WpState::$fields['11|' . Constants::STATUS_FIELD])->toBe(Constants::STATUS_CANCELLED)
            ->and(WpState::$fields['12|' . Constants::STATUS_FIELD])->toBe(Constants::STATUS_CANCELLED)
            ->and($redirect)->toContain('bulk_cancelled=2');
    });

    it('does not count a failed update', function () {
        Functions\when('update_field')->justReturn(false);

        $redirect = (new AnswerAdmin())->handleBulkActions('https://example.test/edit.php', 'mark_cancelled', [11, 12]);

        expect($redirect)->toContain('bulk_cancelled=0');
    });

    it('still produces a zero count with no answers selected', function () {
        $redirect = (new AnswerAdmin())->handleBulkActions('https://example.test/edit.php', 'mark_cancelled', []);

        expect($redirect)->toContain('bulk_cancelled=0');
    });

    // The filter runs for every bulk action, including the ones core owns, so
    // an unrecognised action must hand the redirect straight back untouched.
    it('passes another bulk action its redirect through unchanged', function () {
        $redirect = (new AnswerAdmin())->handleBulkActions('https://example.test/edit.php', 'trash', [11]);

        expect($redirect)->toBe('https://example.test/edit.php')
            ->and(WpState::$fields)->toBe([], 'no answer should have been touched');
    });
});

// ── the notice ────────────────────────────────────────────────────
describe('the notice', function () {
    it('is not shown on an ordinary page load', function () {
        expect(captureOutput(fn () => (new AnswerAdmin())->displayBulkActionNotice()))->toBe('');
    });

    it('agrees with itself about singular and plural', function (string $raw, string $expected) {
        $_REQUEST['bulk_cancelled'] = $raw;

        $html = captureOutput(fn () => (new AnswerAdmin())->displayBulkActionNotice());

        expect($html)->toContain('notice-success', $expected);
    })->with([
        'one'  => ['1', '1 answer marked as cancelled.'],
        'none' => ['0', '0 answers marked as cancelled.'],
        'many' => ['4', '4 answers marked as cancelled.'],
    ]);
});

// ── column styles ─────────────────────────────────────────────────
describe('column styles', function () {
    it('are printed on the answers screen', function () {
        WpState::$screen = (object) ['post_type' => Constants::ANSWER_CUSTOM_TYPE];

        $html = captureOutput(fn () => (new AnswerAdmin())->addAdminColumnStyles());

        expect($html)->toContain('.answer-status-badge', '.column-answer_updated');
    });

    it("are not printed on another post type's screen", function () {
        WpState::$screen = (object) ['post_type' => 'post'];

        expect(captureOutput(fn () => (new AnswerAdmin())->addAdminColumnStyles()))->toBe('');
    });

    // admin_head fires on screens where get_current_screen() has nothing to
    // report — the styles must not fatal there.
    it('are not printed without a screen', function () {
        WpState::$screen = null;

        expect(captureOutput(fn () => (new AnswerAdmin())->addAdminColumnStyles()))->toBe('');
    });
});
