<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Admin\ConfurSettingsAdminPage;
use ReflectionMethod;

/*
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
 */

covers(ConfurSettingsAdminPage::class);

const SETTINGS_OPTION           = 'confur_email_settings';
const SETTINGS_BLOCKLIST_OPTION = 'confur_email_blocklist';
const SETTINGS_HOOK             = 'questions-for-conference_page_confur-settings';
const SETTINGS_NONCE            = 'nonce-confur_settings_action';

function settingsSubmissionRedirect(ConfurSettingsAdminPage $page): string
{
    $m = new ReflectionMethod(ConfurSettingsAdminPage::class, 'resolveSubmissionRedirect');

    return (string) $m->invoke($page);
}

/** A complete, valid settings form, as the screen renders it. */
function validSettingsForm(): array
{
    return [
        'confur_settings_nonce'    => SETTINGS_NONCE,
        'registration_reply_email' => 'conference@example.org',
        'support_email'            => 'support@example.org',
        'backup_email'             => 'backup@example.org',
    ];
}

beforeEach(function () {
    $_POST = [];
    $_GET  = [];

    $this->page = new ConfurSettingsAdminPage();

    Functions\when('get_admin_page_title')->justReturn('Confur Settings');
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
        $this->assertActionAdded('admin_post_confur_update_settings', false, 'the form handler should be registered');
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
            ->and(WpState::$menus[0]['slug'])->toBe('confur-settings')
            ->and(WpState::$menus[0]['cap'])->toBe('manage_options');
    });

    it('only loads the page styles on this screen', function () {
        $this->page->enqueueAdminAssets('edit.php');

        expect(WpState::$enqueued)->toBe([]);
    });

    it('loads the page styles on this screen', function () {
        $this->page->enqueueAdminAssets(SETTINGS_HOOK);

        expect(WpState::$enqueued)->toBe([['fn' => 'wp_add_inline_style', 'handle' => 'wp-admin']]);
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
        $_POST['confur_settings_nonce'] = 'nonce-something-else';

        $this->page->handleFormSubmission();
    })->throws(WpDieException::class);
});

// ── saving settings (the caller redirects and exits) ──────────────
describe('saving settings', function () {
    it('stores every field and reports success on a valid save', function () {
        $_POST = validSettingsForm() + [
            'delete_blocked_posts'       => '1',
            'enable_duplicate_detection' => '1',
        ];

        $redirect = settingsSubmissionRedirect($this->page);

        expect($redirect)->toContain('page=confur-settings', 'updated=1');

        $saved = WpState::$options[SETTINGS_OPTION];
        expect($saved['registration_reply'])->toBe('conference@example.org')
            ->and($saved['support'])->toBe('support@example.org')
            ->and($saved['backup'])->toBe('backup@example.org')
            ->and($saved['delete_blocked_posts'])->toBeTrue()
            ->and($saved['enable_duplicate_detection'])->toBeTrue();
    });

    // Unticked checkboxes are absent from $_POST rather than sent as "0", so
    // "not present" has to mean false and not "leave as it was".
    it('saves unticked checkboxes as false', function () {
        WpState::$options[SETTINGS_OPTION] = [
            'delete_blocked_posts'       => true,
            'enable_duplicate_detection' => true,
        ];
        $_POST = validSettingsForm();

        settingsSubmissionRedirect($this->page);

        $saved = WpState::$options[SETTINGS_OPTION];
        expect($saved['delete_blocked_posts'])->toBeFalse()
            ->and($saved['enable_duplicate_detection'])->toBeFalse();
    });

    // An invalid email address is rejected by ConfurSettings::updateAll(), so
    // nothing is written.
    //
    // The redirect still says updated=1, which is not what you would expect.
    // The branch is `if ($settingsUpdated || $blocklistUpdated)`, and the
    // blocklist is written by the same submission — an empty textarea still
    // counts as a successful write, so the OR is satisfied and the screen
    // reports success over a settings save that did not happen. Asserted as-is
    // rather than corrected: this change is about covering the layer, not
    // altering it.
    it('does not save an invalid email address', function () {
        $_POST = validSettingsForm();
        $_POST['support_email'] = 'not-an-email';

        $redirect = settingsSubmissionRedirect($this->page);

        expect(WpState::$options)->not->toHaveKey(SETTINGS_OPTION, message: 'nothing should have been saved')
            // the successful blocklist write currently masks the rejected settings save
            ->and($redirect)->toContain('updated=1');
    });

    it('reports an error when nothing could be written', function () {
        Functions\when('update_option')->justReturn(false);
        $_POST = validSettingsForm();
        $_POST['support_email'] = 'not-an-email';

        expect(settingsSubmissionRedirect($this->page))->toContain('error=1');
    });

    // The blocked list is saved by the same submission as the settings, from
    // a textarea holding one address per line.
    it('splits, sorts and saves the blocked list textarea', function () {
        $_POST = validSettingsForm();
        $_POST['email_blocklist'] = "  zed@example.org  \n\nalice@example.org\n";

        settingsSubmissionRedirect($this->page);

        expect(WpState::$options[SETTINGS_BLOCKLIST_OPTION])->toBe(['alice@example.org', 'zed@example.org']);
    });

    it('restores the shipped defaults from the reset button', function () {
        WpState::$options[SETTINGS_OPTION] = ['support' => 'custom@example.org'];
        $_POST = [
            'confur_settings_nonce' => SETTINGS_NONCE,
            'reset_to_defaults'     => 'Reset to Defaults',
        ];

        $redirect = settingsSubmissionRedirect($this->page);

        expect($redirect)->toContain('updated=1')
            ->and(WpState::$options[SETTINGS_OPTION]['support'])->toBe('support@aa-bristol.org');
    });

    it('reports an error when a reset fails to write', function () {
        Functions\when('update_option')->justReturn(false);
        $_POST = [
            'confur_settings_nonce' => SETTINGS_NONCE,
            'reset_to_defaults'     => 'Reset to Defaults',
        ];

        expect(settingsSubmissionRedirect($this->page))->toContain('error=1');
    });

    it('empties the blocked list from the clear button', function () {
        WpState::$options[SETTINGS_BLOCKLIST_OPTION] = ['blocked@example.org'];
        $_POST = [
            'confur_settings_nonce' => SETTINGS_NONCE,
            'clear_blocklist'       => 'Clear Blocked List',
        ];

        $redirect = settingsSubmissionRedirect($this->page);

        expect($redirect)->toContain('updated=blocklist_cleared')
            ->and(WpState::$options[SETTINGS_BLOCKLIST_OPTION])->toBe([]);
    });

    it('reports an error when a clear fails to write', function () {
        Functions\when('update_option')->justReturn(false);
        $_POST = [
            'confur_settings_nonce' => SETTINGS_NONCE,
            'clear_blocklist'       => 'Clear Blocked List',
        ];

        expect(settingsSubmissionRedirect($this->page))->toContain('error=blocklist');
    });
});

