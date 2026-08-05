<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Admin\EmailTemplateAdminPage;
use ReflectionMethod;
use Tests\ConfurTestCase;

/**
 * Tests for the email template editor.
 *
 * Most of this class is not admin glue at all: the static accessors are the
 * store the whole email layer reads through, so EmailService::sendConfirmation()
 * and friends get their subject and body from here. Those are driven directly
 * against WpState's option store.
 *
 * handleFormSubmission() ends in wp_redirect() followed by a bare exit. The
 * stubs record a redirect rather than throwing, so exit would run and take the
 * test runner with it. Its guards are covered as guards; the six outcomes
 * behind them are reached through resolveSubmissionRedirect(), which was split
 * out of it for exactly that reason.
 *
 * @covers \Confur\Admin\EmailTemplateAdminPage
 */
final class EmailTemplateAdminPageTest extends ConfurTestCase
{
    private const OPTION = 'confur_email_templates';
    private const HOOK   = 'questions-for-conference_page_confur-email-templates';
    private const NONCE  = 'nonce-confur_email_templates_action';

    private EmailTemplateAdminPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];
        $_GET  = [];

        $this->page = new EmailTemplateAdminPage();

        // Not shipped by wp-mocks: the editor and the form helpers.
        Functions\when('wp_enqueue_editor')->justReturn(null);
        Functions\when('get_admin_page_title')->justReturn('Email Templates');
        Functions\when('wp_editor')->alias(
            static function (string $content, string $id): void {
                echo '<textarea id="' . $id . '">' . $content . '</textarea>';
            }
        );
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
        $m = new ReflectionMethod(EmailTemplateAdminPage::class, 'resolveSubmissionRedirect');

        return (string) $m->invoke($this->page);
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_registers_the_menu_the_form_handler_and_the_assets(): void
    {
        $this->page->init();

        $this->assertActionAdded('admin_menu', false, 'the menu should be registered');
        $this->assertActionAdded('admin_post_confur_update_email_templates', false, 'the form handler should be registered');
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
        $this->assertSame('confur-email-templates', WpState::$menus[0]['slug']);
        $this->assertSame('manage_options', WpState::$menus[0]['cap']);
    }

    /** @test */
    public function the_editor_is_only_loaded_on_this_screen(): void
    {
        $this->page->enqueueAdminAssets('edit.php');

        $this->assertSame([], WpState::$enqueued);
    }

    /** @test */
    public function the_editor_and_the_page_styles_are_loaded_on_this_screen(): void
    {
        $this->page->enqueueAdminAssets(self::HOOK);

        $this->assertSame(
            [['fn' => 'wp_add_inline_style', 'handle' => 'wp-admin']],
            WpState::$enqueued
        );
    }

    // ── reading templates ─────────────────────────────────────────────

    /**
     * With nothing saved, every template falls back to the HTML file shipped
     * in /emails — and to the body of that file, not the whole document, since
     * the result is embedded in an email the plugin composes.
     *
     * @test
     */
    public function an_unsaved_template_falls_back_to_the_shipped_html_file(): void
    {
        $body = EmailTemplateAdminPage::getBody('RegistrationConfirmation');

        $this->assertStringContainsString('{{MeetingName}}', $body);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $body, 'only the body content should be returned');
        $this->assertStringNotContainsString('</html>', $body);
    }

    /** @test */
    public function every_known_template_is_returned_with_its_metadata(): void
    {
        $templates = EmailTemplateAdminPage::getAll();

        $this->assertSame(
            ['RegistrationConfirmation', 'AnswersComplete', 'RegistrationBlocked'],
            array_keys($templates)
        );

        foreach ($templates as $key => $template) {
            $this->assertNotSame('', $template['name'], $key . ' should have a display name');
            $this->assertNotSame('', $template['subject'], $key . ' should have a subject');
            $this->assertNotSame('', $template['body'], $key . ' should have a body');
            $this->assertArrayHasKey('placeholders', $template, $key . ' should list its placeholders');
        }
    }

    /** @test */
    public function a_saved_subject_and_body_override_the_defaults(): void
    {
        WpState::$options[self::OPTION] = [
            'AnswersComplete' => ['subject' => 'Nicely done', 'body' => '<p>Thanks</p>'],
        ];

        $this->assertSame('Nicely done', EmailTemplateAdminPage::getSubject('AnswersComplete'));
        $this->assertSame('<p>Thanks</p>', EmailTemplateAdminPage::getBody('AnswersComplete'));
    }

    /**
     * The two halves are saved independently, so a template with only a custom
     * subject must still fall back to the shipped body rather than to nothing.
     *
     * @test
     */
    public function a_saved_subject_alone_leaves_the_body_at_its_default(): void
    {
        WpState::$options[self::OPTION] = ['AnswersComplete' => ['subject' => 'Nicely done']];

        $this->assertSame('Nicely done', EmailTemplateAdminPage::getSubject('AnswersComplete'));
        $this->assertNotSame('', EmailTemplateAdminPage::getBody('AnswersComplete'));
    }

    /** @test */
    public function an_unknown_template_key_yields_null_and_empty_strings(): void
    {
        $this->assertNull(EmailTemplateAdminPage::get('NoSuchTemplate'));
        $this->assertSame('', EmailTemplateAdminPage::getSubject('NoSuchTemplate'));
        $this->assertSame('', EmailTemplateAdminPage::getBody('NoSuchTemplate'));
    }

    /**
     * The key becomes a filename, so it is sanitised and then re-checked
     * against a strict pattern before it is used to build a path.
     *
     * @test
     * @dataProvider hostileKeys
     */
    public function a_template_key_cannot_be_used_to_read_another_file(string $key): void
    {
        $m = new ReflectionMethod(EmailTemplateAdminPage::class, 'getDefaultBody');

        $this->assertSame('', $m->invoke(null, $key));
    }

    /** @return array<string, array{0: string}> */
    public static function hostileKeys(): array
    {
        return [
            'traversal'         => ['../../wp-config'],
            'absolute path'     => ['/etc/passwd'],
            'a name with a dot' => ['Registration.Confirmation'],
            'empty'             => [''],
            'unknown but valid' => ['NoSuchTemplate'],
        ];
    }

    // ── writing templates ─────────────────────────────────────────────

    /** @test */
    public function saving_stores_only_the_known_templates(): void
    {
        $this->assertTrue(EmailTemplateAdminPage::update([
            'AnswersComplete' => ['subject' => 'Done', 'body' => '<p>Body</p>'],
            'NotATemplate'    => ['subject' => 'Ignore me', 'body' => 'Ignore me'],
        ]));

        $this->assertSame(['AnswersComplete'], array_keys(WpState::$options[self::OPTION]));
        $this->assertSame('Done', WpState::$options[self::OPTION]['AnswersComplete']['subject']);
    }

    /** @test */
    public function a_template_saved_with_neither_field_stores_empty_strings(): void
    {
        EmailTemplateAdminPage::update(['AnswersComplete' => []]);

        $this->assertSame(
            ['subject' => '', 'body' => ''],
            WpState::$options[self::OPTION]['AnswersComplete']
        );
    }

    /** @test */
    public function resetting_everything_clears_the_saved_option(): void
    {
        WpState::$options[self::OPTION] = ['AnswersComplete' => ['subject' => 'Custom']];

        $this->assertTrue(EmailTemplateAdminPage::resetToDefaults());
        $this->assertArrayNotHasKey(self::OPTION, WpState::$options);
    }

    /** @test */
    public function resetting_one_template_leaves_the_others_saved(): void
    {
        WpState::$options[self::OPTION] = [
            'AnswersComplete'          => ['subject' => 'Custom'],
            'RegistrationConfirmation' => ['subject' => 'Also custom'],
        ];

        $this->assertTrue(EmailTemplateAdminPage::resetTemplate('AnswersComplete'));

        $this->assertSame(['RegistrationConfirmation'], array_keys(WpState::$options[self::OPTION]));
    }

    /**
     * Resetting the last customised template should leave no option row behind
     * rather than an empty array, so getAll() takes its "nothing saved" path.
     *
     * @test
     */
    public function resetting_the_last_customised_template_removes_the_option_entirely(): void
    {
        WpState::$options[self::OPTION] = ['AnswersComplete' => ['subject' => 'Custom']];

        $this->assertTrue(EmailTemplateAdminPage::resetTemplate('AnswersComplete'));
        $this->assertArrayNotHasKey(self::OPTION, WpState::$options);
    }

    /** @test */
    public function resetting_a_template_that_was_never_customised_succeeds_quietly(): void
    {
        $this->assertTrue(EmailTemplateAdminPage::resetTemplate('AnswersComplete'));
        $this->assertArrayNotHasKey(self::OPTION, WpState::$options);
    }

    /** @test */
    public function resetting_an_unknown_template_fails(): void
    {
        $this->assertFalse(EmailTemplateAdminPage::resetTemplate('NoSuchTemplate'));
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
        $_POST['confur_email_templates_nonce'] = 'nonce-something-else';

        $this->expectException(WpDieException::class);
        $this->page->handleFormSubmission();
    }

    // ── submission outcomes (the caller redirects and exits) ──────────

    /** @test */
    public function a_normal_save_writes_every_template_and_reports_success(): void
    {
        $_POST = [
            'confur_email_templates_nonce'      => self::NONCE,
            'template_AnswersComplete_subject'  => 'Nicely done',
            'template_AnswersComplete_body'     => '<p>Thanks</p>',
        ];

        $redirect = $this->submissionRedirect();

        $this->assertStringContainsString('page=confur-email-templates', $redirect);
        $this->assertStringContainsString('updated=1', $redirect);
        $this->assertSame('Nicely done', WpState::$options[self::OPTION]['AnswersComplete']['subject']);
        $this->assertSame(
            ['RegistrationConfirmation', 'AnswersComplete', 'RegistrationBlocked'],
            array_keys(WpState::$options[self::OPTION]),
            'the untouched templates should be written too, as empty overrides'
        );
    }

    /** @test */
    public function a_save_that_fails_to_write_reports_an_error(): void
    {
        Functions\when('update_option')->justReturn(false);
        $_POST['confur_email_templates_nonce'] = self::NONCE;

        $this->assertStringContainsString('error=1', $this->submissionRedirect());
    }

    /** @test */
    public function the_reset_all_button_clears_everything(): void
    {
        WpState::$options[self::OPTION] = ['AnswersComplete' => ['subject' => 'Custom']];
        $_POST = [
            'confur_email_templates_nonce' => self::NONCE,
            'reset_all_defaults'           => 'Reset All to Defaults',
        ];

        $redirect = $this->submissionRedirect();

        $this->assertStringContainsString('updated=reset_all', $redirect);
        $this->assertArrayNotHasKey(self::OPTION, WpState::$options);
    }

    /** @test */
    public function the_per_template_reset_button_clears_only_that_template(): void
    {
        WpState::$options[self::OPTION] = [
            'AnswersComplete'          => ['subject' => 'Custom'],
            'RegistrationConfirmation' => ['subject' => 'Also custom'],
        ];
        $_POST = [
            'confur_email_templates_nonce' => self::NONCE,
            'reset_template'               => 'AnswersComplete',
        ];

        $redirect = $this->submissionRedirect();

        $this->assertStringContainsString('updated=reset_single', $redirect);
        $this->assertSame(['RegistrationConfirmation'], array_keys(WpState::$options[self::OPTION]));
    }

    /** @test */
    public function resetting_an_unknown_template_from_the_form_reports_an_error(): void
    {
        $_POST = [
            'confur_email_templates_nonce' => self::NONCE,
            'reset_template'               => 'NoSuchTemplate',
        ];

        $this->assertStringContainsString('error=1', $this->submissionRedirect());
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
     * Driving the render for real is what proves the form field names match
     * the ones handleFormSubmission() reads back out of $_POST — a rename on
     * one side alone would silently stop saving.
     *
     * @test
     */
    public function the_screen_renders_a_card_per_template_with_matching_field_names(): void
    {
        $html = $this->capture(fn () => $this->page->renderAdminPage());

        foreach (['RegistrationConfirmation', 'AnswersComplete', 'RegistrationBlocked'] as $key) {
            $this->assertStringContainsString('name="template_' . $key . '_subject"', $html);
            $this->assertStringContainsString('id="template_' . $key . '_body"', $html);
            $this->assertStringContainsString('value="' . $key . '"', $html, $key . ' should have a reset button');
        }

        $this->assertStringContainsString('name="action" value="confur_update_email_templates"', $html);
        $this->assertStringContainsString('name="reset_all_defaults"', $html);
    }

    /** @test */
    public function the_screen_lists_the_placeholders_a_template_accepts(): void
    {
        $html = $this->capture(fn () => $this->page->renderAdminPage());

        $this->assertStringContainsString('<code>{{MeetingName}}</code>', $html);
        $this->assertStringContainsString('<code>{{AllocationNotice}}</code>', $html);
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
            'saved'            => [['updated' => '1'], 'Email templates updated successfully.'],
            'all reset'        => [['updated' => 'reset_all'], 'All email templates reset to defaults.'],
            'one reset'        => [['updated' => 'reset_single'], 'Email template reset to default.'],
            'failed'           => [['error' => '1'], 'Failed to update email templates.'],
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
