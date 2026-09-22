<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Admin\EmailTemplateAdminPage;
use ReflectionMethod;

/*
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
 */

covers(EmailTemplateAdminPage::class);

const TEMPLATES_OPTION = 'confur_email_templates';
const TEMPLATES_HOOK   = 'questions-for-conference_page_confur-email-templates';
const TEMPLATES_NONCE  = 'nonce-confur_email_templates_action';

function templatesSubmissionRedirect(EmailTemplateAdminPage $page): string
{
    $m = new ReflectionMethod(EmailTemplateAdminPage::class, 'resolveSubmissionRedirect');

    return (string) $m->invoke($page);
}

beforeEach(function () {
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
});

afterEach(function () {
    $_POST = [];
    $_GET  = [];
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('registers the menu, the form handler and the assets from init', function () {
        $this->page->init();

        $this->assertActionAdded('admin_menu', false, 'the menu should be registered');
        $this->assertActionAdded('admin_post_confur_update_email_templates', false, 'the form handler should be registered');
        $this->assertActionAdded('admin_enqueue_scripts', false, 'the assets should be registered');
    });

    it('registers nothing on a front-end request', function () {
        WpState::$isAdmin = false;

        $this->page->init();

        $this->assertActionNotAdded('admin_menu');
    });

    it('adds the page under the Confur menu for administrators only', function () {
        $this->page->addAdminMenu();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0]['parent'])->toBe('confur')
            ->and(WpState::$menus[0]['slug'])->toBe('confur-email-templates')
            ->and(WpState::$menus[0]['cap'])->toBe('manage_options');
    });

    it('only loads the editor on this screen', function () {
        $this->page->enqueueAdminAssets('edit.php');

        expect(WpState::$enqueued)->toBe([]);
    });

    it('loads the editor and the page styles on this screen', function () {
        $this->page->enqueueAdminAssets(TEMPLATES_HOOK);

        expect(WpState::$enqueued)->toBe([['fn' => 'wp_add_inline_style', 'handle' => 'wp-admin']]);
    });
});

// ── reading templates ─────────────────────────────────────────────
describe('reading templates', function () {
    // With nothing saved, every template falls back to the HTML file shipped
    // in /emails — and to the body of that file, not the whole document, since
    // the result is embedded in an email the plugin composes.
    it('falls back to the shipped html file for an unsaved template', function () {
        $body = EmailTemplateAdminPage::getBody('RegistrationConfirmation');

        expect($body)->toContain('{{MeetingName}}')
            // only the body content should be returned
            ->not->toContain('<!DOCTYPE html>')
            ->not->toContain('</html>');
    });

    it('returns every known template with its metadata', function () {
        $templates = EmailTemplateAdminPage::getAll();

        expect(array_keys($templates))->toBe(['RegistrationConfirmation', 'AnswersComplete', 'RegistrationBlocked']);

        foreach ($templates as $key => $template) {
            expect($template['name'])->not->toBe('', $key . ' should have a display name')
                ->and($template['subject'])->not->toBe('', $key . ' should have a subject')
                ->and($template['body'])->not->toBe('', $key . ' should have a body')
                ->and($template)->toHaveKey('placeholders', message: $key . ' should list its placeholders');
        }
    });

    it('lets a saved subject and body override the defaults', function () {
        WpState::$options[TEMPLATES_OPTION] = [
            'AnswersComplete' => ['subject' => 'Nicely done', 'body' => '<p>Thanks</p>'],
        ];

        expect(EmailTemplateAdminPage::getSubject('AnswersComplete'))->toBe('Nicely done')
            ->and(EmailTemplateAdminPage::getBody('AnswersComplete'))->toBe('<p>Thanks</p>');
    });

    // The two halves are saved independently, so a template with only a custom
    // subject must still fall back to the shipped body rather than to nothing.
    it('leaves the body at its default when only the subject is saved', function () {
        WpState::$options[TEMPLATES_OPTION] = ['AnswersComplete' => ['subject' => 'Nicely done']];

        expect(EmailTemplateAdminPage::getSubject('AnswersComplete'))->toBe('Nicely done')
            ->and(EmailTemplateAdminPage::getBody('AnswersComplete'))->not->toBe('');
    });

    it('yields null and empty strings for an unknown template key', function () {
        expect(EmailTemplateAdminPage::get('NoSuchTemplate'))->toBeNull()
            ->and(EmailTemplateAdminPage::getSubject('NoSuchTemplate'))->toBe('')
            ->and(EmailTemplateAdminPage::getBody('NoSuchTemplate'))->toBe('');
    });

    // The key becomes a filename, so it is sanitised and then re-checked
    // against a strict pattern before it is used to build a path.
    it('cannot use a template key to read another file', function (string $key) {
        $m = new ReflectionMethod(EmailTemplateAdminPage::class, 'getDefaultBody');

        expect($m->invoke(null, $key))->toBe('');
    })->with([
        'traversal'         => ['../../wp-config'],
        'absolute path'     => ['/etc/passwd'],
        'a name with a dot' => ['Registration.Confirmation'],
        'empty'             => [''],
        'unknown but valid' => ['NoSuchTemplate'],
    ]);
});