// ── the screen ────────────────────────────────────────────────────
describe('the screen', function () {
    it('refuses a user without the capability', function () {
        WpState::$userCan = false;

        $this->page->renderAdminPage();
    })->throws(WpDieException::class);

    // The names rendered here are the ones resolveSubmissionRedirect() reads
    // back out of $_POST, so rendering for real is what keeps the two halves
    // of the form honest.
    it('renders the fields the handler reads back', function () {
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        foreach (
            [
            'registration_reply_email',
            'support_email',
            'backup_email',
            'email_blocklist',
            'delete_blocked_posts',
            'enable_duplicate_detection',
            ] as $field
        ) {
            // each field should be on the form
            expect($html)->toContain('name="' . $field . '"');
        }

        expect($html)->toContain('name="action" value="confur_update_settings"', 'name="reset_to_defaults"');
    });

    it('shows the current values and the defaults', function () {
        WpState::$options[SETTINGS_OPTION] = [
            'registration_reply' => 'current@example.org',
            'support'            => 'support@example.org',
            'backup'             => 'backup@example.org',
        ];

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('value="current@example.org"')
            // the defaults box should list the shipped value
            ->toContain('conference@aa-bristol.org');
    });

    // Both checkboxes render from the saved settings, so a ticked box has to
    // survive a page reload.
    it('reflects what is saved in the checkboxes', function () {
        WpState::$options[SETTINGS_OPTION] = [
            'delete_blocked_posts'       => true,
            'enable_duplicate_detection' => true,
        ];

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect(substr_count($html, 'checked="checked"'))->toBe(2, 'both checkboxes should be ticked');
    });

    // The clear button is only worth offering when there is something to
    // clear, and the count is shown twice — once as a warning, once as a
    // caption.
    it('shows the clear button and the count only with a populated blocked list', function () {
        WpState::$options[SETTINGS_BLOCKLIST_OPTION] = ['one@example.org', 'two@example.org'];

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('name="clear_blocklist"', 'Currently 2 email(s)')
            // the textarea should hold the list
            ->toContain('one@example.org');
    });

    it('hides the clear button when the blocked list is empty', function () {
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->not->toContain('name="clear_blocklist"')
            ->toContain('Currently 0 email(s)');
    });

    it('renders a corrupt blocked list option as empty rather than fatalling', function () {
        WpState::$options[SETTINGS_BLOCKLIST_OPTION] = 'not-an-array';

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain('Currently 0 email(s)');
    });

    it('reports back on the last submission', function (array $query, string $expected) {
        $_GET = $query;

        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->toContain($expected);
    })->with([
        'saved'            => [['updated' => '1'], 'Settings updated successfully.'],
        'reset'            => [['updated' => 'reset'], 'Settings reset to defaults successfully.'],
        'blocked list'     => [['updated' => 'blocklist_cleared'], 'Email blocked list cleared successfully.'],
        'failed'           => [['error' => '1'], 'Failed to update settings.'],
    ]);

    it('shows no notice on a plain page load', function () {
        $html = captureOutput(fn () => $this->page->renderAdminPage());

        expect($html)->not->toContain('confur-notice error')
            ->not->toContain('updated successfully');
    });
});
