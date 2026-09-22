<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Admin\StatusAdminPage;
use Confur\Config\Constants;
use ReflectionMethod;
use WP_Screen;

/*
 * Tests for the registration status screen.
 *
 * The largest class in the layer, and the least glue-like: it joins every TSML
 * meeting against every answer, dedupes paired registrations, counts distinct
 * meetings separately from registrations, and detects duplicate sign-ups by
 * email and meeting combination. That work is private behind renderAdminPage(),
 * so it is driven through reflection and asserted on as data, with the render
 * checked separately by capturing the markup.
 *
 * The two AJAX handlers end in wp_send_json_*(), which the shared stubs turn
 * into a JsonResponseException, so every guard and both outcomes are plain
 * exception assertions. Nothing on this page redirects, so there is no exit to
 * work around.
 *
 * The page builds its own repositories, so meetings and answers are seeded
 * into WpState and the real ones read them.
 */

covers(StatusAdminPage::class);

const STATUS_SCREEN = 'questions-for-conference_page_confur-answer-submissions';

/** @return array<int, array<string, mixed>> */
function statusMeetingsData(StatusAdminPage $page): array
{
    $m = new ReflectionMethod(StatusAdminPage::class, 'getAllMeetingsData');

    /** @var array<int, array<string, mixed>> $data */
    $data = $m->invoke($page);

    return $data;
}

/**
 * @param array<int, array<string, mixed>> $meetings
 * @return array<string, mixed>
 */
function statusStats(StatusAdminPage $page, array $meetings): array
{
    $m = new ReflectionMethod(StatusAdminPage::class, 'calculateStats');

    /** @var array<string, mixed> $stats */
    $stats = $m->invoke($page, $meetings);

    return $stats;
}

/** @return array<int, array<string, mixed>> */
function statusDuplicates(StatusAdminPage $page): array
{
    $m = new ReflectionMethod(StatusAdminPage::class, 'findDuplicateRegistrations');

    /** @var array<int, array<string, mixed>> $duplicates */
    $duplicates = $m->invoke($page, []);

    return $duplicates;
}

beforeEach(function () {
    $_POST = [];

    // Stands in for the user meta store.
    $this->userMeta = [];

    $this->page = new StatusAdminPage();

    Functions\when('get_admin_page_title')->justReturn('Status');
    Functions\when('get_user_meta')->alias(
        fn (int $userId, string $key, bool $single = false): mixed
            => $this->userMeta[$userId . '|' . $key] ?? ''
    );
    Functions\when('update_user_meta')->alias(
        function (int $userId, string $key, mixed $value): bool {
            $this->userMeta[$userId . '|' . $key] = $value;

            return true;
        }
    );

    // Seed a TSML meeting the status page will list.
    $this->seedMeeting = function (int $id, string $title, string $day = '1', string $time = '19:00'): void {
        WpState::$queryPosts[] = (object) [
            'ID'          => $id,
            'post_type'   => 'tsml_meeting',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_name'   => 'meeting-' . $id,
            'post_parent' => 0,
        ];
        WpState::$postMeta[$id] = ['day' => [$day], 'time' => [$time]];
        $this->makePost($id, $title, 'publish', 'tsml_meeting');
    };

    // Seed an answer post registered against a meeting.
    $this->seedRegistration = function (
        int $postId,
        int $meetingId,
        string $status = Constants::STATUS_COMPLETED,
        string $email = 'group@example.org',
        ?int $fellowMeetingId = null,
        string $updated = '2026-07-24 10:00:00'
    ): void {
        WpState::$queryPosts[] = (object) [
            'ID'          => $postId,
            'post_type'   => Constants::ANSWER_CUSTOM_TYPE,
            'post_status' => 'publish',
        ];
        WpState::$postStatuses[$postId] = 'publish';

        $this->fields[$postId] = [
            Constants::MEETING_FIELD        => $meetingId,
            Constants::FELLOW_MEETING_FIELD => $fellowMeetingId,
            Constants::EMAIL_FIELD          => $email,
            Constants::UPDATED_FIELD        => $updated,
            Constants::STATUS_FIELD         => $status,
        ];
    };

    // Seed an answer the resend handler will accept.
    $this->seedResendable = function (int $postId, int $meetingId = 500, ?int $fellowMeetingId = null): void {
        $this->makePost($postId, 'An answer', 'publish', Constants::ANSWER_CUSTOM_TYPE);
        $this->makePost($meetingId, 'Monday Group', 'publish', 'tsml_meeting');
        $this->fields[$postId] = [
            Constants::EMAIL_FIELD          => 'group@example.org',
            Constants::MEETING_FIELD        => $meetingId,
            Constants::FELLOW_MEETING_FIELD => $fellowMeetingId,
        ];
        $_POST = ['nonce' => 'nonce-confur_resend_confirmation', 'answer_id' => (string) $postId];
    };
});

