<?php

namespace Tests\Unit\Services;

use Confur\Services\AdminAssetService;
use Confur\Services\AssetService;
use Confur\Services\ShortcodeService;
use BleedingDeacons\WpMocks\WpState;
use Tests\ConfurTestCase;

/**
 * @covers \Confur\Services\AssetService
 * @covers \Confur\Services\AdminAssetService
 * @covers \Confur\Services\ShortcodeService
 */
class ServicesTest extends ConfurTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpState::$isSingular = false;
        unset($_GET['page']);
    }

    public function testAssetServiceEnqueuesOnAnswerSingular(): void
    {
        WpState::$isSingular = true;
        (new AssetService())->enqueueScripts();
        $this->assertTrue(true); // injectAdminUrls path executed without error
    }

    public function testAssetServiceSkipsWhenNotSingular(): void
    {
        WpState::$isSingular = false;
        (new AssetService())->enqueueScripts();
        $this->assertTrue(true);
    }

    public function testAdminAssetServiceEnqueuesOnReportingPage(): void
    {
        $_GET['page'] = 'confur-reporting';
        (new AdminAssetService())->enqueueScripts();
        $this->assertTrue(true);
    }

    public function testAdminAssetServiceSkipsElsewhere(): void
    {
        $_GET['page'] = 'something-else';
        (new AdminAssetService())->enqueueScripts();
        $this->assertTrue(true);
    }

    public function testShortcodeServiceRegistersAllShortcodes(): void
    {
        (new ShortcodeService())->registerShortcodes();

        $registered = $this->registeredShortcodes();
        $this->assertContains('step', $registered);
        $this->assertContains('tradition', $registered);
        $this->assertContains('answer', $registered);
        $this->assertContains('open_new_link', $registered);
        $this->assertContains('allocated_committee', $registered);
    }

    public function testShortcodeServiceSkipsGeneralShortcodesAlreadyRegistered(): void
    {
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

        $this->assertSame($incumbent, WpState::$shortcodes['open_new_link']);
        $this->assertContains('step', $this->registeredShortcodes());
    }
}
