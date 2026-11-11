<?php

namespace Confur\Application;

use Confur\Domain\AnswerState;

/** What a save did: the state it was saved in, when, and what to tell the group. */
final readonly class SaveResult
{
    public function __construct(
        public AnswerState $state,
        public string $updated,
        public string $message,
    ) {
    }
}
