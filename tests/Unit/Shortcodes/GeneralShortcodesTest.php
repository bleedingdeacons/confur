<?php

namespace Tests\Unit\Shortcodes;

use Confur\Shortcodes\GeneralShortcodes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Confur\Shortcodes\GeneralShortcodes
 */
class GeneralShortcodesTest extends TestCase
{
    private GeneralShortcodes $sc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sc = new GeneralShortcodes();
    }

    public function testOpenBlank(): void
    {
        $out = $this->sc->openBlank(['href' => 'http://x', 'class' => 'btn'], 'Go');
        $this->assertStringContainsString('href="http://x"', $out);
        $this->assertStringContainsString('>Go</a>', $out);
    }

    public function testLinkEmailWithAddress(): void
    {
        $out = $this->sc->linkEmail(['address' => 'a@b.com', 'subject' => 'Hi'], 'Mail');
        $this->assertStringContainsString('mailto:a@b.com', $out);
    }

    public function testLinkEmailWithoutAddressReturnsContent(): void
    {
        $this->assertSame('fallback', $this->sc->linkEmail([], 'fallback'));
    }

    public function testGeneratePdfLinkHappyPath(): void
    {
        $out = $this->sc->generatePdfLink(['url' => 'http://x/f.pdf', 'name' => 'f.pdf'], 'Get');
        $this->assertStringContainsString('<div>', $out);
        $this->assertStringContainsString('download="f.pdf"', $out);
    }

    public function testGeneratePdfLinkMissingParams(): void
    {
        $out = $this->sc->generatePdfLink(['url' => '', 'name' => ''], 'x');
        $this->assertStringContainsString('Missing required parameters', $out);
    }

    public function testDaysRemainingNoEndDate(): void
    {
        $this->assertSame('Please provide an end date.', $this->sc->generateDaysRemaining([]));
    }

    public function testDaysRemainingInvalidDate(): void
    {
        $out = $this->sc->generateDaysRemaining(['end_date' => 'not-a-date']);
        $this->assertStringContainsString('Invalid date format', $out);
    }

    public function testDaysRemainingPassedDate(): void
    {
        $out = $this->sc->generateDaysRemaining(['end_date' => '2000-01-01']);
        $this->assertStringContainsString('already passed', $out);
    }

    public function testDaysRemainingFutureDateInDays(): void
    {
        $future = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+10 days')->format('Y-m-d');
        $out = $this->sc->generateDaysRemaining(['end_date' => $future]);
        $this->assertStringContainsString('Deadline:', $out);
        $this->assertStringContainsString('remaining', $out);
    }

    public function testDaysRemainingWithExtension(): void
    {
        $future = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
        $out = $this->sc->generateDaysRemaining(['end_date' => $future, 'extend_by' => 3]);
        $this->assertStringContainsString('extended by 3 days', $out);
    }

    public function testDaysRemainingFutureDateInHours(): void
    {
        $future = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+3 hours')->format('Y-m-d H:i:s');
        $out = $this->sc->generateDaysRemaining(['end_date' => $future]);
        $this->assertStringContainsString('hour', $out);
        $this->assertStringContainsString('remaining', $out);
    }

    // ── error branches: a non-scalar attribute makes an inner call throw ──

    public function testOpenBlankReturnsErrorOnThrow(): void
    {
        $out = $this->sc->openBlank(['href' => ['array'], 'class' => ''], 'x');
        $this->assertStringContainsString('openBlank error', $out);
    }

    public function testLinkEmailReturnsErrorOnThrow(): void
    {
        $out = $this->sc->linkEmail(['address' => ['array'], 'subject' => null], 'x');
        $this->assertStringContainsString('linkEmail error', $out);
    }

    public function testGeneratePdfLinkReturnsErrorOnThrow(): void
    {
        $out = $this->sc->generatePdfLink(['url' => ['array'], 'name' => 'f.pdf'], 'x');
        $this->assertStringContainsString('generatePdfLink error', $out);
    }

    public function testDaysRemainingReturnsErrorOnThrow(): void
    {
        $out = $this->sc->generateDaysRemaining(['end_date' => ['array'], 'extend_by' => 0]);
        $this->assertStringContainsString('generateDaysRemaining error', $out);
    }
}
