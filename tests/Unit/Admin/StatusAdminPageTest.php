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
use Tests\ConfurTestCase;
use WP_Screen;

/**
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
 *
 * @covers \Confur\Admin\StatusAdminPage
 */
final class StatusAdminPageTest extends ConfurTestCase
{
    private const SCREEN = 'questions-for-conference_page_confur-answer-submissions';

    private StatusAdminPage $page;

    /** @var array<string, mixed> Stands in for the user meta store. */
    private array $userMeta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];
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
    }

    protected function tearDown(): void
    {
        $_POST = [];

        parent::tearDown();
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

    /** Seed a TSML meeting the status page will list. */
    private function seedMeeting(int $id, string $title, string $day = '1', string $time = '19:00'): void
    {
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
    }

    /** Seed an answer post registered against a meeting. */
    private function seedRegistration(
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
    }

    /** @return array<int, array<string, mixed>> */
    private function meetingsData(): array
    {
        $m = new ReflectionMethod(StatusAdminPage::class, 'getAllMeetingsData');

        /** @var array<int, array<string, mixed>> $data */
        $data = $m->invoke($this->page);

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $meetings
     * @return array<string, mixed>
     */
    private function stats(array $meetings): array
    {
        $m = new ReflectionMethod(StatusAdminPage::class, 'calculateStats');

        /** @var array<string, mixed> $stats */
        $stats = $m->invoke($this->page, $meetings);

        return $stats;
    }

    /** @return array<int, array<string, mixed>> */
    private function duplicates(): array
    {
        $m = new ReflectionMethod(StatusAdminPage::class, 'findDuplicateRegistrations');

        /** @var array<int, array<string, mixed>> $duplicates */
        $duplicates = $m->invoke($this->page, []);

        return $duplicates;
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_registers_the_menu_the_assets_and_all_three_ajax_endpoints(): void
    {
        $this->page->init();

        foreach ([
            'admin_menu',
            'admin_enqueue_scripts',
            'wp_ajax_confur_cancel_duplicate',
            'wp_ajax_confur_resend_confirmation',
            'load-' . self::SCREEN,
            'wp_ajax_confur_save_screen_option',
        ] as $hook) {
            $this->assertActionAdded($hook, false, 'expected ' . $hook . ' to be hooked');
        }
    }

    /** @test */
    public function nothing_is_registered_on_a_front_end_request(): void
    {
        WpState::$isAdmin = false;

        $this->page->init();

        $this->assertActionNotAdded('admin_menu');
        $this->assertActionNotAdded('wp_ajax_confur_cancel_duplicate');
    }

    /** @test */
    public function the_page_is_added_under_the_confur_menu_for_any_logged_in_user(): void
    {
        $this->page->addAdminMenu();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame('confur', WpState::$menus[0]['parent']);
        $this->assertSame('confur-answer-submissions', WpState::$menus[0]['slug']);
        $this->assertSame('read', WpState::$menus[0]['cap']);
    }

    /** @test */
    public function the_page_assets_are_only_loaded_on_this_screen(): void
    {
        $this->page->enqueueAdminAssets('edit.php');

        $this->assertSame([], WpState::$enqueued);
    }

    /** @test */
    public function the_page_styles_and_scripts_are_loaded_on_this_screen(): void
    {
        $this->page->enqueueAdminAssets(self::SCREEN);

        $this->assertSame(
            [
                ['fn' => 'wp_add_inline_style', 'handle' => 'wp-admin'],
                ['fn' => 'wp_add_inline_script', 'handle' => 'jquery'],
            ],
            WpState::$enqueued
        );
    }

    // ── screen options ────────────────────────────────────────────────

    /** @test */
    public function the_screen_options_filter_is_registered_when_the_screen_loads(): void
    {
        $this->page->addScreenOptions();

        $this->assertFilterAdded('screen_settings', false, 'the screen options should be registered');
    }

    /** @test */
    public function the_screen_options_are_not_added_to_another_screen(): void
    {
        $screen = new WP_Screen(['id' => 'edit-post']);

        $this->assertSame('existing', $this->page->renderScreenOptions('existing', $screen));
    }

    /** @test */
    public function the_screen_options_add_a_show_cancellations_toggle(): void
    {
        $screen = new WP_Screen(['id' => self::SCREEN]);

        $html = $this->page->renderScreenOptions('existing', $screen);

        $this->assertStringStartsWith('existing', $html, 'the existing settings should be preserved');
        $this->assertStringContainsString('id="confur_show_cancellations"', $html);
        $this->assertStringContainsString('confur_save_screen_option', $html, 'the toggle should post to the save endpoint');
    }

    /**
     * A user who has never touched the toggle should see cancellations, so an
     * unset preference has to read as on rather than as off.
     *
     * @test
     */
    public function the_toggle_defaults_to_on_for_a_user_who_has_never_set_it(): void
    {
        $html = $this->page->renderScreenOptions('', new WP_Screen(['id' => self::SCREEN]));

        $this->assertStringContainsString('checked="checked"', $html);
    }

    /** @test */
    public function the_toggle_reflects_a_saved_preference_of_off(): void
    {
        $this->userMeta['1|confur_show_cancellations'] = 0;

        $html = $this->page->renderScreenOptions('', new WP_Screen(['id' => self::SCREEN]));

        $this->assertStringNotContainsString('checked="checked"', $html);
    }

    /** @test */
    public function saving_the_screen_option_stores_it_against_the_current_user(): void
    {
        $_POST['show_cancellations'] = '1';

        try {
            $this->page->handleSaveScreenOption();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertSame(1, $this->userMeta['1|confur_show_cancellations']);
        }
    }

    /** @test */
    public function clearing_the_screen_option_stores_zero(): void
    {
        $_POST['show_cancellations'] = '0';

        try {
            $this->page->handleSaveScreenOption();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame(0, $this->userMeta['1|confur_show_cancellations']);
        }
    }

    // ── cancelling a duplicate ────────────────────────────────────────

    /** @test */
    public function cancelling_refuses_a_request_without_a_nonce(): void
    {
        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
            $this->assertSame('Invalid security token', $e->data['message']);
        }
    }

    /** @test */
    public function cancelling_refuses_a_user_without_the_capability(): void
    {
        $_POST['nonce'] = 'nonce-confur_cancel_duplicate';
        WpState::$userCan = false;

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('Insufficient permissions', $e->data['message']);
        }
    }

    /** @test */
    public function cancelling_refuses_a_missing_or_unusable_answer_id(): void
    {
        $_POST = ['nonce' => 'nonce-confur_cancel_duplicate'];

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('Invalid answer ID', $e->data['message']);
        }
    }

    /**
     * The id arrives from the browser, so it is re-checked against the post
     * type rather than trusted to point at an answer.
     *
     * @test
     */
    public function cancelling_refuses_an_id_that_is_not_an_answer(): void
    {
        $this->makePost(700, 'A meeting', 'publish', 'tsml_meeting');
        $_POST = ['nonce' => 'nonce-confur_cancel_duplicate', 'answer_id' => '700'];

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('Answer not found', $e->data['message']);
        }
    }

    /** @test */
    public function cancelling_sets_the_status_to_cancelled(): void
    {
        $this->makePost(700, 'An answer', 'publish', Constants::ANSWER_CUSTOM_TYPE);
        $_POST = ['nonce' => 'nonce-confur_cancel_duplicate', 'answer_id' => '700'];

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertSame(
                Constants::STATUS_CANCELLED,
                WpState::$fields['700|' . Constants::STATUS_FIELD]
            );
        }
    }

    /** @test */
    public function a_cancellation_that_fails_to_write_is_reported_back(): void
    {
        $this->makePost(700, 'An answer', 'publish', Constants::ANSWER_CUSTOM_TYPE);
        Functions\when('update_field')->justReturn(false);
        $_POST = ['nonce' => 'nonce-confur_cancel_duplicate', 'answer_id' => '700'];

        try {
            $this->page->handleCancelDuplicate();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
            $this->assertSame('Failed to cancel registration', $e->data['message']);
        }
    }

    // ── resending a confirmation ──────────────────────────────────────

    /** Seed an answer the resend handler will accept. */
    private function seedResendable(int $postId, int $meetingId = 500, ?int $fellowMeetingId = null): void
    {
        $this->makePost($postId, 'An answer', 'publish', Constants::ANSWER_CUSTOM_TYPE);
        $this->makePost($meetingId, 'Monday Group', 'publish', 'tsml_meeting');
        $this->fields[$postId] = [
            Constants::EMAIL_FIELD          => 'group@example.org',
            Constants::MEETING_FIELD        => $meetingId,
            Constants::FELLOW_MEETING_FIELD => $fellowMeetingId,
        ];
        $_POST = ['nonce' => 'nonce-confur_resend_confirmation', 'answer_id' => (string) $postId];
    }

    /** @test */
    public function resending_refuses_a_request_without_a_nonce(): void
    {
        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('Invalid security token', $e->data['message']);
        }
    }

    /** @test */
    public function resending_refuses_a_user_without_the_capability(): void
    {
        $_POST['nonce'] = 'nonce-confur_resend_confirmation';
        WpState::$userCan = false;

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('Insufficient permissions', $e->data['message']);
        }
    }

    /** @test */
    public function resending_refuses_a_missing_answer_id(): void
    {
        $_POST = ['nonce' => 'nonce-confur_resend_confirmation'];

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('Invalid answer ID', $e->data['message']);
        }
    }

    /** @test */
    public function resending_refuses_an_id_that_is_not_an_answer(): void
    {
        $this->makePost(700, 'A meeting', 'publish', 'tsml_meeting');
        $_POST = ['nonce' => 'nonce-confur_resend_confirmation', 'answer_id' => '700'];

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('Answer not found', $e->data['message']);
        }
    }

    /**
     * @test
     * @dataProvider unusableEmails
     */
    public function resending_refuses_an_answer_without_a_usable_email(mixed $email): void
    {
        $this->seedResendable(700);
        $this->fields[700] = [
            Constants::EMAIL_FIELD   => $email,
            Constants::MEETING_FIELD => 500,
        ];

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('No valid email address found for this registration', $e->data['message']);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableEmails(): array
    {
        return [
            'missing'    => [null],
            'empty'      => [''],
            'malformed'  => ['not-an-email'],
        ];
    }

    /** @test */
    public function resending_refuses_an_answer_with_no_meeting(): void
    {
        $this->seedResendable(700);
        $this->fields[700] = [
            Constants::EMAIL_FIELD   => 'group@example.org',
            Constants::MEETING_FIELD => null,
        ];

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertSame('No meeting associated with this registration', $e->data['message']);
        }
    }

    /** @test */
    public function resending_sends_the_confirmation_to_the_registered_address(): void
    {
        $this->seedResendable(700);

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertStringContainsString('group@example.org', $e->data['message']);
        }

        $this->assertCount(1, WpState::$mail);
        $this->assertSame('group@example.org', WpState::$mail[0]['to']);
        $this->assertStringContainsString('Monday Group', WpState::$mail[0]['message']);
    }

    /**
     * A paired registration's email names both meetings, matching what
     * AnswerHandler sends at registration time.
     *
     * @test
     */
    public function a_paired_registration_names_both_meetings_in_the_email(): void
    {
        $this->seedResendable(700, 500, 501);
        $this->makePost(501, 'Tuesday Group', 'publish', 'tsml_meeting');

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
        }

        $this->assertStringContainsString('Monday Group and Tuesday Group', WpState::$mail[0]['message']);
    }

    /** @test */
    public function a_confirmation_that_fails_to_send_is_reported_back(): void
    {
        $this->seedResendable(700);
        WpState::$mailResult = false;

        try {
            $this->page->handleResendConfirmation();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
            $this->assertSame('Failed to send confirmation email', $e->data['message']);
        }
    }

    // ── joining meetings against registrations ────────────────────────

    /** @test */
    public function a_meeting_with_no_registration_is_listed_as_unregistered(): void
    {
        $this->seedMeeting(500, 'Monday Group');

        $rows = $this->meetingsData();

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['is_registered']);
        $this->assertSame('Unregistered', $rows[0]['status_label']);
        $this->assertSame('unregistered-row', $rows[0]['row_class']);
        $this->assertNull($rows[0]['answer_id']);
        $this->assertSame('-', $rows[0]['last_saved']);
    }

    /** @test */
    public function a_registered_meeting_carries_its_answer_id_status_and_email(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500);

        $rows = $this->meetingsData();

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_registered']);
        $this->assertSame(1, $rows[0]['answer_id']);
        $this->assertSame('Completed', $rows[0]['status_label']);
        $this->assertSame('registered-row', $rows[0]['row_class']);
        $this->assertStringContainsString('group@example.org', $rows[0]['email_html']);
        $this->assertSame('2026-07-24 10:00:00', $rows[0]['last_saved']);
    }

    /**
     * A paired registration is indexed under both meetings so either one finds
     * it, but it must still produce one row rather than two.
     *
     * @test
     */
    public function a_paired_registration_is_listed_once_across_both_meetings(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedMeeting(501, 'Tuesday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_COMPLETED, 'group@example.org', 501);

        $rows = $this->meetingsData();

        $this->assertCount(1, $rows, 'the pair should collapse to a single row');
        $this->assertSame('Monday Group and Tuesday Group', $rows[0]['name']);
    }

    /** @test */
    public function a_cancelled_registration_is_marked_as_such(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_CANCELLED);

        $rows = $this->meetingsData();

        $this->assertSame('cancelled', $rows[0]['status_class']);
        $this->assertSame('cancelled-row', $rows[0]['row_class']);
    }

    /** @test */
    public function a_registration_that_has_never_been_saved_reads_as_not_started(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500, '', 'group@example.org', null, '   ');

        $rows = $this->meetingsData();

        $this->assertSame('Not Started', $rows[0]['status_label']);
        $this->assertSame('Not Started', $rows[0]['last_saved']);
    }

    /** @test */
    public function a_registration_without_an_email_renders_a_dash(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_COMPLETED, '');

        $this->assertSame('-', $this->meetingsData()[0]['email_html']);
    }

    /**
     * Registered meetings sort above unregistered ones, and each block sorts
     * by name — the screen is read as "who has signed up" first.
     *
     * @test
     */
    public function registered_meetings_sort_first_then_alphabetically(): void
    {
        $this->seedMeeting(500, 'Zed Group');
        $this->seedMeeting(501, 'Alpha Group');
        $this->seedMeeting(502, 'Beta Group');
        $this->seedRegistration(1, 500);

        $this->assertSame(
            ['Zed Group', 'Alpha Group', 'Beta Group'],
            array_column($this->meetingsData(), 'name')
        );
    }

    /** @test */
    public function a_meetings_contacts_are_rendered_as_telephone_links(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        WpState::$postMeta[500] += [
            'contact_1_name'  => ['Alice'],
            'contact_1_phone' => ['0117 000 0000'],
            'contact_2_name'  => ['Bob'],
            'contact_2_phone' => ['0117 111 1111'],
        ];

        $row = $this->meetingsData()[0];

        $this->assertStringContainsString('Alice', $row['contact1_html']);
        $this->assertStringContainsString('0117 000 0000', $row['contact1_html']);
        $this->assertStringContainsString('Bob', $row['contact2_html']);
    }

    /** @test */
    public function a_meeting_without_contacts_renders_dashes(): void
    {
        $this->seedMeeting(500, 'Monday Group');

        $row = $this->meetingsData()[0];

        $this->assertSame('-', $row['contact1_html']);
        $this->assertSame('-', $row['contact2_html']);
    }

    // ── day names ─────────────────────────────────────────────────────

    /**
     * TSML stores the day as a number with Sunday at 0, and the screen has to
     * show a name. A value that is already a name passes straight through.
     *
     * @test
     * @dataProvider days
     */
    public function the_day_number_is_rendered_as_a_name(mixed $stored, string $expected): void
    {
        $m = new ReflectionMethod(StatusAdminPage::class, 'getDayName');

        $this->assertSame($expected, $m->invoke($this->page, $stored));
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function days(): array
    {
        return [
            'sunday'          => [0, 'Sunday'],
            'monday'          => [1, 'Monday'],
            'saturday'        => [6, 'Saturday'],
            'a numeric string' => ['3', 'Wednesday'],
            'already a name'  => ['Thursday', 'Thursday'],
            'empty'           => ['', ''],
            'out of range'    => [9, '9'],
        ];
    }

    /**
     * Inside WordPress the day name comes from $wp_locale, so it is
     * translated; the hard-coded list above is only the fallback for when that
     * global is not available. This is the path that actually runs in
     * production.
     *
     * @test
     */
    public function the_day_name_is_taken_from_the_locale_when_one_is_available(): void
    {
        $GLOBALS['wp_locale'] = new class {
            public function get_weekday(int $day): string
            {
                return ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$day];
            }
        };

        try {
            $m = new ReflectionMethod(StatusAdminPage::class, 'getDayName');

            $this->assertSame('Lundi', $m->invoke($this->page, 1));
        } finally {
            unset($GLOBALS['wp_locale']);
        }
    }

    // ── statistics ────────────────────────────────────────────────────

    /**
     * Totals count distinct meetings, but the status counters count
     * registrations — two registrations against one meeting is one meeting and
     * two completions.
     *
     * @test
     */
    public function totals_count_meetings_while_status_counters_count_registrations(): void
    {
        $stats = $this->stats([
            ['id' => 500, 'is_registered' => true,  'status_class' => 'completed'],
            ['id' => 500, 'is_registered' => true,  'status_class' => 'draft'],
            ['id' => 501, 'is_registered' => false, 'status_class' => 'unregistered'],
        ]);

        $this->assertSame(2, $stats['total'], 'two distinct meetings');
        $this->assertSame(1, $stats['registered'], 'one distinct registered meeting');
        $this->assertSame(1, $stats['unregistered']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['draft']);
    }

    /** @test */
    public function every_status_has_its_own_counter(): void
    {
        $stats = $this->stats([
            ['id' => 500, 'is_registered' => true, 'status_class' => 'completed'],
            ['id' => 501, 'is_registered' => true, 'status_class' => 'draft'],
            ['id' => 502, 'is_registered' => true, 'status_class' => 'not-started'],
            ['id' => 503, 'is_registered' => true, 'status_class' => 'cancelled'],
        ]);

        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['draft']);
        $this->assertSame(1, $stats['not_started']);
        $this->assertSame(1, $stats['cancelled']);
    }

    /** @test */
    public function an_empty_screen_reports_zero_everywhere(): void
    {
        $this->assertSame(
            ['total' => 0, 'registered' => 0, 'unregistered' => 0, 'completed' => 0,
             'draft' => 0, 'not_started' => 0, 'cancelled' => 0],
            $this->stats([])
        );
    }

    /**
     * @test
     * @dataProvider statusValues
     */
    public function each_stored_status_maps_to_a_label_and_a_css_class(
        string $stored,
        string $label,
        string $class
    ): void {
        $m = new ReflectionMethod(StatusAdminPage::class, 'getStatusInfo');

        $this->assertSame(['label' => $label, 'class' => $class], $m->invoke($this->page, $stored));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function statusValues(): array
    {
        return [
            'the completed constant' => [Constants::STATUS_COMPLETED, 'Completed', 'completed'],
            'lowercase completed'    => ['completed', 'Completed', 'completed'],
            'the draft constant'     => [Constants::STATUS_DRAFT, 'Draft', 'draft'],
            'lowercase draft'        => ['draft', 'Draft', 'draft'],
            'the cancelled constant' => [Constants::STATUS_CANCELLED, 'Cancelled', 'cancelled'],
            'lowercase cancelled'    => ['cancelled', 'Cancelled', 'cancelled'],
            'anything else'          => ['Sideways', 'Not Started', 'not-started'],
        ];
    }

    // ── duplicate detection ───────────────────────────────────────────

    /** @test */
    public function two_registrations_for_the_same_meeting_and_email_are_a_duplicate(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500);
        $this->seedRegistration(2, 500);

        $duplicates = $this->duplicates();

        $this->assertSame([500], array_keys($duplicates));
        $this->assertSame(2, $duplicates[500]['count']);
        $this->assertStringContainsString('Monday Group', $duplicates[500]['name']);
        $this->assertStringContainsString('group@example.org', $duplicates[500]['name']);
    }

    /** @test */
    public function a_single_registration_is_not_a_duplicate(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500);

        $this->assertSame([], $this->duplicates());
    }

    /** @test */
    public function two_different_addresses_on_one_meeting_are_not_duplicates(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_COMPLETED, 'one@example.org');
        $this->seedRegistration(2, 500, Constants::STATUS_COMPLETED, 'two@example.org');

        $this->assertSame([], $this->duplicates());
    }

    /**
     * The address is normalised before comparison, so a re-registration typed
     * with different capitalisation still counts as the same person.
     *
     * @test
     */
    public function addresses_are_compared_case_insensitively(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_COMPLETED, 'Group@Example.org');
        $this->seedRegistration(2, 500, Constants::STATUS_COMPLETED, 'group@example.org');

        $this->assertSame(2, $this->duplicates()[500]['count']);
    }

    /**
     * Cancelling a duplicate is how the screen's own button resolves one, so a
     * cancelled registration must stop counting towards the warning.
     *
     * @test
     */
    public function a_cancelled_registration_no_longer_counts_as_a_duplicate(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500);
        $this->seedRegistration(2, 500, Constants::STATUS_CANCELLED);

        $this->assertSame([], $this->duplicates());
    }

    /**
     * A paired registration only collides with another paired one — a group
     * that also signed up alone is a different registration, not a duplicate.
     *
     * @test
     */
    public function a_paired_registration_does_not_collide_with_a_single_one(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedMeeting(501, 'Tuesday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_COMPLETED, 'group@example.org', 501);
        $this->seedRegistration(2, 500);

        $this->assertSame([], $this->duplicates());
    }

    /**
     * The pair is compared as a sorted set, so registering Monday+Tuesday and
     * then Tuesday+Monday is the same registration twice.
     *
     * @test
     */
    public function a_pair_registered_in_either_order_is_the_same_pair(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedMeeting(501, 'Tuesday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_COMPLETED, 'group@example.org', 501);
        $this->seedRegistration(2, 501, Constants::STATUS_COMPLETED, 'group@example.org', 500);

        $duplicates = $this->duplicates();

        $this->assertSame([500], array_keys($duplicates), 'keyed on the lowest meeting id');
        $this->assertSame(2, $duplicates[500]['count']);
    }

    /** @test */
    public function a_registration_missing_its_email_is_skipped(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_COMPLETED, '');
        $this->seedRegistration(2, 500, Constants::STATUS_COMPLETED, '');

        $this->assertSame([], $this->duplicates());
    }

    // ── the screen ────────────────────────────────────────────────────

    /** @test */
    public function the_screen_refuses_a_user_without_the_capability(): void
    {
        WpState::$userCan = false;

        $this->expectException(WpDieException::class);
        $this->page->renderAdminPage();
    }

    /** @test */
    public function an_empty_screen_says_so_rather_than_rendering_an_empty_table(): void
    {
        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString('No meetings found.', $html);
        $this->assertStringNotContainsString('<table class="confur-answers-table">', $html);
    }

    /** @test */
    public function the_screen_renders_a_row_per_meeting_with_its_stats(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedMeeting(501, 'Tuesday Group');
        $this->seedRegistration(1, 500);

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString('Monday Group', $html);
        $this->assertStringContainsString('Tuesday Group', $html);
        $this->assertStringContainsString('class="registered-row"', $html);
        $this->assertStringContainsString('class="unregistered-row"', $html);
        $this->assertStringContainsString('Total Meetings', $html);
        $this->assertStringContainsString('resend-confirmation-btn', $html);
    }

    /** @test */
    public function the_screen_warns_about_duplicates_and_offers_a_cancel_button(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500);
        $this->seedRegistration(2, 500);

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString('Duplicate registrations detected!', $html);
        $this->assertStringContainsString('duplicate-indicator', $html);
        $this->assertStringContainsString('cancel-duplicate-btn', $html);
        $this->assertStringContainsString('class="duplicate-row"', $html);
    }

    /**
     * A cancelled registration keeps its row but loses the resend button —
     * there is nothing left to confirm.
     *
     * @test
     */
    public function a_cancelled_row_offers_no_resend_button(): void
    {
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_CANCELLED);

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString('class="cancelled-row"', $html);
        $this->assertStringNotContainsString('resend-confirmation-btn"', $html);
    }

    /** @test */
    public function turning_the_screen_option_off_hides_cancelled_registrations(): void
    {
        $this->userMeta['1|confur_show_cancellations'] = 0;
        $this->seedMeeting(500, 'Monday Group');
        $this->seedMeeting(501, 'Tuesday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_CANCELLED);
        $this->seedRegistration(2, 501);

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringNotContainsString('class="cancelled-row"', $html);
        $this->assertStringContainsString('Tuesday Group', $html);
    }

    /**
     * Hiding cancellations is a display filter, not a data filter — the
     * counters still report them so the totals stay honest.
     *
     * @test
     */
    public function hiding_cancellations_still_counts_them_in_the_statistics(): void
    {
        $this->userMeta['1|confur_show_cancellations'] = 0;
        $this->seedMeeting(500, 'Monday Group');
        $this->seedRegistration(1, 500, Constants::STATUS_CANCELLED);

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertMatchesRegularExpression(
            '/<div class="number">1<\/div>\s*<div class="label">Cancelled<\/div>/',
            $html
        );
    }
}