afterEach(function () {
    $_POST = [];
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('registers the menu, the assets and all three AJAX endpoints from init', function () {
        $this->page->init();

        foreach (
            [
            'admin_menu',
            'admin_enqueue_scripts',
            'wp_ajax_confur_cancel_duplicate',
            'wp_ajax_confur_resend_confirmation',
            'load-' . STATUS_SCREEN,
            'wp_ajax_confur_save_screen_option',
            ] as $hook
        ) {
            $this->assertActionAdded($hook, false, 'expected ' . $hook . ' to be hooked');
        }
    });

    it('registers nothing on a front-end request', function () {
        WpState::$isAdmin = false;

        $this->page->init();

        $this->assertActionNotAdded('admin_menu');
        $this->assertActionNotAdded('wp_ajax_confur_cancel_duplicate');
    });

    // The gate is 'edit_answers', not 'read'.
    //
    // This table lists every meeting's contact names, telephone numbers and
    // registration email address. 'read' is held by every logged-in user
    // including Subscribers, which put that data one URL away from anyone
    // with an account; the rest of this class already gated on
    // 'edit_answers'.
    it('adds the page under the Confur menu for answer editors', function () {
        $this->page->addAdminMenu();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0]['parent'])->toBe('confur')
            ->and(WpState::$menus[0]['slug'])->toBe('confur-answer-submissions')
            ->and(WpState::$menus[0]['cap'])->toBe('edit_answers');
    });

    it('only loads the page assets on this screen', function () {
        $this->page->enqueueAdminAssets('edit.php');

        expect(WpState::$enqueued)->toBe([]);
    });

    it('loads the page styles and scripts on this screen', function () {
        $this->page->enqueueAdminAssets(STATUS_SCREEN);

        expect(WpState::$enqueued)->toBe([
            ['fn' => 'wp_add_inline_style', 'handle' => 'wp-admin'],
            ['fn' => 'wp_add_inline_script', 'handle' => 'jquery'],
        ]);
    });
});

// ── screen options ────────────────────────────────────────────────
describe('screen options', function () {
    it('registers the screen options filter when the screen loads', function () {
        $this->page->addScreenOptions();

        $this->assertFilterAdded('screen_settings', false, 'the screen options should be registered');
    });

    it('does not add the screen options to another screen', function () {
        $screen = new WP_Screen(['id' => 'edit-post']);

        expect($this->page->renderScreenOptions('existing', $screen))->toBe('existing');
    });

    it('adds a show cancellations toggle', function () {
        $screen = new WP_Screen(['id' => STATUS_SCREEN]);

        $html = $this->page->renderScreenOptions('existing', $screen);

        expect($html)->toStartWith('existing', 'the existing settings should be preserved')
            ->toContain('id="confur_show_cancellations"')
            // the toggle should post to the save endpoint
            ->toContain('confur_save_screen_option');
    });

    // A user who has never touched the toggle should see cancellations, so an
    // unset preference has to read as on rather than as off.
    it('defaults the toggle to on for a user who has never set it', function () {
        $html = $this->page->renderScreenOptions('', new WP_Screen(['id' => STATUS_SCREEN]));

        expect($html)->toContain('checked="checked"');
    });

    it('reflects a saved preference of off in the toggle', function () {
        $this->userMeta['1|confur_show_cancellations'] = 0;

        $html = $this->page->renderScreenOptions('', new WP_Screen(['id' => STATUS_SCREEN]));

        expect($html)->not->toContain('checked="checked"');
    });

    it('stores the screen option against the current user when saving', function () {
        $_POST['show_cancellations'] = '1';

        try {
            $this->page->handleSaveScreenOption();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeTrue()
                ->and($this->userMeta['1|confur_show_cancellations'])->toBe(1);
        }
    });

    it('stores zero when clearing the screen option', function () {
        $_POST['show_cancellations'] = '0';

        try {
            $this->page->handleSaveScreenOption();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($this->userMeta['1|confur_show_cancellations'])->toBe(0);
        }
    });
});

