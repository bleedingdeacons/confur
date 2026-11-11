<?php

namespace Confur\Storage;

use Confur\Config\Constants;
use Confur\Domain\Answer;
use Confur\Domain\AnswerKey;

/**
 * Reads and writes `answer` posts through ACF.
 *
 * The only class that knows how a registration is laid out in post meta. It
 * hands back Answer objects with meeting ids already normalised, so the
 * "ACF may return an id, an object or an array" handling lives here once
 * instead of being copied into each caller.
 */
class AnswerStore
{
    /** Every status a live registration can have — i.e. not the trash. */
    private const LIVE_STATUSES = ['publish', 'draft', 'pending', 'private'];

    /**
     * Ids of every live answer post.
     *
     * @param int|null $excluding A post to leave out (e.g. one being created).
     * @return list<int>
     */
    public function ids(?int $excluding = null): array
    {
        $args = [
            'post_type' => Constants::ANSWER_CUSTOM_TYPE,
            'posts_per_page' => -1,
            'post_status' => self::LIVE_STATUSES,
            'fields' => 'ids',
        ];

        if ($excluding !== null) {
            $args['post__not_in'] = [$excluding];
        }

        $ids = array_values(array_filter(array_map('intval', get_posts($args))));

        // 'fields' => 'ids' skips WordPress's meta priming, which would leave
        // every get_field() below as its own query. Prime it in one.
        if ($ids !== [] && function_exists('update_postmeta_cache')) {
            update_postmeta_cache($ids);
        }

        return $ids;
    }

    /**
     * Every live registration.
     *
     * @param bool $withAnswers Also load the answer text (one more read per post).
     * @param int|null $excluding A post to leave out.
     * @return list<Answer>
     */
    public function all(bool $withAnswers = false, ?int $excluding = null): array
    {
        $answers = [];

        foreach ($this->ids($excluding) as $postId) {
            // The query excludes the trash, but a post can be trashed between
            // the query and this read.
            if (get_post_status($postId) === 'trash') {
                continue;
            }

            $answers[] = $this->load($postId, $withAnswers);
        }

        return $answers;
    }

    /** One registration by post id. The caller is responsible for the post being an answer. */
    public function load(int $postId, bool $withAnswers = false): Answer
    {
        return new Answer(
            postId: $postId,
            meetingId: self::normalizePostId(get_field(Constants::MEETING_FIELD, $postId)),
            fellowMeetingId: self::normalizePostId(get_field(Constants::FELLOW_MEETING_FIELD, $postId)),
            email: self::stringOrNull(get_field(Constants::EMAIL_FIELD, $postId)),
            updated: self::stringOrNull(get_field(Constants::UPDATED_FIELD, $postId)),
            status: self::stringOrNull(get_field(Constants::STATUS_FIELD, $postId)),
            answers: $withAnswers ? $this->answerFields($postId) : [],
        );
    }

    /**
     * One field, sanitised for display in a textarea.
     *
     * @param int|false $postId False means ACF's current post, which only
     *                          exists inside the loop.
     */
    public function value(string $fieldName, int|false $postId = false): string
    {
        return sanitize_textarea_field((string) get_field($fieldName, $postId));
    }

    public function saveAnswer(int $postId, AnswerKey $key, string $text): bool
    {
        return (bool) update_field($key->fieldName(), $text, $postId);
    }

    /**
     * ACF returns a relationship as an id, a WP_Post or an array depending on
     * the field's return format. Reduce any of those to the id.
     */
    public static function normalizePostId(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_object($value) && isset($value->ID)) {
            return (int) $value->ID;
        }

        if (is_array($value) && isset($value['ID'])) {
            return (int) $value['ID'];
        }

        return null;
    }

    /**
     * Every answer field on the post that has text in it.
     *
     * @return array<string, string>
     */
    private function answerFields(int $postId): array
    {
        $fields = get_fields($postId);

        if (!is_array($fields)) {
            return [];
        }

        $answers = [];

        foreach ($fields as $name => $value) {
            if (AnswerKey::parse((string) $name) === null || empty($value) || !is_scalar($value)) {
                continue;
            }

            $answers[(string) $name] = (string) $value;
        }

        return $answers;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === false ? null : (is_scalar($value) ? (string) $value : null);
    }
}
