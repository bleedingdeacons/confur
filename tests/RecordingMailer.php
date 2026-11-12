<?php

namespace Tests;

use Confur\Config\Settings;
use Confur\Services\Mailer;

/**
 * A Mailer that records what it was asked to send instead of sending it.
 *
 * The application services take a Mailer in their constructor; handing them
 * one of these lets a test assert on the emails as calls — who, which kind,
 * with what — rather than by parsing rendered templates out of wp_mail().
 * Set $throwOn to a method name to have that send throw, for the paths that
 * must survive a mail failure.
 */
final class RecordingMailer extends Mailer
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $sent = [];

    public ?string $throwOn = null;

    public function __construct()
    {
        parent::__construct(new Settings('reply@example.org', 'support@example.org', 'backup@example.org'));
    }

    /** @return list<list<mixed>> The arguments of each call to $method. */
    public function calls(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[1],
            array_filter($this->sent, static fn (array $call): bool => $call[0] === $method)
        ));
    }

    public function send(string $recipient, string $from, string $subject, string $body): bool
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function sendBackup(string $pageUrl, array $submitted): bool
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function sendConfirmation(string $recipient, string $groupName, string $answerUrl, string $allocatedCommittee = '', bool $isDuplicate = false): bool
    {
        return $this->record(__FUNCTION__, [$recipient, $groupName, $answerUrl, $allocatedCommittee, $isDuplicate]);
    }

    public function sendCompletion(string $recipient, string $groupName): bool
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function sendRegistrationBlocked(string $recipient): bool
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function sendRegistrationError(string $recipient, string $subject, string $message): bool
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    /** @param list<mixed> $args */
    private function record(string $method, array $args): bool
    {
        if ($this->throwOn === $method) {
            throw new \RuntimeException("$method failed");
        }

        $this->sent[] = [$method, $args];

        return true;
    }
}