// ── writing templates ─────────────────────────────────────────────
describe('writing templates', function () {
    it('stores only the known templates when saving', function () {
        expect(EmailTemplateAdminPage::update([
            'AnswersComplete' => ['subject' => 'Done', 'body' => '<p>Body</p>'],
            'NotATemplate'    => ['subject' => 'Ignore me', 'body' => 'Ignore me'],
        ]))->toBeTrue()
            ->and(array_keys(WpState::$options[TEMPLATES_OPTION]))->toBe(['AnswersComplete'])
            ->and(WpState::$options[TEMPLATES_OPTION]['AnswersComplete']['subject'])->toBe('Done');
    });

    it('stores empty strings for a template saved with neither field', function () {
        EmailTemplateAdminPage::update(['AnswersComplete' => []]);

        expect(WpState::$options[TEMPLATES_OPTION]['AnswersComplete'])->toBe(['subject' => '', 'body' => '']);
    });

    it('clears the saved option when resetting everything', function () {
        WpState::$options[TEMPLATES_OPTION] = ['AnswersComplete' => ['subject' => 'Custom']];

        expect(EmailTemplateAdminPage::resetToDefaults())->toBeTrue()
            ->and(WpState::$options)->not->toHaveKey(TEMPLATES_OPTION);
    });

    it('leaves the others saved when resetting one template', function () {
        WpState::$options[TEMPLATES_OPTION] = [
            'AnswersComplete'          => ['subject' => 'Custom'],
            'RegistrationConfirmation' => ['subject' => 'Also custom'],
        ];

        expect(EmailTemplateAdminPage::resetTemplate('AnswersComplete'))->toBeTrue()
            ->and(array_keys(WpState::$options[TEMPLATES_OPTION]))->toBe(['RegistrationConfirmation']);
    });

    // Resetting the last customised template should leave no option row behind
    // rather than an empty array, so getAll() takes its "nothing saved" path.
    it('removes the option entirely when resetting the last customised template', function () {
        WpState::$options[TEMPLATES_OPTION] = ['AnswersComplete' => ['subject' => 'Custom']];

        expect(EmailTemplateAdminPage::resetTemplate('AnswersComplete'))->toBeTrue()
            ->and(WpState::$options)->not->toHaveKey(TEMPLATES_OPTION);
    });

    it('quietly succeeds in resetting a template that was never customised', function () {
        expect(EmailTemplateAdminPage::resetTemplate('AnswersComplete'))->toBeTrue()
            ->and(WpState::$options)->not->toHaveKey(TEMPLATES_OPTION);
    });

    it('fails to reset an unknown template', function () {
        expect(EmailTemplateAdminPage::resetTemplate('NoSuchTemplate'))->toBeFalse();
    });
});

// ── submission guards ─────────────────────────────────────────────
describe('submission guards', function () {
    it('refuses a user without the capability', function () {
        WpState::$userCan = false;

        $this->page->handleFormSubmission();
    })->throws(WpDieException::class);

    it('refuses a submission with no nonce', function () {
        $this->page->handleFormSubmission();
    })->throws(WpDieException::class);

    it('refuses a submission with a stale nonce', function () {
        $_POST['confur_email_templates_nonce'] = 'nonce-something-else';

        $this->page->handleFormSubmission();
    })->throws(WpDieException::class);
});

