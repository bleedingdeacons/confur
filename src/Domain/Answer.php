<?php

namespace Confur\Domain;

/**
 * One group's registration and its answers, as loaded from an `answer` post.
 *
 * Plain data: AnswerStore builds it and nothing here calls WordPress. Group
 * ids are already normalised (ACF can hand back an id, a post object or an
 * array), so callers never see the raw field shapes.
 *
 * $status and $updated are kept exactly as stored — the admin screens compare
 * and display them as-is. Use state() for the interpreted lifecycle state.
 */
final readonly class Answer
{
    /**
     * @param array<string, string> $answers Answer field name => text, for
     *                                       every non-empty answer field
     *                                       (empty when loaded without them).
     */
    public function __construct(
        public int $postId,
        public ?int $groupId,
        public ?int $fellowGroupId,
        public ?string $email,
        public ?string $updated,
        public ?string $status,
        public array $answers = [],
    ) {
    }

    public function state(): AnswerState
    {
        return AnswerState::orDraft($this->status);
    }

    public function isCancelled(): bool
    {
        return $this->state() === AnswerState::Cancelled;
    }

    /** Registered for two groups jointly rather than one. */
    public function isPaired(): bool
    {
        return $this->fellowGroupId !== null;
    }

    /**
     * The group ids this registration covers, sorted, so a pair matches
     * regardless of which one was entered first.
     *
     * @return list<int>
     */
    public function groupIds(): array
    {
        $ids = array_values(array_filter([$this->groupId, $this->fellowGroupId], static fn (?int $id): bool => $id !== null));
        sort($ids);

        return $ids;
    }

    public function answer(AnswerKey $key): string
    {
        return $this->answers[$key->fieldName()] ?? '';
    }
}
