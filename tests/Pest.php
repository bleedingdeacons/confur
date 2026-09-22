<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test in this suite runs on Tests\ConfurTestCase, which wraps
// wp-mocks' TestCase: it owns the Brain Monkey lifecycle, Mockery integration
// and the WpState reset between tests, and adds the post-keyed $this->fields /
// $this->titles / $this->statuses views and the seeding helpers the tests
// call as $this->makePost(), $this->seedFields() and so on. Every test class
// extended it before the conversion to Pest — Confur has no suite on plain
// PHPUnit — so the whole Unit directory is bound to it.
//
// So this line is load-bearing. A test file added outside tests/Unit finds no
// Brain Monkey functions and none of ConfurTestCase's helpers.

use Tests\ConfurTestCase;

pest()->extend(ConfurTestCase::class)->in('Unit');

/**
 * Runs $render inside an output buffer and returns what it printed.
 *
 * The admin screens echo their markup, so this is how their tests read it.
 * The buffer is closed in a finally, so a render that throws — wp_die() is a
 * WpDieException under the shared stubs — cannot leave it open and have
 * PHPUnit flag the test as risky.
 */
function captureOutput(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}
