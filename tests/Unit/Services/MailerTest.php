<?php

namespace Tests\Unit\Services;

use BleedingDeacons\WpMocks\WpState;
use Confur\Config\Settings;
use Confur\Services\Mailer;

covers(Mailer::class);

beforeEach(function () {
    WpState::$options = [
        'confur_email_templates' => [
            'RegistrationConfirmation' => ['subject' => 'Confirmed', 'body' => 'Hello {{MeetingName}} {{Url}} {{AllocationNotice}} {{RegistrationStatus}}'],
            'AnswersComplete' => ['subject' => 'Done', 'body' => 'Thanks {{MeetingName}}'],
            'RegistrationBlocked' => ['subject' => 'Blocked', 'body' => 'You are blocked'],
        ],
    ];
    WpState::$mail = [];

    $this->mailer = new Mailer(new Settings(
        registrationReplyEmail: 'reply@example.org',
        supportEmail: 'support@example.org',
        backupEmail: 'backup@example.org',
    ));

    $this->lastMail = fn (): array => end(WpState::$mail) ?: [];
});

it('sends HTML from the given address, blind-copied to support', function () {
    expect($this->mailer->send('a@b.com', 'from@b.com', 'Sub', '<p>Body</p>'))->toBeTrue();

    $sent = ($this->lastMail)();
    expect($sent['to'])->toBe('a@b.com')
        ->and($sent['headers'])->toContain('Content-Type: text/html; charset=UTF-8', 'Bcc: support@example.org', 'From: from@b.com');
});

describe('the backup copy', function () {
    it('goes to the backup address, titled with the answer page', function () {
        expect($this->mailer->sendBackup('https://x.test/answer/abc', ['c1_a1' => 'Hi']))->toBeTrue();

        $sent = ($this->lastMail)();
        expect($sent['to'])->toBe('backup@example.org')
            ->and($sent['subject'])->toBe('https://x.test/answer/abc')
            ->and($sent['headers'])->toContain('From: support@example.org')
            ->and($sent['message'])->toContain('c1_a1', 'Hi');
    });

    it('escapes the submission rather than sending it as markup', function () {
        $escape = static fn (mixed $t): string => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        \Brain\Monkey\Functions\when('esc_html')->alias($escape);

        $this->mailer->sendBackup('https://x.test/a', ['c1_a1' => '<img src=x onerror=alert(1)>']);

        expect(($this->lastMail)()['message'])->toStartWith('<pre>')
            ->not->toContain('<img')
            ->toContain('&lt;img');
    });

    it('reports a failed send', function () {
        WpState::$mailResult = false;

        expect($this->mailer->sendBackup('https://x.test/a', []))->toBeFalse();
    });
});

describe('the registration confirmation', function () {
    it('refuses an invalid address', function () {
        expect($this->mailer->sendConfirmation('not-an-email', 'Group', 'http://x'))->toBeFalse()
            ->and(WpState::$mail)->toBe([]);
    });

    it('comes from the registration reply address', function () {
        $this->mailer->sendConfirmation('a@b.com', 'Group', 'http://x/a');

        expect(($this->lastMail)()['headers'])->toContain('From: reply@example.org')
            ->and(($this->lastMail)()['subject'])->toBe('Confirmed');
    });

    it('names the Last Question for an All Committees allocation', function () {
        $this->mailer->sendConfirmation('a@b.com', 'Group', 'http://x/a', '7');

        expect(($this->lastMail)()['message'])->toContain('Last Question');
    });

    it('names the committee and flags a repeat registration', function () {
        $this->mailer->sendConfirmation('a@b.com', 'Group', 'http://x/a', '3', true);

        expect(($this->lastMail)()['message'])->toContain('Committee: 3', 'already registered');
    });
});

describe('the completion thanks', function () {
    it('refuses an invalid address', function () {
        expect($this->mailer->sendCompletion('bad', 'Group'))->toBeFalse();
    });

    it('thanks the group from the Region Representatives', function () {
        expect($this->mailer->sendCompletion('a@b.com', 'Group'))->toBeTrue();

        $sent = ($this->lastMail)();
        expect($sent['message'])->toContain('Thanks Group')
            ->and($sent['headers'])->toContain('From: Region Representatives <reply@example.org>');
    });

    it('falls back to the bundled template file', function () {
        // No admin-customised template → the packaged emails/AnswersComplete.html.
        WpState::$options = [];

        expect($this->mailer->sendCompletion('a@b.com', 'Group'))->toBeTrue()
            ->and(($this->lastMail)()['message'])->not->toBe('');
    });
});

describe('the blocked-registration notice', function () {
    it('refuses an invalid address', function () {
        expect($this->mailer->sendRegistrationBlocked('bad'))->toBeFalse();
    });

    it('comes from support', function () {
        expect($this->mailer->sendRegistrationBlocked('a@b.com'))->toBeTrue();

        $sent = ($this->lastMail)();
        expect(strtolower($sent['message']))->toContain('blocked')
            ->and($sent['headers'])->toContain('From: support@example.org');
    });
});

it('sends a registration error as escaped text from support', function () {
    $escape = static fn (mixed $t): string => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    \Brain\Monkey\Functions\when('esc_html')->alias($escape);

    $this->mailer->sendRegistrationError('a@b.com', 'Error: X', 'Something <went> wrong');

    $sent = ($this->lastMail)();
    expect($sent['subject'])->toBe('Error: X')
        ->and($sent['message'])->toBe('<p>Something &lt;went&gt; wrong</p>')
        ->and($sent['headers'])->toContain('From: support@example.org');
});

it('builds itself from the saved settings', function () {
    WpState::$options['confur_email_settings'] = [
        'registration_reply' => 'saved-reply@example.org',
        'support' => 'saved-support@example.org',
        'backup' => 'saved-backup@example.org',
    ];

    Mailer::fromOptions()->sendBackup('https://x.test/a', []);

    expect(($this->lastMail)()['to'])->toBe('saved-backup@example.org');
});
