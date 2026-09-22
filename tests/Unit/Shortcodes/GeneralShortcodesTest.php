<?php

namespace Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use Confur\Shortcodes\GeneralShortcodes;

covers(GeneralShortcodes::class);

// ── error branches: a non-scalar attribute makes an inner call throw ──
//
// WordPress declares these as esc_x(string $text), so handing one an array
// is a TypeError — which is exactly what these branches catch. wp-mocks'
// escaping stubs are deliberately permissive, taking mixed and casting, so
// each of these tests restores the real signature first.

function strictEscaping(): void
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

beforeEach(function () {
    $this->sc = new GeneralShortcodes();
});

it('renders openBlank', function () {
    $out = $this->sc->openBlank(['href' => 'http://x', 'class' => 'btn'], 'Go');
    expect($out)->toContain('href="http://x"', '>Go</a>');
});

it('renders linkEmail with an address', function () {
    $out = $this->sc->linkEmail(['address' => 'a@b.com', 'subject' => 'Hi'], 'Mail');
    expect($out)->toContain('mailto:a@b.com');
});

it('returns the content from linkEmail without an address', function () {
    expect($this->sc->linkEmail([], 'fallback'))->toBe('fallback');
});

it('renders generatePdfLink on the happy path', function () {
    $out = $this->sc->generatePdfLink(['url' => 'http://x/f.pdf', 'name' => 'f.pdf'], 'Get');
    expect($out)->toContain('<div>', 'download="f.pdf"');
});

it('reports missing parameters from generatePdfLink', function () {
    $out = $this->sc->generatePdfLink(['url' => '', 'name' => ''], 'x');
    expect($out)->toContain('Missing required parameters');
});

it('asks for an end date in generateDaysRemaining', function () {
    expect($this->sc->generateDaysRemaining([]))->toBe('Please provide an end date.');
});

it('rejects an invalid date in generateDaysRemaining', function () {
    $out = $this->sc->generateDaysRemaining(['end_date' => 'not-a-date']);
    expect($out)->toContain('Invalid date format');
});

it('reports a passed date in generateDaysRemaining', function () {
    $out = $this->sc->generateDaysRemaining(['end_date' => '2000-01-01']);
    expect($out)->toContain('already passed');
});

it('counts a future date in days in generateDaysRemaining', function () {
    $future = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+10 days')->format('Y-m-d');
    $out = $this->sc->generateDaysRemaining(['end_date' => $future]);
    expect($out)->toContain('Deadline:', 'remaining');
});

it('applies an extension in generateDaysRemaining', function () {
    $future = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    $out = $this->sc->generateDaysRemaining(['end_date' => $future, 'extend_by' => 3]);
    expect($out)->toContain('extended by 3 days');
});

it('counts a future date in hours in generateDaysRemaining', function () {
    $future = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+3 hours')->format('Y-m-d H:i:s');
    $out = $this->sc->generateDaysRemaining(['end_date' => $future]);
    expect($out)->toContain('hour', 'remaining');
});

it('returns an error from openBlank when it throws', function () {
    strictEscaping();

    $out = $this->sc->openBlank(['href' => ['array'], 'class' => ''], 'x');
    expect($out)->toContain('openBlank error');
});

it('returns an error from linkEmail when it throws', function () {
    strictEscaping();

    $out = $this->sc->linkEmail(['address' => ['array'], 'subject' => null], 'x');
    expect($out)->toContain('linkEmail error');
});

it('returns an error from generatePdfLink when it throws', function () {
    strictEscaping();

    $out = $this->sc->generatePdfLink(['url' => ['array'], 'name' => 'f.pdf'], 'x');
    expect($out)->toContain('generatePdfLink error');
});

it('returns an error from generateDaysRemaining when it throws', function () {
    $out = $this->sc->generateDaysRemaining(['end_date' => ['array'], 'extend_by' => 0]);
    expect($out)->toContain('generateDaysRemaining error');
});
