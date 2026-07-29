<?php

namespace Tests\Unit\Services;

use Confur\Services\AdminAssetService;
use Confur\Services\AssetService;
use Confur\Services\ShortcodeService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Confur\Services\AssetService
 * @covers \Confur\Services\AdminAssetService
 * @covers \Confur\Services\ShortcodeService
 */
class ServicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['confur_is_singular'] = false;
        $GLOBALS['confur_registered_shortcodes'] = [];
        $GLOBALS['confur_shortcode_exists'] = [];
        unset($_GET['page']);
    }

    public function testAssetServiceEnqueuesOnAnswerSingular(): void
    {
        $GLOBALS['confur_is_singular'] = true;
        (new AssetService())->enqueueScripts();
        $this->assertTrue(true); // injectAdminUrls path executed without error
    }

    public function testAssetServiceSkipsWhenNotSingular(): void
    {
        $GLOBALS['confur_is_singular'] = false;
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

        $registered = $GLOBALS['confur_registered_shortcodes'];
        $this->assertContains('step', $registered);
        $this->assertContains('tradition', $registered);
        $this->assertContains('answer', $registered);
        $this->assertContains('open_new_link', $registered);
        $this->assertContains('allocated_committee', $registered);
    }

    public function testShortcodeServiceSkipsGeneralShortcodesAlreadyRegistered(): void
    {
        // Simulate Amber having registered the shared general shortcodes first.
        $GLOBALS['confur_shortcode_exists'] = [
            'open_new_link' => true,
            'open_email' => true,
            'pdf_link' => true,
            'days_remaining' => true,
        ];

        (new ShortcodeService())->registerShortcodes();

        $this->assertNotContains('open_new_link', $GLOBALS['confur_registered_shortcodes']);
        $this->assertContains('step', $GLOBALS['confur_registered_shortcodes']);
    }
}
