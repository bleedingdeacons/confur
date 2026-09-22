<?php

namespace Tests\Unit\Services;

use BleedingDeacons\WpMocks\WpState;
use Confur\Services\EmailService;

covers(EmailService::class);

beforeEach(function () {
    WpState::$options = [
        'confur_email_templates' => [
            'RegistrationConfirmation' => ['subject' => 'Confirmed', 'body' => 'Hello {{MeetingName}} {{Url}} {{AllocationNotice}} {{RegistrationStatus}}'],
            'AnswersComplete' => ['subject' => 'Done', 'body' => 'Thanks {{MeetingName}}'],
            'RegistrationBlocked' => ['subject' => 'Blocked', 'body' => 'You are blocked'],
        ],
    ];
    WpState::$mail = [];
});

it('passes sendEmail through wp_mail', function () {
    expect(EmailService::sendEmail('a@b.com', 'from@b.com', 'Sub', '<p>Body</p>'))->toBeTrue()
        ->and(WpState::$mail)->toHaveCount(1);
});

it('reports success and failure from sendBackup', function () {
    expect(EmailService::sendBackup('a@b.com', 'from@b.com', 'S', 'B'))->toBeTrue();

    WpState::$mailResult = false;
    expect(EmailService::sendBackup('a@b.com', 'from@b.com', 'S', 'B'))->toBeFalse();
});

it('rejects an invalid email in sendConfirmation', function () {
    expect(EmailService::sendConfirmation('not-an-email', 'Group', 'http://x'))->toBeFalse();
});

it('sends a confirmation with a Last Question allocation', function () {
    expect(EmailService::sendConfirmation('a@b.com', 'Group', 'http://x/a', '7'))->toBeTrue();
    $sent = end(WpState::$mail);
    expect($sent['message'])->toContain('Last Question');
});

it('sends a confirmation with a committee allocation and the duplicate flag', function () {
    expect(EmailService::sendConfirmation('a@b.com', 'Group', 'http://x/a', '3', true))->toBeTrue();
    $sent = end(WpState::$mail);
    expect($sent['message'])->toContain('Committee: 3', 'already registered');
});

it('rejects an invalid email in sendCompletion', function () {
    expect(EmailService::sendCompletion('bad', 'Group'))->toBeFalse();
});

it('sends a completion email', function () {
    expect(EmailService::sendCompletion('a@b.com', 'Group'))->toBeTrue();
    $sent = end(WpState::$mail);
    expect($sent['message'])->toContain('Thanks Group');
});

it('rejects an invalid email in sendRegistrationBlocked', function () {
    expect(EmailService::sendRegistrationBlocked('bad'))->toBeFalse();
});

it('sends a registration-blocked email', function () {
    expect(EmailService::sendRegistrationBlocked('a@b.com'))->toBeTrue();
    $sent = end(WpState::$mail);
    expect(strtolower($sent['message']))->toContain('blocked');
});

it('falls back to the bundled template file', function () {
    // No admin-customised template → renderTemplate() reads the packaged
    // emails/AnswersComplete.html file instead.
    WpState::$options = [];

    expect(EmailService::sendCompletion('a@b.com', 'Group'))->toBeTrue();
    $sent = end(WpState::$mail);
    expect($sent['message'])->not->toBe('');
});