// ── cancelling a duplicate ────────────────────────────────────────
describe('cancelling a duplicate', function () {
    it('refuses a request without a nonce', function () {
        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeFalse()
                ->and($e->data['message'])->toBe('Invalid security token');
        }
    });

    it('refuses a user without the capability', function () {
        $_POST['nonce'] = 'nonce-confur_cancel_duplicate';
        WpState::$userCan = false;

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('Insufficient permissions');
        }
    });

    it('refuses a missing or unusable answer id', function () {
        $_POST = ['nonce' => 'nonce-confur_cancel_duplicate'];

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('Invalid answer ID');
        }
    });

    // The id arrives from the browser, so it is re-checked against the post
    // type rather than trusted to point at an answer.
    it('refuses an id that is not an answer', function () {
        $this->makePost(700, 'A meeting', 'publish', 'tsml_meeting');
        $_POST = ['nonce' => 'nonce-confur_cancel_duplicate', 'answer_id' => '700'];

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('Answer not found');
        }
    });

    it('sets the status to cancelled', function () {
        $this->makePost(700, 'An answer', 'publish', Constants::ANSWER_CUSTOM_TYPE);
        $_POST = ['nonce' => 'nonce-confur_cancel_duplicate', 'answer_id' => '700'];

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeTrue()
                ->and(WpState::$fields['700|' . Constants::STATUS_FIELD])->toBe(Constants::STATUS_CANCELLED);
        }
    });

    it('reports back a cancellation that fails to write', function () {
        $this->makePost(700, 'An answer', 'publish', Constants::ANSWER_CUSTOM_TYPE);
        Functions\when('update_field')->justReturn(false);
        $_POST = ['nonce' => 'nonce-confur_cancel_duplicate', 'answer_id' => '700'];

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeFalse()
                ->and($e->data['message'])->toBe('Failed to cancel registration');
        }
    });
});

// ── resending a confirmation ──────────────────────────────────────
describe('resending a confirmation', function () {
    it('refuses a request without a nonce', function () {
        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('Invalid security token');
        }
    });

    it('refuses a user without the capability', function () {
        $_POST['nonce'] = 'nonce-confur_resend_confirmation';
        WpState::$userCan = false;

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('Insufficient permissions');
        }
    });

    it('refuses a missing answer id', function () {
        $_POST = ['nonce' => 'nonce-confur_resend_confirmation'];

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('Invalid answer ID');
        }
    });

    it('refuses an id that is not an answer', function () {
        $this->makePost(700, 'A meeting', 'publish', 'tsml_meeting');
        $_POST = ['nonce' => 'nonce-confur_resend_confirmation', 'answer_id' => '700'];

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('Answer not found');
        }
    });

    it('refuses an answer without a usable email', function (mixed $email) {
        ($this->seedResendable)(700);
        $this->fields[700] = [
            Constants::EMAIL_FIELD   => $email,
            Constants::MEETING_FIELD => 500,
        ];

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('No valid email address found for this registration');
        }
    })->with([
        'missing'    => [null],
        'empty'      => [''],
        'malformed'  => ['not-an-email'],
    ]);

    it('refuses an answer with no meeting', function () {
        ($this->seedResendable)(700);
        $this->fields[700] = [
            Constants::EMAIL_FIELD   => 'group@example.org',
            Constants::MEETING_FIELD => null,
        ];

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->data['message'])->toBe('No meeting associated with this registration');
        }
    });

    it('sends the confirmation to the registered address', function () {
        ($this->seedResendable)(700);

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeTrue()
                ->and($e->data['message'])->toContain('group@example.org');
        }

        expect(WpState::$mail)->toHaveCount(1)
            ->and(WpState::$mail[0]['to'])->toBe('group@example.org')
            ->and(WpState::$mail[0]['message'])->toContain('Monday Group');
    });

    // A paired registration's email names both meetings, matching what
    // AnswerHandler sends at registration time.
    it('names both meetings in the email for a paired registration', function () {
        ($this->seedResendable)(700, 500, 501);
        $this->makePost(501, 'Tuesday Group', 'publish', 'tsml_meeting');

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeTrue();
        }

        expect(WpState::$mail[0]['message'])->toContain('Monday Group and Tuesday Group');
    });

    it('reports back a confirmation that fails to send', function () {
        ($this->seedResendable)(700);
        WpState::$mailResult = false;

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeFalse()
                ->and($e->data['message'])->toBe('Failed to send confirmation email');
        }
    });
});

