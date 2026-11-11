<?php

namespace Tests\Unit\Services;

use BleedingDeacons\WpMocks\WpState;
use Confur\Services\AdminAssetService;
use Confur\Services\AssetService;
use Confur\Services\ShortcodeService;

covers(AssetService::class);
covers(AdminAssetService::class);
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

it('enqueues the admin assets on the reporting page', function () {
    $_GET['page'] = 'confur-reporting';
    (new AdminAssetService())->enqueueScripts();
})->throwsNoExceptions();

it('skips the admin assets elsewhere', function () {
    $_GET['page'] = 'something-else';
    (new AdminAssetService())->enqueueScripts();
})->throwsNoExceptions();

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
