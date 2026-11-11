<?php

namespace Confur\Application;

/**
 * A submission that cannot be saved, with the HTTP status that says why.
 *
 * Thrown by SaveAnswers and turned into a response at the boundary
 * (AnswerHandler), so the rules do not know they are answering an HTTP request.
 */
final class SubmissionRejected extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Post does not exist.', 404);
    }

    public static function cancelled(): self
    {
        return new self('This registration has been cancelled.', 403);
    }
}
