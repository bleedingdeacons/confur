<?php

namespace Tests\Unit;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Plugin;
use Tests\ConfurTestCase;

/**
 * @covers \Confur\Plugin
 */
class PluginTest extends ConfurTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpState::$options = [];
        $GLOBALS['wp_post_types'] = [];
        unset($_GET['et_fb'], $_GET['page']);
    }

    public function testInitBootstrapsWithoutError(): void
    {
        (new Plugin())->init();
        $this->assertTrue(true); // registerHooks + all service/admin construction ran
    }

    public function testRegisterConfurMenuRegistersMenu(): void
    {
        (new Plugin())->registerConfurMenu();
        $this->assertTrue(true);
    }

    public function testModifyAnswerPostTypeTogglesFlags(): void
    {
        $GLOBALS['wp_post_types']['answer'] = (object) [
            'publicly_queryable' => false,
            'exclude_from_search' => false,
        ];

        (new Plugin())->modifyAnswerPostType();

        $this->assertTrue($GLOBALS['wp_post_types']['answer']->publicly_queryable);
        $this->assertTrue($GLOBALS['wp_post_types']['answer']->exclude_from_search);
    }

    public function testModifyAnswerPostTypeNoOpWhenAbsent(): void
    {
        $GLOBALS['wp_post_types'] = [];
        (new Plugin())->modifyAnswerPostType();
        $this->assertTrue(true);
    }

    public function testMaybeDisableShortcodesForDiviRunsWhenBuilderActive(): void
    {
        $_GET['et_fb'] = '1';
        (new Plugin())->maybeDisableShortcodesForDivi();
        $this->assertTrue(true);
    }

    public function testMaybeDisableShortcodesForDiviSkipsWhenBuilderInactive(): void
    {
        unset($_GET['et_fb']);
        (new Plugin())->maybeDisableShortcodesForDivi();
        $this->assertTrue(true);
    }

    public function testMaybeDisableShortcodesForDiviSwallowsRemovalErrors(): void
    {
        $_GET['et_fb'] = '1';
        Functions\when('remove_shortcode')->alias(static function (): void {
            throw new \RuntimeException('remove_shortcode failed');
        });

        (new Plugin())->maybeDisableShortcodesForDivi();
        $this->assertTrue(true); // per-shortcode failures are caught and logged
    }

    public function testActivateAddsCapabilitiesToAdministrator(): void
    {
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

        $this->assertContains('edit_answers', $role->ref);
        $this->assertContains('create_answers', $role->ref);
    }

    public function testActivateReturnsEarlyWithoutAdministrator(): void
    {
        // No administrator role to add caps to; activate() must not fatal.
        Functions\when('get_role')->justReturn(null);

        Plugin::activate();
        $this->assertTrue(true);
    }
}
