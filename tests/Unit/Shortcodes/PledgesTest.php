<?php

namespace Tests\Unit\Shortcodes;

use Confur\Shortcodes\ResponsibilityPledgeShortcode;
use Confur\Shortcodes\StepShortcode;
use Confur\Shortcodes\TraditionShortcode;

covers(StepShortcode::class);
covers(TraditionShortcode::class);
covers(ResponsibilityPledgeShortcode::class);

it('renders a valid step number', function () {
    $out = (new StepShortcode())->render(['number' => ' 3 ']);
    expect($out)->toContain('Step 3.', 'en_step3.pdf');
});

it('rejects an unknown step number', function () {
    expect((new StepShortcode())->render(['number' => '99']))->toBe('')
        ->and((new StepShortcode())->render([]))->toBe('');
});

it('renders a valid tradition number', function () {
    $out = (new TraditionShortcode())->render(['number' => '1']);
    expect($out)->toContain('Tradition 1.', 'en_tradition1.pdf');
});

it('rejects an unknown tradition number', function () {
    expect((new TraditionShortcode())->render(['number' => '0']))->toBe('');
});

it('renders and rejects responsibility pledge numbers', function () {
    $shortcode = new ResponsibilityPledgeShortcode();
    expect($shortcode->render(['number' => '12']))->toContain('Step 12.')
        ->and($shortcode->render(['number' => '13']))->toBe('');
});
