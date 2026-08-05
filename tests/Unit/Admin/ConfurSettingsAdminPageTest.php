<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Admin\ConfurSettingsAdminPage;
use ReflectionMethod;
use Tests\ConfurTestCase;

/**
 * Tests for the plugin settings screen.
 *
 * Like the email template page, this ends in wp_redirect() followed by a bare
 * exit, so the three submission branches are reached through
 * resolveSubmissionRedirect() rather than through handleFormSubmission(); the
 * capability and nonce guards in front of them are covered as guards, since
 * wp_die() throws.
 *
 * The settings themselves live in Confur\Config\ConfurSettings, which is
 * excluded from coverage — these tests drive it anyway, because what matters
 * on this screen is that the field names the form renders are the ones the
 * handler reads back, and that only holds if both sides run for real.
 *
 * @covers \Confur\Admin\ConfurSettingsAdminPage
 */
final class ConfurSettingsAdminPageTest extends ConfurTestCase
{
    private const SETTINGS_OPTION  = 'confur_email_settings';
    private const BLOCKLIST_OPTION = 'confur_email_blocklist';
    private const HOOK             = 'questions-for-conference_page_confur-settings';
    private const NONCE            = 'nonce-confur_settings_action';

    private ConfurSettingsAdminPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];
        $_GET  = [];

        $this->page = new ConfurSettingsAdminPage();

        Functions\when('get_admin_page_title')->justReturn('Confur Settings');
        Functions\when('submit_button')->alias(
            static function (string $text = 'Save', string $type = 'primary', string $name = 'submit'): void {
                echo '<button type="submit" name="' . $name . '">' . $text . '</button>';
            }
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];

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

    private function submissionRedirect(): string
    {
        $m = new ReflectionMethod(ConfurSettingsAdminPage::class, 'resolveSubmissionRedirect');

        return (string) $m->invoke($this->page);
    }

    /** A complete, valid settings form, as the screen renders it. */
    private function validForm(): array
    {
        return [
            'confur_settings_nonce'    => self::NONCE,
            'registration_reply_email' => 'conference@example.org',
            'support_email'            => 'support@example.org',
            'backup_email'             => 'backup@example.org',
        ];
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_registers_the_menu_the_form_handler_and_the_assets(): void
    {
        $this->page->init();

        $this->assertActionAdded('admin_menu', false, 'the menu should be registered');
        $this->assertActionAdded('admin_post_confur_update_settings', false, 'the form handler should be registered');
        $this->assertActionAdded('admin_enqueue_scripts', false, 'the assets should be registered');
    }

    /** @test */
    public function nothing_is_registered_on_a_front_end_request(): void
    {
        WpState::$isAdmin = false;

        $this->page->init();

        $this->assertActionNotAdded('admin_menu');
    }

    /** @test */
    public function the_page_is_added_under_the_confur_menu_for_administrators_only(): void
    {
        $this->page->addAdminMenu();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame('confur', WpState::$menus[0]['parent']);
        $this->assertSame('confur-settings', WpState::$menus[0]['slug']);
        $this->assertSame('manage_options', WpState::$menus[0]['cap']);
    }

    /** @test */
    public function the_page_styles_are_only_loaded_on_this_screen(): void
    {
        $this->page->enqueueAdminAssets('edit.php');

        $this->assertSame([], WpState::$enqueued);
    }

    /** @test */
    public function the_page_styles_are_loaded_on_this_screen(): void
    {
        $this->page->enqueueAdminAssets(self::HOOK);

        $this->assertSame(
            [['fn' => 'wp_add_inline_style', 'handle' => 'wp-admin']],
            WpState::$enqueued
        );
    }

    // ── submission guards ─────────────────────────────────────────────

    /** @test */
    public function the_form_handler_refuses_a_user_without_the_capability(): void
    {
        WpState::$userCan = false;

        $this->expectException(WpDieException::class);
        $this->page->handleFormSubmission();
    }

    /** @test */
    public function the_form_handler_refuses_a_submission_with_no_nonce(): void
    {
        $this->expectException(WpDieException::class);
        $this->page->handleFormSubmission();
    }

    /** @test */
    public function the_form_handler_refuses_a_submission_with_a_stale_nonce(): void
    {
        $_POST['confur_settings_nonce'] = 'nonce-something-else';

        $this->expectException(WpDieException::class);
        $this->page->handleFormSubmission();
    }

    // ── saving settings (the caller redirects and exits) ──────────────

    /** @test */
    public function a_valid_save_stores_every_field_and_reports_success(): void
    {
        $_POST = $this->validForm() + [
            'delete_blocked_posts'       => '1',
            'enable_duplicate_detection' => '1',
        ];

        $redirect = $this->submissionRedirect();

        $this->assertStringContainsString('page=confur-settings', $redirect);
        $this->assertStringContainsString('updated=1', $redirect);

        $saved = WpState::$options[self::SETTINGS_OPTION];
        $this->assertSame('conference@example.org', $saved['registration_reply']);
        $this->assertSame('support@example.org', $saved['support']);
        $this->assertSame('backup@example.org', $saved['backup']);
        $this->assertTrue($saved['delete_blocked_posts']);
        $this->assertTrue($saved['enable_duplicate_detection']);
    }

    /**
     * Unticked checkboxes are absent from $_POST rather than sent as "0", so
     * "not present" has to mean false and not "leave as it was".
     *
     * @test
     */
    public function unticked_checkboxes_are_saved_as_false(): void
    {
        WpState::$options[self::SETTINGS_OPTION] = [
            'delete_blocked_posts'       => true,
            'enable_duplicate_detection' => true,
        ];
        $_POST = $this->validForm();

        $this->submissionRedirect();

        $saved = WpState::$options[self::SETTINGS_OPTION];
        $this->assertFalse($saved['delete_blocked_posts']);
        $this->assertFalse($saved['enable_duplicate_detection']);
    }

    /**
     * An invalid email address is rejected by ConfurSettings::updateAll(), so
     * nothing is written.
     *
     * The redirect still says updated=1, which is not what you would expect.
     * The branch is `if ($settingsUpdated || $blocklistUpdated)`, and the
     * blocklist is written by the same submission — an empty textarea still
     * counts as a successful write, so the OR is satisfied and the screen
     * reports success over a settings save that did not happen. Asserted as-is
     * rather than corrected: this change is about covering the layer, not
     * altering it.
     *
     * @test
     */
    public function an_invalid_email_address_is_not_saved(): void
    {
        $_POST = $this->validForm();
        $_POST['support_email'] = 'not-an-email';

        $redirect = $this->submissionRedirect();

        $this->assertArrayNotHasKey(self::SETTINGS_OPTION, WpState::$options, 'nothing should have been saved');
        $this->assertStringContainsString(
            'updated=1',
            $redirect,
            'the successful blocklist write currently masks the rejected settings save'
        );
    }

    /** @test */
    public function a_save_where_nothing_could_be_written_reports_an_error(): void
    {
        Functions\when('update_option')->justReturn(false);
        $_POST = $this->validForm();
        $_POST['support_email'] = 'not-an-email';

        $this->assertStringContainsString('error=1', $this->submissionRedirect());
    }

    /**
     * The blocked list is saved by the same submission as the settings, from
     * a textarea holding one address per line.
     *
     * @test
     */
    public function the_blocked_list_textarea_is_split_sorted_and_saved(): void
    {
        $_POST = $this->validForm();
        $_POST['email_blocklist'] = "  zed@example.org  \n\nalice@example.org\n";

        $this->submissionRedirect();

        $this->assertSame(
            ['alice@example.org', 'zed@example.org'],
            WpState::$options[self::BLOCKLIST_OPTION]
        );
    }

    /** @test */
    public function the_reset_button_restores_the_shipped_defaults(): void
    {
        WpState::$options[self::SETTINGS_OPTION] = ['support' => 'custom@example.org'];
        $_POST = [
            'confur_settings_nonce' => self::NONCE,
            'reset_to_defaults'     => 'Reset to Defaults',
        ];

        $redirect = $this->submissionRedirect();

        $this->assertStringContainsString('updated=1', $redirect);
        $this->assertSame(
            'support@aa-bristol.org',
            WpState::$options[self::SETTINGS_OPTION]['support']
        );
    }

    /** @test */
    public function a_reset_that_fails_to_write_reports_an_error(): void
    {
        Functions\when('update_option')->justReturn(false);
        $_POST = [
            'confur_settings_nonce' => self::NONCE,
            'reset_to_defaults'     => 'Reset to Defaults',
        ];

        $this->assertStringContainsString('error=1', $this->submissionRedirect());
    }

    /** @test */
    public function the_clear_button_empties_the_blocked_list(): void
    {
        WpState::$options[self::BLOCKLIST_OPTION] = ['blocked@example.org'];
        $_POST = [
            'confur_settings_nonce' => self::NONCE,
            'clear_blocklist'       => 'Clear Blocked List',
        ];

        $redirect = $this->submissionRedirect();

        $this->assertStringContainsString('updated=blocklist_cleared', $redirect);
        $this->assertSame([], WpState::$options[self::BLOCKLIST_OPTION]);
    }

    /** @test */
    public function a_clear_that_fails_to_write_reports_an_error(): void
    {
        Functions\when('update_option')->justReturn(false);
        $_POST = [
            'confur_settings_nonce' => self::NONCE,
            'clear_blocklist'       => 'Clear Blocked List',
        ];

        $this->assertStringContainsString('error=blocklist', $this->submissionRedirect());
    }

    // ── the screen ────────────────────────────────────────────────────

    /** @test */
    public function the_screen_refuses_a_user_without_the_capability(): void
    {
        WpState::$userCan = false;

        $this->expectException(WpDieException::class);
        $this->page->renderAdminPage();
    }

    /**
     * The names rendered here are the ones resolveSubmissionRedirect() reads
     * back out of $_POST, so rendering for real is what keeps the two halves
     * of the form honest.
     *
     * @test
     */
    public function the_screen_renders_the_fields_the_handler_reads_back(): void
    {
        $html = $this->capture(fn () => $this->page->renderAdminPage());

        foreach ([
            'registration_reply_email',
            'support_email',
            'backup_email',
            'email_blocklist',
            'delete_blocked_posts',
            'enable_duplicate_detection',
        ] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html, $field . ' should be on the form');
        }

        $this->assertStringContainsString('name="action" value="confur_update_settings"', $html);
        $this->assertStringContainsString('name="reset_to_defaults"', $html);
    }

    /** @test */
    public function the_screen_shows_the_current_values_and_the_defaults(): void
    {
        WpState::$options[self::SETTINGS_OPTION] = [
            'registration_reply' => 'current@example.org',
            'support'            => 'support@example.org',
            'backup'             => 'backup@example.org',
        ];

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString('value="current@example.org"', $html);
        $this->assertStringContainsString('conference@aa-bristol.org', $html, 'the defaults box should list the shipped value');
    }

    /**
     * Both checkboxes render from the saved settings, so a ticked box has to
     * survive a page reload.
     *
     * @test
     */
    public function the_checkboxes_reflect_what_is_saved(): void
    {
        WpState::$options[self::SETTINGS_OPTION] = [
            'delete_blocked_posts'       => true,
            'enable_duplicate_detection' => true,
        ];

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertSame(2, substr_count($html, 'checked="checked"'), 'both checkboxes should be ticked');
    }

    /**
     * The clear button is only worth offering when there is something to
     * clear, and the count is shown twice — once as a warning, once as a
     * caption.
     *
     * @test
     */
    public function the_clear_button_and_the_count_appear_only_with_a_populated_blocked_list(): void
    {
        WpState::$options[self::BLOCKLIST_OPTION] = ['one@example.org', 'two@example.org'];

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString('name="clear_blocklist"', $html);
        $this->assertStringContainsString('Currently 2 email(s)', $html);
        $this->assertStringContainsString('one@example.org', $html, 'the textarea should hold the list');
    }

    /** @test */
    public function the_clear_button_is_hidden_when_the_blocked_list_is_empty(): void
    {
        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringNotContainsString('name="clear_blocklist"', $html);
        $this->assertStringContainsString('Currently 0 email(s)', $html);
    }

    /** @test */
    public function a_corrupt_blocked_list_option_renders_as_empty_rather_than_fatalling(): void
    {
        WpState::$options[self::BLOCKLIST_OPTION] = 'not-an-array';

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString('Currently 0 email(s)', $html);
    }

    /**
     * @test
     * @dataProvider noticeParameters
     * @param array<string, string> $query
     */
    public function the_screen_reports_back_on_the_last_submission(array $query, string $expected): void
    {
        $_GET = $query;

        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString($expected, $html);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function noticeParameters(): array
    {
        return [
            'saved'            => [['updated' => '1'], 'Settings updated successfully.'],
            'reset'            => [['updated' => 'reset'], 'Settings reset to defaults successfully.'],
            'blocked list'     => [['updated' => 'blocklist_cleared'], 'Email blocked list cleared successfully.'],
            'failed'           => [['error' => '1'], 'Failed to update settings.'],
        ];
    }

    /** @test */
    public function no_notice_is_shown_on_a_plain_page_load(): void
    {
        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringNotContainsString('confur-notice error', $html);
        $this->assertStringNotContainsString('updated successfully', $html);
    }
}