// ── joining meetings against registrations ────────────────────────
describe('joining meetings against registrations', function () {
    it('lists a meeting with no registration as unregistered', function () {
        ($this->seedMeeting)(500, 'Monday Group');

        $rows = statusMeetingsData($this->page);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['is_registered'])->toBeFalse()
            ->and($rows[0]['status_label'])->toBe('Unregistered')
            ->and($rows[0]['row_class'])->toBe('unregistered-row')
            ->and($rows[0]['answer_id'])->toBeNull()
            ->and($rows[0]['last_saved'])->toBe('-');
    });

    it('carries the answer id, status and email of a registered meeting', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500);

        $rows = statusMeetingsData($this->page);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['is_registered'])->toBeTrue()
            ->and($rows[0]['answer_id'])->toBe(1)
            ->and($rows[0]['status_label'])->toBe('Completed')
            ->and($rows[0]['row_class'])->toBe('registered-row')
            ->and($rows[0]['email_html'])->toContain('group@example.org')
            ->and($rows[0]['last_saved'])->toBe('2026-07-24 10:00:00');
    });

    // A paired registration is indexed under both meetings so either one finds
    // it, but it must still produce one row rather than two.
    it('lists a paired registration once across both meetings', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedMeeting)(501, 'Tuesday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_COMPLETED, 'group@example.org', 501);

        $rows = statusMeetingsData($this->page);

        expect($rows)->toHaveCount(1, 'the pair should collapse to a single row')
            ->and($rows[0]['name'])->toBe('Monday Group and Tuesday Group');
    });

    it('marks a cancelled registration as such', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_CANCELLED);

        $rows = statusMeetingsData($this->page);

        expect($rows[0]['status_class'])->toBe('cancelled')
            ->and($rows[0]['row_class'])->toBe('cancelled-row');
    });

    it('reads a registration that has never been saved as not started', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500, '', 'group@example.org', null, '   ');

        $rows = statusMeetingsData($this->page);

        expect($rows[0]['status_label'])->toBe('Not Started')
            ->and($rows[0]['last_saved'])->toBe('Not Started');
    });

    it('renders a dash for a registration without an email', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_COMPLETED, '');

        expect(statusMeetingsData($this->page)[0]['email_html'])->toBe('-');
    });

    // Registered meetings sort above unregistered ones, and each block sorts
    // by name — the screen is read as "who has signed up" first.
    it('sorts registered meetings first, then alphabetically', function () {
        ($this->seedMeeting)(500, 'Zed Group');
        ($this->seedMeeting)(501, 'Alpha Group');
        ($this->seedMeeting)(502, 'Beta Group');
        ($this->seedRegistration)(1, 500);

        expect(array_column(statusMeetingsData($this->page), 'name'))
            ->toBe(['Zed Group', 'Alpha Group', 'Beta Group']);
    });

    it("renders a meeting's contacts as telephone links", function () {
        ($this->seedMeeting)(500, 'Monday Group');
        WpState::$postMeta[500] += [
            'contact_1_name'  => ['Alice'],
            'contact_1_phone' => ['0117 000 0000'],
            'contact_2_name'  => ['Bob'],
            'contact_2_phone' => ['0117 111 1111'],
        ];

        $row = statusMeetingsData($this->page)[0];

        expect($row['contact1_html'])->toContain('Alice', '0117 000 0000')
            ->and($row['contact2_html'])->toContain('Bob');
    });

    it('renders dashes for a meeting without contacts', function () {
        ($this->seedMeeting)(500, 'Monday Group');

        $row = statusMeetingsData($this->page)[0];

        expect($row['contact1_html'])->toBe('-')
            ->and($row['contact2_html'])->toBe('-');
    });
});

