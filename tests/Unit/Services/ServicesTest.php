<?php

namespace Tests\Unit\Services;

use BleedingDeacons\WpMocks\WpState;
use Confur\Services\AdminAssets;
use Confur\Services\AssetService;
use Confur\Services\ShortcodeService;

covers(AssetService::class);
covers(AdminAssets::class);
covers(ShortcodeService::class);

beforeEach(function () {
    WpState::$isSingular = false;
    unset($_GET['page']);
});

it('gives the answer page script its slug and REST endpoints', function () {
    WpState::$isSingular = true;
    \Brain\Monkey\Functions\when('get_queried_object')->justReturn((object) ['post_name' => 'abc123_monday_group']);

    (new AssetService())->enqueueScripts();

    expect(array_column(WpState::$enqueued, 'handle'))->toContain(AssetService::CLIENT_HANDLE)
        ->and(WpState::$localized['confurAnswerPage'])->toBe([
            'slug' => 'abc123_monday_group',
            'statusUrl' => 'https://example.test/wp-json/answer/v1/status/abc123_monday_group',
            'saveUrl' => 'https://example.test/wp-json/confur/v1/answers/abc123_monday_group',
        ]);
});

it('skips the front-end assets when not on an answer page', function () {
    WpState::$isSingular = false;

    (new AssetService())->enqueueScripts();

    expect(WpState::$enqueued)->toBe([])
        ->and(WpState::$localized)->not->toHaveKey('confurAnswerPage');
});

describe('admin assets', function () {
    it('enqueues a stylesheet from assets/admin/', function () {
        AdminAssets::style('status');

        expect(WpState::$enqueued)->toBe([['fn' => 'wp_enqueue_style', 'handle' => 'confur-admin-status']]);
    });

    it('enqueues a script with its config, rather than baking values into it', function () {
        AdminAssets::script('dashboard', ['jquery'], 'confurDashboard', ['nonce' => 'abc']);

        expect(WpState::$enqueued)->toBe([['fn' => 'wp_enqueue_script', 'handle' => 'confur-admin-dashboard']])
            ->and(WpState::$localized['confurDashboard'])->toBe(['nonce' => 'abc']);
    });

    it('ships every file a screen asks for', function (string $file) {
        expect(dirname(__DIR__, 3) . '/assets/admin/' . $file)->toBeFile();
    })->with([
        'status.css', 'status.js', 'settings.css', 'email-templates.css', 'results.css', 'results.js',
        'dashboard.css', 'dashboard.js', 'answer-list.css',
    ]);

    it('leaves no PHP-generated values in the scripts', function (string $file) {
        // Nonces, URLs and user ids come from the localized config objects.
        expect(file_get_contents(dirname(__DIR__, 3) . '/assets/admin/' . $file))
            ->not->toMatch('/nonce-|example\.test|<\?php/');
    })->with(['status.js', 'dashboard.js', 'results.js']);
});

it('registers all the shortcodes', function () {
    (new ShortcodeService())->registerShortcodes();

    $registered = $this->registeredShortcodes();
    expect($registered)->toContain('step', 'tradition', 'answer', 'open_new_link', 'allocated_committee');
});

it('skips general shortcodes that are already registered', function () {
    // Simulate Amber having registered the shared general shortcodes
    // first. shortcode_exists() reads the real registry now, so "already
    // registered" means actually registering them — and "skipped" means
    // the callback still belongs to whoever got there first, rather than
    // the tag being absent as the old boolean-flag stub implied.
    $incumbent = static fn (): string => 'amber';
    foreach (['open_new_link', 'open_email', 'pdf_link', 'days_remaining'] as $tag) {
        add_shortcode($tag, $incumbent);
    }

    (new ShortcodeService())->registerShortcodes();

    expect(WpState::$shortcodes['open_new_link'])->toBe($incumbent)
        ->and($this->registeredShortcodes())->toContain('step');
});
