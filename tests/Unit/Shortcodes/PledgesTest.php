<?php

namespace Tests\Unit\Shortcodes;

use Confur\Shortcodes\ResponsibilityPledgeShortcode;
use Confur\Shortcodes\StepShortcode;
use Confur\Shortcodes\TraditionShortcode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Confur\Shortcodes\StepShortcode
 * @covers \Confur\Shortcodes\TraditionShortcode
 * @covers \Confur\Shortcodes\ResponsibilityPledgeShortcode
 */
class PledgesTest extends TestCase
{
    public function testStepRendersValidNumber(): void
    {
        $out = (new StepShortcode())->render(['number' => ' 3 ']);
        $this->assertStringContainsString('Step 3.', $out);
        $this->assertStringContainsString('en_step3.pdf', $out);
    }

    public function testStepRejectsUnknownNumber(): void
    {
        $this->assertSame('', (new StepShortcode())->render(['number' => '99']));
        $this->assertSame('', (new StepShortcode())->render([]));
    }

    public function testTraditionRendersValidNumber(): void
    {
        $out = (new TraditionShortcode())->render(['number' => '1']);
        $this->assertStringContainsString('Tradition 1.', $out);
        $this->assertStringContainsString('en_tradition1.pdf', $out);
    }

    public function testTraditionRejectsUnknownNumber(): void
    {
        $this->assertSame('', (new TraditionShortcode())->render(['number' => '0']));
    }

    public function testResponsibilityPledgeRendersAndRejects(): void
    {
        $shortcode = new ResponsibilityPledgeShortcode();
        $this->assertStringContainsString('Step 12.', $shortcode->render(['number' => '12']));
        $this->assertSame('', $shortcode->render(['number' => '13']));
    }
}