// ── day names ─────────────────────────────────────────────────────
describe('day names', function () {
    // TSML stores the day as a number with Sunday at 0, and the screen has to
    // show a name. A value that is already a name passes straight through.
    it('renders the day number as a name', function (mixed $stored, string $expected) {
        $m = new ReflectionMethod(StatusAdminPage::class, 'getDayName');

        expect($m->invoke($this->page, $stored))->toBe($expected);
    })->with([
        'sunday'          => [0, 'Sunday'],
        'monday'          => [1, 'Monday'],
        'saturday'        => [6, 'Saturday'],
        'a numeric string' => ['3', 'Wednesday'],
        'already a name'  => ['Thursday', 'Thursday'],
        'empty'           => ['', ''],
        'out of range'    => [9, '9'],
    ]);

    // Inside WordPress the day name comes from $wp_locale, so it is
    // translated; the hard-coded list above is only the fallback for when that
    // global is not available. This is the path that actually runs in
    // production.
    it('takes the day name from the locale when one is available', function () {
        $GLOBALS['wp_locale'] = new class {
            public function get_weekday(int $day): string
            {
                return ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$day];
            }
        };

        try {
            $m = new ReflectionMethod(StatusAdminPage::class, 'getDayName');

            expect($m->invoke($this->page, 1))->toBe('Lundi');
        } finally {
            unset($GLOBALS['wp_locale']);
        }
    });
});

// ── statistics ────────────────────────────────────────────────────
describe('statistics', function () {
    // Totals count distinct meetings, but the status counters count
    // registrations — two registrations against one meeting is one meeting and
    // two completions.
    it('counts meetings in the totals while the status counters count registrations', function () {
        $stats = statusStats($this->page, [
            ['id' => 500, 'is_registered' => true,  'status_class' => 'completed'],
            ['id' => 500, 'is_registered' => true,  'status_class' => 'draft'],
            ['id' => 501, 'is_registered' => false, 'status_class' => 'unregistered'],
        ]);

        expect($stats['total'])->toBe(2, 'two distinct meetings')
            ->and($stats['registered'])->toBe(1, 'one distinct registered meeting')
            ->and($stats['unregistered'])->toBe(1)
            ->and($stats['completed'])->toBe(1)
            ->and($stats['draft'])->toBe(1);
    });

    it('gives every status its own counter', function () {
        $stats = statusStats($this->page, [
            ['id' => 500, 'is_registered' => true, 'status_class' => 'completed'],
            ['id' => 501, 'is_registered' => true, 'status_class' => 'draft'],
            ['id' => 502, 'is_registered' => true, 'status_class' => 'not-started'],
            ['id' => 503, 'is_registered' => true, 'status_class' => 'cancelled'],
        ]);

        expect($stats['completed'])->toBe(1)
            ->and($stats['draft'])->toBe(1)
            ->and($stats['not_started'])->toBe(1)
            ->and($stats['cancelled'])->toBe(1);
    });

    it('reports zero everywhere for an empty screen', function () {
        expect(statusStats($this->page, []))->toBe(
            ['total' => 0, 'registered' => 0, 'unregistered' => 0, 'completed' => 0,
             'draft' => 0, 'not_started' => 0, 'cancelled' => 0]
        );
    });

    it('maps each stored status to a label and a css class', function (string $stored, string $label, string $class) {
        $m = new ReflectionMethod(StatusAdminPage::class, 'getStatusInfo');

        expect($m->invoke($this->page, $stored))->toBe(['label' => $label, 'class' => $class]);
    })->with([
        'the completed constant' => [Constants::STATUS_COMPLETED, 'Completed', 'completed'],
        'lowercase completed'    => ['completed', 'Completed', 'completed'],
        'the draft constant'     => [Constants::STATUS_DRAFT, 'Draft', 'draft'],
        'lowercase draft'        => ['draft', 'Draft', 'draft'],
        'the cancelled constant' => [Constants::STATUS_CANCELLED, 'Cancelled', 'cancelled'],
        'lowercase cancelled'    => ['cancelled', 'Cancelled', 'cancelled'],
        'anything else'          => ['Sideways', 'Not Started', 'not-started'],
    ]);
});

