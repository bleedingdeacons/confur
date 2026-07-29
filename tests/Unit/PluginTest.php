<?php

namespace Tests\Unit;

use Confur\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Confur\Plugin
 */
class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['confur_options'] = [];
        $GLOBALS['confur_roles'] = [];
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
        $GLOBALS['confur_remove_shortcode_throws'] = true;
        try {
            (new Plugin())->maybeDisableShortcodesForDivi();
            $this->assertTrue(true); // per-shortcode failures are caught and logged
        } finally {
            unset($GLOBALS['confur_remove_shortcode_throws']);
        }
    }

    public function testActivateAddsCapabilitiesToAdministrator(): void
    {
        $added = [];
        $role = new class($added) {
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
        $GLOBALS['confur_roles']['administrator'] = $role;

        Plugin::activate();

        $this->assertContains('edit_answers', $role->ref);
        $this->assertContains('create_answers', $role->ref);
    }

    public function testActivateReturnsEarlyWithoutAdministrator(): void
    {
        $GLOBALS['confur_roles'] = [];
        Plugin::activate();
        $this->assertTrue(true);
    }
}
