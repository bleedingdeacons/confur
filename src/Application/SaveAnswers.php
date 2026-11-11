<?php

namespace Confur\Application;

use Confur\Config\Constants;
use Confur\Domain\AnswerKey;
use Confur\Domain\AnswerState;
use Confur\Domain\QuestionSet;
use Confur\Services\Mailer;
use Confur\Storage\AnswerStore;

/**
 * Saves a group's answers from its answer page.
 *
 * The rules, in order:
 * - the target must be an existing `answer` post that is not cancelled;
 * - the submission is copied to the backup address before anything is written;
 * - each changed answer is written;
 * - the group may ask for Draft or Complete, but Complete needs an answer to
 *   every question in the set — otherwise it is saved as a Draft and the
 *   group is told how many remain;
 * - Complete records the completion time and thanks the group by email.
 */
class SaveAnswers
{
    public function __construct(
        private readonly AnswerStore $store,
        private readonly Mailer $mailer,
    ) {
    }

    /**
     * @param array<mixed> $submitted The submitted form: answer fields plus anything else.
     * @param mixed $requestedState What the group asked for (the button it pressed).
     * @throws SubmissionRejected When the post cannot take answers.
     */
    public function save(int $postId, array $submitted, mixed $requestedState): SaveResult
    {
        $this->assertWritable($postId);

        // A safety net: sent before anything is written, and a failure to
        // send never stops the save.
        try {
            $this->mailer->sendBackup(get_permalink($postId) ?: '', $submitted);
        } catch (\Throwable $e) {
            error_log('SaveAnswers::save - Backup email failed: ' . $e->getMessage());
        }

        $this->writeAnswers($postId, $submitted);

        [$state, $message] = $this->decideState($postId, $requestedState);

        $updated = current_time('l, Y-m-d h:i:s A');

        // Stored raw; escaped where it is output.
        update_field(Constants::UPDATED_FIELD, $updated, $postId);
        update_field(Constants::STATUS_FIELD, $state->value, $postId);
        update_field(Constants::COMPLETION_FIELD, $state === AnswerState::Complete ? $updated : '', $postId);

        wp_publish_post($postId);

        if ($state === AnswerState::Complete) {
            try {
                $this->mailer->sendCompletion((string) get_field(Constants::EMAIL_FIELD, $postId), get_the_title($postId));
            } catch (\Throwable $e) {
                error_log('SaveAnswers::save - Completion email failed: ' . $e->getMessage());
            }
        }

        return new SaveResult($state, $updated, $message ?? 'Answers Saved as ' . $state->value);
    }

    /** @throws SubmissionRejected */
    private function assertWritable(int $postId): void
    {
        // An id can name any post on the site, so the type has to be checked:
        // without it a submission could write answer fields onto, and
        // publish, an unrelated post.
        if (get_post_status($postId) === false || get_post_type($postId) !== Constants::ANSWER_CUSTOM_TYPE) {
            throw SubmissionRejected::notFound();
        }

        // A cancelled registration is read-only. The client disables its
        // buttons, but that is a convenience, not the enforcement.
        if (AnswerState::fromStored(get_field(Constants::STATUS_FIELD, $postId)) === AnswerState::Cancelled) {
            throw SubmissionRejected::cancelled();
        }
    }

    /** @param array<mixed> $submitted */
    private function writeAnswers(int $postId, array $submitted): void
    {
        foreach ($submitted as $fieldName => $value) {
            $key = AnswerKey::parse((string) $fieldName);

            if ($key === null || !is_scalar($value)) {
                continue;
            }

            $text = sanitize_textarea_field((string) $value);

            if ($this->store->value($key->fieldName(), $postId) === $text) {
                continue;
            }

            // Field keys and post IDs only: the answers are members' words
            // and do not belong in the PHP error log.
            if (!$this->store->saveAnswer($postId, $key, $text)) {
                error_log(sprintf('SaveAnswers::writeAnswers - Could not save answer c%d_a%d on post %d', $key->committee, $key->question, $postId));
            }
        }
    }

    /**
     * Draft or Complete, and a message if Complete was refused.
     *
     * @return array{0: AnswerState, 1: string|null}
     */
    private function decideState(int $postId, mixed $requestedState): array
    {
        $requested = AnswerState::fromStored($requestedState);

        // Anything else, including Cancelled, is saved as a draft.
        if ($requested !== AnswerState::Complete) {
            return [AnswerState::Draft, null];
        }

        $unanswered = count(QuestionSet::current()->unanswered($this->store->load($postId, true)->answers));

        if ($unanswered === 0) {
            return [AnswerState::Complete, null];
        }

        error_log("SaveAnswers::decideState - Post $postId asked for Complete with $unanswered unanswered; saved as Draft");

        return [
            AnswerState::Draft,
            sprintf(
                'Answers Saved as Draft: %d %s still %s an answer.',
                $unanswered,
                $unanswered === 1 ? 'question' : 'questions',
                $unanswered === 1 ? 'needs' : 'need'
            ),
        ];
    }
}