// ── duplicate detection ───────────────────────────────────────────
describe('duplicate detection', function () {
    it('treats two registrations for the same meeting and email as a duplicate', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500);
        ($this->seedRegistration)(2, 500);

        $duplicates = statusDuplicates($this->page);

        expect(array_keys($duplicates))->toBe([500])
            ->and($duplicates[500]['count'])->toBe(2)
            ->and($duplicates[500]['name'])->toContain('Monday Group', 'group@example.org');
    });

    it('does not treat a single registration as a duplicate', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500);

        expect(statusDuplicates($this->page))->toBe([]);
    });

    it('does not treat two different addresses on one meeting as duplicates', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_COMPLETED, 'one@example.org');
        ($this->seedRegistration)(2, 500, Constants::STATUS_COMPLETED, 'two@example.org');

        expect(statusDuplicates($this->page))->toBe([]);
    });

    // The address is normalised before comparison, so a re-registration typed
    // with different capitalisation still counts as the same person.
    it('compares addresses case-insensitively', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_COMPLETED, 'Group@Example.org');
        ($this->seedRegistration)(2, 500, Constants::STATUS_COMPLETED, 'group@example.org');

        expect(statusDuplicates($this->page)[500]['count'])->toBe(2);
    });

    // Cancelling a duplicate is how the screen's own button resolves one, so a
    // cancelled registration must stop counting towards the warning.
    it('no longer counts a cancelled registration as a duplicate', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500);
        ($this->seedRegistration)(2, 500, Constants::STATUS_CANCELLED);

        expect(statusDuplicates($this->page))->toBe([]);
    });

    // A paired registration only collides with another paired one — a group
    // that also signed up alone is a different registration, not a duplicate.
    it('does not collide a paired registration with a single one', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedMeeting)(501, 'Tuesday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_COMPLETED, 'group@example.org', 501);
        ($this->seedRegistration)(2, 500);

        expect(statusDuplicates($this->page))->toBe([]);
    });

    // The pair is compared as a sorted set, so registering Monday+Tuesday and
    // then Tuesday+Monday is the same registration twice.
    it('treats a pair registered in either order as the same pair', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedMeeting)(501, 'Tuesday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_COMPLETED, 'group@example.org', 501);
        ($this->seedRegistration)(2, 501, Constants::STATUS_COMPLETED, 'group@example.org', 500);

        $duplicates = statusDuplicates($this->page);

        expect(array_keys($duplicates))->toBe([500], 'keyed on the lowest meeting id')
            ->and($duplicates[500]['count'])->toBe(2);
    });

    it('skips a registration missing its email', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_COMPLETED, '');
        ($this->seedRegistration)(2, 500, Constants::STATUS_COMPLETED, '');

        expect(statusDuplicates($this->page))->toBe([]);
    });
});

// ── the screen ────────────────────────────────────────────────────
describe('the screen', function () {
    it('refuses a user without the capability', function () {
        WpState::$userCan = false;

        $this->page->renderAdminPage();
    })->throws(WpDieException::class);

    it('says so on an empty screen rather than rendering an empty table', function () {
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('No meetings found.')
            ->not->toContain('<table class="confur-answers-table">');
    });

    it('renders a row per meeting with its stats', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedMeeting)(501, 'Tuesday Group');
        ($this->seedRegistration)(1, 500);

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain(
            'Monday Group',
            'Tuesday Group',
            'class="registered-row"',
            'class="unregistered-row"',
            'Total Meetings',
            'resend-confirmation-btn',
        );
    });

    it('warns about duplicates and offers a cancel button', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500);
        ($this->seedRegistration)(2, 500);

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain(
            'Duplicate registrations detected!',
            'duplicate-indicator',
            'cancel-duplicate-btn',
            'class="duplicate-row"',
        );
    });

    // A cancelled registration keeps its row but loses the resend button —
    // there is nothing left to confirm.
    it('offers no resend button on a cancelled row', function () {
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_CANCELLED);

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('class="cancelled-row"')
            ->not->toContain('resend-confirmation-btn"');
    });

    it('hides cancelled registrations when the screen option is turned off', function () {
        $this->userMeta['1|confur_show_cancellations'] = 0;
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedMeeting)(501, 'Tuesday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_CANCELLED);
        ($this->seedRegistration)(2, 501);

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->not->toContain('class="cancelled-row"')
            ->toContain('Tuesday Group');
    });

    // Hiding cancellations is a display filter, not a data filter — the
    // counters still report them so the totals stay honest.
    it('still counts hidden cancellations in the statistics', function () {
        $this->userMeta['1|confur_show_cancellations'] = 0;
        ($this->seedMeeting)(500, 'Monday Group');
        ($this->seedRegistration)(1, 500, Constants::STATUS_CANCELLED);

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toMatch('/<div class="number">1<\/div>\s*<div class="label">Cancelled<\/div>/');
    });
});
