<?php

declare(strict_types=1);

namespace Tests\Unit\Logger;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Logger\HasLogger;

covers(HasLogger::class);

beforeEach(function () {
    $this->logger = new class {
        use HasLogger;
    };
});

describe('with Sentinel', function () {
    it('logs every class on the one confur channel', function () {
        $this->logger::logError('Something failed', ['post_id' => 3]);

        expect(WpState::$logs)->toBe([['confur', 'error', 'Something failed', ['post_id' => 3]]]);
    });

    it('forwards each level as itself', function (string $method, string $level) {
        $this->logger::$method('Message');

        expect(WpState::$logs[0][1])->toBe($level);
    })->with([
        ['logCritical', 'critical'],
        ['logError', 'error'],
        ['logWarning', 'warning'],
        ['logNotice', 'notice'],
        ['logInfo', 'info'],
        ['logDebug', 'debug'],
    ]);
});

describe('without Sentinel', function () {
    beforeEach(function () {
        // The wp-mocks stub's nullable return type exists for this: it is how
        // a test switches the shared logger off.
        Functions\when('wp_log')->justReturn(null);

        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'confur-log');
        $this->previousErrorLog = ini_set('error_log', $this->errorLog);
    });

    afterEach(function () {
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errorLog);
    });

    it('falls back to the PHP error log, naming the plugin, level and context', function () {
        $this->logger::logWarning('Something odd', ['post_id' => 3]);

        expect(WpState::$logs)->toBe([])
            ->and((string) file_get_contents($this->errorLog))->toContain('[confur] WARNING: Something odd {"post_id":3}');
    });

    it('leaves the context off when there is none', function () {
        $this->logger::logInfo('Plain');

        expect((string) file_get_contents($this->errorLog))->toMatch('/\[confur\] INFO: Plain\R/');
    });
});

describe('exceptionContext', function () {
    it('names the message and class, and the trace only when asked', function () {
        $e = new \RuntimeException('Broke');

        expect($this->logger::exceptionContext($e))->toBe(['error' => 'Broke', 'exception' => \RuntimeException::class])
            ->and($this->logger::exceptionContext($e, true))->toHaveKey('trace');
    });
});