// ── submission outcomes (the caller redirects and exits) ──────────
describe('submission outcomes', function () {
    it('writes every template and reports success on a normal save', function () {
        $_POST = [
            'confur_email_templates_nonce'      => TEMPLATES_NONCE,
            'template_AnswersComplete_subject'  => 'Nicely done',
            'template_AnswersComplete_body'     => '<p>Thanks</p>',
        ];

        $redirect = templatesSubmissionRedirect($this->page);

        expect($redirect)->toContain('page=confur-email-templates', 'updated=1')
            ->and(WpState::$options[TEMPLATES_OPTION]['AnswersComplete']['subject'])->toBe('Nicely done')
            ->and(array_keys(WpState::$options[TEMPLATES_OPTION]))->toBe(
                ['RegistrationConfirmation', 'AnswersComplete', 'RegistrationBlocked'],
                'the untouched templates should be written too, as empty overrides'
            );
    });

    it('reports an error when a save fails to write', function () {
        Functions\when('update_option')->justReturn(false);
        $_POST['confur_email_templates_nonce'] = TEMPLATES_NONCE;

        expect(templatesSubmissionRedirect($this->page))->toContain('error=1');
    });

    it('clears everything from the reset all button', function () {
        WpState::$options[TEMPLATES_OPTION] = ['AnswersComplete' => ['subject' => 'Custom']];
        $_POST = [
            'confur_email_templates_nonce' => TEMPLATES_NONCE,
            'reset_all_defaults'           => 'Reset All to Defaults',
        ];

        $redirect = templatesSubmissionRedirect($this->page);

        expect($redirect)->toContain('updated=reset_all')
            ->and(WpState::$options)->not->toHaveKey(TEMPLATES_OPTION);
    });

    it('clears only that template from the per-template reset button', function () {
        WpState::$options[TEMPLATES_OPTION] = [
            'AnswersComplete'          => ['subject' => 'Custom'],
            'RegistrationConfirmation' => ['subject' => 'Also custom'],
        ];
        $_POST = [
            'confur_email_templates_nonce' => TEMPLATES_NONCE,
            'reset_template'               => 'AnswersComplete',
        ];

        $redirect = templatesSubmissionRedirect($this->page);

        expect($redirect)->toContain('updated=reset_single')
            ->and(array_keys(WpState::$options[TEMPLATES_OPTION]))->toBe(['RegistrationConfirmation']);
    });

    it('reports an error when resetting an unknown template from the form', function () {
        $_POST = [
            'confur_email_templates_nonce' => TEMPLATES_NONCE,
            'reset_template'               => 'NoSuchTemplate',
        ];

        expect(templatesSubmissionRedirect($this->page))->toContain('error=1');
    });
});

// ── the screen ────────────────────────────────────────────────────
describe('the screen', function () {
    it('refuses a user without the capability', function () {
        WpState::$userCan = false;

        $this->page->renderAdminPage();
    })->throws(WpDieException::class);

    // Driving the render for real is what proves the form field names match
    // the ones handleFormSubmission() reads back out of $_POST — a rename on
    // one side alone would silently stop saving.
    it('renders a card per template with matching field names', function () {
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        foreach (['RegistrationConfirmation', 'AnswersComplete', 'RegistrationBlocked'] as $key) {
            expect($html)->toContain('name="template_' . $key . '_subject"', 'id="template_' . $key . '_body"')
                // each template should have a reset button
                ->toContain('value="' . $key . '"');
        }

        expect($html)->toContain('name="action" value="confur_update_email_templates"', 'name="reset_all_defaults"');
    });

    it('lists the placeholders a template accepts', function () {
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('<code>{{MeetingName}}</code>', '<code>{{AllocationNotice}}</code>');
    });

    it('reports back on the last submission', function (array $query, string $expected) {
        $_GET = $query;

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain($expected);
    })->with([
        'saved'            => [['updated' => '1'], 'Email templates updated successfully.'],
        'all reset'        => [['updated' => 'reset_all'], 'All email templates reset to defaults.'],
        'one reset'        => [['updated' => 'reset_single'], 'Email template reset to default.'],
        'failed'           => [['error' => '1'], 'Failed to update email templates.'],
    ]);

    it('shows no notice on a plain page load', function () {
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->not->toContain('confur-notice error')
            ->not->toContain('updated successfully');
    });
});
