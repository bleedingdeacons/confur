<?php

namespace Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use Confur\Shortcodes\GeneralShortcodes;
use Tests\ConfurTestCase;

/**
 * @covers \Confur\Shortcodes\GeneralShortcodes
 */
class GeneralShortcodesTest extends ConfurTestCase
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
    //
    // WordPress declares these as esc_x(string $text), so handing one an array
    // is a TypeError — which is exactly what these branches catch. wp-mocks'
    // escaping stubs are deliberately permissive, taking mixed and casting, so
    // each of these tests restores the real signature first.

    private function strictEscaping(): void
    {
        $strict = static function (mixed $text) use (&$strict): string {
            if (!is_string($text)) {
                throw new \TypeError('Argument #1 must be of type string');
            }

            return $text;
        };

        foreach (['esc_attr', 'esc_url', 'esc_html'] as $fn) {
            Functions\when($fn)->alias($strict);
        }
    }

    public function testOpenBlankReturnsErrorOnThrow(): void
    {
        $this->strictEscaping();

        $out = $this->sc->openBlank(['href' => ['array'], 'class' => ''], 'x');
        $this->assertStringContainsString('openBlank error', $out);
    }

    public function testLinkEmailReturnsErrorOnThrow(): void
    {
        $this->strictEscaping();

        $out = $this->sc->linkEmail(['address' => ['array'], 'subject' => null], 'x');
        $this->assertStringContainsString('linkEmail error', $out);
    }

    public function testGeneratePdfLinkReturnsErrorOnThrow(): void
    {
        $this->strictEscaping();

        $out = $this->sc->generatePdfLink(['url' => ['array'], 'name' => 'f.pdf'], 'x');
        $this->assertStringContainsString('generatePdfLink error', $out);
    }

    public function testDaysRemainingReturnsErrorOnThrow(): void
    {
        $out = $this->sc->generateDaysRemaining(['end_date' => ['array'], 'extend_by' => 0]);
        $this->assertStringContainsString('generateDaysRemaining error', $out);
    }
}
