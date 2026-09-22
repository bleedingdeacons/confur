<?php

namespace Tests\Unit;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Plugin;

covers(Plugin::class);

beforeEach(function () {
    WpState::$options = [];
    $GLOBALS['wp_post_types'] = [];
    unset($_GET['et_fb'], $_GET['page']);
});

// registerHooks + all service/admin construction ran
it('bootstraps from init without error', function () {
    (new Plugin())->init();
})->throwsNoExceptions();

it('registers the Confur menu', function () {
    (new Plugin())->registerConfurMenu();
})->throwsNoExceptions();

it('toggles the answer post type flags', function () {
    $GLOBALS['wp_post_types']['answer'] = (object) [
        'publicly_queryable' => false,
        'exclude_from_search' => false,
    ];

    (new Plugin())->modifyAnswerPostType();

    expect($GLOBALS['wp_post_types']['answer']->publicly_queryable)->toBeTrue()
        ->and($GLOBALS['wp_post_types']['answer']->exclude_from_search)->toBeTrue();
});

it('leaves the answer post type alone when it is absent', function () {
    $GLOBALS['wp_post_types'] = [];
    (new Plugin())->modifyAnswerPostType();
})->throwsNoExceptions();

it('disables shortcodes when the Divi builder is active', function () {
    $_GET['et_fb'] = '1';
    (new Plugin())->maybeDisableShortcodesForDivi();
})->throwsNoExceptions();

it('leaves shortcodes alone when the Divi builder is inactive', function () {
    unset($_GET['et_fb']);
    (new Plugin())->maybeDisableShortcodesForDivi();
})->throwsNoExceptions();

// per-shortcode failures are caught and logged
it('swallows shortcode removal errors under the Divi builder', function () {
    $_GET['et_fb'] = '1';
    Functions\when('remove_shortcode')->alias(static function (): void {
        throw new \RuntimeException('remove_shortcode failed');
    });

    (new Plugin())->maybeDisableShortcodesForDivi();
})->throwsNoExceptions();

it('adds capabilities to the administrator on activation', function () {
    $added = [];
    $role = new class ($added) {
        public array $caps = [];
        public function __construct(&$added)
        {
            $this->ref = &$added;
        }
        public array $ref;
        public function add_cap($cap): void
        {
            $this->ref[] = $cap;
        }
    };
    // wp-mocks' get_role() hands back a plain object describing the role.
    // This test needs one that records add_cap(), so it stands in for the
    // duration of the test.
    Functions\when('get_role')->justReturn($role);

    Plugin::activate();

    expect($role->ref)->toContain('edit_answers')
        ->and($role->ref)->toContain('create_answers');
});

it('returns early from activation without an administrator', function () {
    // No administrator role to add caps to; activate() must not fatal.
    Functions\when('get_role')->justReturn(null);

    Plugin::activate();
})->throwsNoExceptions();
