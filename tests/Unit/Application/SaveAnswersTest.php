<?php

namespace Tests\Unit\Application;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Confur\Application\SaveAnswers;
use Confur\Application\SubmissionRejected;
use Confur\Config\Constants;
use Confur\Domain\AnswerState;
use Confur\Domain\QuestionSet;
use Confur\Storage\AnswerStore;
use Tests\RecordingMailer;

covers(SaveAnswers::class, SubmissionRejected::class);

beforeEach(function () {
    $this->mailer = new RecordingMailer();
    $this->service = new SaveAnswers(new AnswerStore(), $this->mailer);

    // An answer post the group can write to.
    $this->answerPost = function (int $id, array $fields = []): void {
        $this->statuses[$id] = 'publish';
        WpState::$postTypes[$id] = Constants::ANSWER_CUSTOM_TYPE;
        $this->fields[$id] = $fields + [Constants::EMAIL_FIELD => 'group@example.org'];
    };

    $this->everyAnswer = function (): array {
        $answers = [];
        foreach (QuestionSet::current()->fieldNames() as $field) {
            $answers[$field] = "Answer to $field";
        }
        return $answers;
    };
});

describe('what it refuses', function () {
    it('refuses a post that does not exist', function () {
        $this->statuses[5] = false;

        expect(fn () => $this->service->save(5, [], 'Draft'))
            ->toThrow(SubmissionRejected::class, 'Post does not exist.');
    });

    it('refuses a post that is not an answer, with a 404', function () {
        $this->statuses[6] = 'publish';
        WpState::$postTypes[6] = 'page';

        try {
            $this->service->save(6, ['c1_a1' => 'Injected'], 'Draft');
            $this->fail('Expected a rejection.');
        } catch (SubmissionRejected $e) {
            expect($e->status)->toBe(404)
                ->and(get_field('c1_a1', 6))->toBeNull()
                ->and($this->mailer->sent)->toBe([]); // not even the backup
        }
    });

    it('refuses a cancelled registration, with a 403, however the state is spelled', function (string $stored) {
        ($this->answerPost)(7, [Constants::STATUS_FIELD => $stored]);

        try {
            $this->service->save(7, ['c1_a1' => 'Late'], 'Draft');
            $this->fail('Expected a rejection.');
        } catch (SubmissionRejected $e) {
            expect($e->status)->toBe(403)
                ->and(get_field('c1_a1', 7))->toBeNull();
        }
    })->with(['Cancelled', 'cancelled']);
});

describe('saving', function () {
    it('backs up the submission before writing anything', function () {
        ($this->answerPost)(10);

        $this->service->save(10, ['c1_a1' => 'Yes', 'submit_answers' => 'Draft'], 'Draft');

        expect($this->mailer->calls('sendBackup'))->toHaveCount(1)
            ->and($this->mailer->calls('sendBackup')[0][1])->toBe(['c1_a1' => 'Yes', 'submit_answers' => 'Draft']);
    });

    it('saves even when the backup email fails', function () {
        ($this->answerPost)(11);
        $this->mailer->throwOn = 'sendBackup';

        $result = $this->service->save(11, ['c1_a1' => 'Kept'], 'Draft');

        expect($result->state)->toBe(AnswerState::Draft)
            ->and(get_field('c1_a1', 11))->toBe('Kept');
    });

    it('writes answer fields only, ignoring everything else submitted', function () {
        ($this->answerPost)(12);

        $this->service->save(12, ['c1_a1' => 'Yes', 'meeting' => 999, 'c1_q1' => 'no', 'c2_a1' => ['array']], 'Draft');

        expect(get_field('c1_a1', 12))->toBe('Yes')
            ->and(get_field('meeting', 12))->toBeNull()
            ->and(get_field('c1_q1', 12))->toBeNull()
            ->and(get_field('c2_a1', 12))->toBeNull();
    });

    it('compares against the post being saved, not the current post', function () {
        // A REST request has no current post. If the change check read
        // there, a value matching whatever it found would never be saved.
        ($this->answerPost)(15, ['c1_a1' => 'Old']);
        update_field('c1_a1', 'New'); // the "current post" already holds the new text

        $this->service->save(15, ['c1_a1' => 'New'], 'Draft');

        expect(get_field('c1_a1', 15))->toBe('New');
    });

    it('carries on and logs when an answer cannot be written', function () {
        ($this->answerPost)(16);
        Functions\when('update_field')->justReturn(false);

        expect($this->service->save(16, ['c1_a1' => 'Lost'], 'Draft')->state)->toBe(AnswerState::Draft);
    });

    it('records the time and state, and publishes the post', function () {
        ($this->answerPost)(13);
        Functions\expect('current_time')->with('mysql')->andReturn('2024-01-01 13:02:03');
        Functions\expect('wp_publish_post')->once()->with(13);

        $result = $this->service->save(13, [], 'Draft');

        // Stored in the date-time picker's own format; shown as the field
        // formats it, matching the status endpoint.
        expect($result->updated)->toBe('Monday, January 1, 2024 1:02 PM')
            ->and($result->message)->toBe('Answers Saved as Draft')
            ->and(get_field(Constants::UPDATED_FIELD, 13))->toBe('2024-01-01 13:02:03')
            ->and(get_field(Constants::STATUS_FIELD, 13))->toBe('Draft')
            ->and(get_field(Constants::COMPLETION_FIELD, 13))->toBe('');
    });

    it('saves anything other than Complete as a draft', function (mixed $asked) {
        ($this->answerPost)(14);

        expect($this->service->save(14, [], $asked)->state)->toBe(AnswerState::Draft);
    })->with(['Draft', 'Cancelled', 'Bogus', null]);
});

describe('completing', function () {
    it('completes a full set, records when, and thanks the group', function () {
        ($this->answerPost)(20);
        $this->titles[20] = 'Answers from Monday Group';
        Functions\when('current_time')->justReturn('2024-01-02 09:00:00');

        $result = $this->service->save(20, ($this->everyAnswer)(), 'Complete');

        expect($result->state)->toBe(AnswerState::Complete)
            ->and($result->message)->toBe('Answers Saved as Complete')
            ->and(get_field(Constants::COMPLETION_FIELD, 20))->toBe('2024-01-02 09:00:00')
            ->and($this->mailer->calls('sendCompletion'))->toBe([['group@example.org', 'Answers from Monday Group']]);
    });

    it('keeps an incomplete set as a draft and says how many remain', function () {
        ($this->answerPost)(21);
        $answers = ($this->everyAnswer)();
        unset($answers['c7_a1']);

        $result = $this->service->save(21, $answers, 'Complete');

        expect($result->state)->toBe(AnswerState::Draft)
            ->and($result->message)->toBe('Answers Saved as Draft: 1 question still needs an answer.')
            ->and($this->mailer->calls('sendCompletion'))->toBe([]);
    });

    it('counts an answer of "0" as answered', function () {
        ($this->answerPost)(24);
        $answers = ($this->everyAnswer)();
        $answers['c3_a1'] = '0';

        expect($this->service->save(24, $answers, 'Complete')->state)->toBe(AnswerState::Complete);
    });

    it('completes when earlier saves supplied the remaining answers', function () {
        $saved = ($this->everyAnswer)();
        unset($saved['c7_a1']);
        ($this->answerPost)(25, $saved);

        expect($this->service->save(25, ['c7_a1' => 'The last one'], 'Complete')->state)->toBe(AnswerState::Complete);
    });

    it('still completes when the thank-you email fails', function () {
        ($this->answerPost)(22);
        $this->mailer->throwOn = 'sendCompletion';

        expect($this->service->save(22, ($this->everyAnswer)(), 'Complete')->state)->toBe(AnswerState::Complete);
    });

    it('copes with a registration that has no email address', function () {
        ($this->answerPost)(23);
        $this->fields[23] = [Constants::EMAIL_FIELD => null];

        $this->service->save(23, ($this->everyAnswer)(), 'Complete');

        expect($this->mailer->calls('sendCompletion')[0][0])->toBe('');
    });
});
