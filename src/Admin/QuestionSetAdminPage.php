<?php

namespace Confur\Admin;

use Confur\Domain\QuestionSet;
use Confur\Storage\QuestionSetStore;

/**
 * Questions for Conference → Questions: which committees there are, how many
 * questions each has, and which one is "All Committees".
 *
 * Changing the set changes what "Complete" requires, the progress table and
 * the Results page. The answer pages themselves are ordinary pages built from
 * [answer] shortcodes, so they have to be edited to match — the screen says so.
 */
class QuestionSetAdminPage
{
    public const PAGE_SLUG = 'confur-questions';
    private const CAPABILITY = 'manage_options';
    private const ACTION = 'confur_update_questions';
    private const NONCE_ACTION = 'confur_questions_action';
    private const NONCE_FIELD = 'confur_questions_nonce';

    /** Blank rows offered for adding committees. */
    private const SPARE_ROWS = 2;

    public function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_post_' . self::ACTION, [$this, 'handleFormSubmission']);
    }

    public function addAdminMenu(): void
    {
        add_submenu_page(
            'confur',
            'Conference Questions',
            'Questions',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'renderAdminPage']
        );
    }

    public function handleFormSubmission(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)
        ) {
            wp_die(__('Security check failed.'));
        }

        wp_redirect($this->resolveSubmissionRedirect($_POST));
        exit;
    }

    /**
     * Apply the submitted form and say where to go next. Split from
     * handleFormSubmission() so tests can drive it without the exit.
     *
     * @param array<string, mixed> $post The submitted form.
     */
    private function resolveSubmissionRedirect(array $post): string
    {
        if (isset($post['reset_to_default'])) {
            return $this->pageUrl(QuestionSetStore::reset() ? ['updated' => 'reset'] : ['error' => 'save']);
        }

        try {
            $questions = self::parseForm($post);
        } catch (\InvalidArgumentException $e) {
            // The reason is kept server-side rather than put in the URL, so a
            // crafted link cannot put words in an admin notice.
            set_transient(self::errorTransient(), $e->getMessage(), 5 * MINUTE_IN_SECONDS);
            return $this->pageUrl(['error' => 'invalid']);
        }

        return $this->pageUrl(QuestionSetStore::save($questions) ? ['updated' => '1'] : ['error' => 'save']);
    }

    /**
     * Build a QuestionSet from the form's rows. A row with no committee
     * number or a question count of 0 is dropped, which is how a committee is
     * removed; the spare blank rows are how one is added. The set has no
     * questions for all committees when "none" is chosen, or when the chosen
     * row is one that was dropped.
     *
     * @param array<string, mixed> $post
     * @throws \InvalidArgumentException When the rows do not make a valid set.
     */
    public static function parseForm(array $post): QuestionSet
    {
        $committees = is_array($post['committee'] ?? null) ? $post['committee'] : [];
        $counts = is_array($post['questions'] ?? null) ? $post['questions'] : [];
        $allRow = isset($post['all_committees']) ? (string) $post['all_committees'] : '';

        $questionCounts = [];
        $allCommittees = null;

        foreach ($committees as $row => $committee) {
            $committee = trim((string) $committee);
            $count = trim((string) ($counts[$row] ?? ''));

            if ($committee === '' || $count === '' || $count === '0') {
                continue;
            }

            if (!ctype_digit($committee) || !ctype_digit($count)) {
                throw new \InvalidArgumentException('Committee numbers and question counts must be whole numbers.');
            }

            $number = (int) $committee;

            if (isset($questionCounts[$number])) {
                throw new \InvalidArgumentException("Committee $number is listed twice.");
            }

            $questionCounts[$number] = (int) $count;

            if ((string) $row === $allRow) {
                $allCommittees = $number;
            }
        }

        return new QuestionSet($questionCounts, $allCommittees);
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $questions = QuestionSet::current();
        $saved = QuestionSetStore::load();
        $rows = [];

        foreach ($saved->committees() as $committee) {
            $count = $saved->questionCount($committee);
            $rows[] = [
                'committee' => (string) $committee,
                'questions' => (string) $count,
                'isAll' => $saved->isAllCommittees($committee),
                'fields' => self::fieldRange($committee, $count),
            ];
        }

        for ($i = 0; $i < self::SPARE_ROWS; $i++) {
            $rows[] = ['committee' => '', 'questions' => '', 'isAll' => false, 'fields' => ''];
        }

        Template::render('admin/questions', [
            'notices' => $this->notices(),
            'rows' => $rows,
            'noAllCommittees' => !$saved->hasAllCommittees(),
            'overriddenByFilter' => !$questions->equals($saved),
            'customised' => QuestionSetStore::isCustomised(),
            'action' => self::ACTION,
            'nonceAction' => self::NONCE_ACTION,
            'nonceField' => self::NONCE_FIELD,
        ]);
    }

    /**
     * What to tell the administrator about the last save.
     *
     * @return list<array{type: string, message: string}>
     */
    private function notices(): array
    {
        $updated = isset($_GET['updated']) ? sanitize_text_field(wp_unslash($_GET['updated'])) : '';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        $notices = [];

        if ($updated === '1') {
            $notices[] = ['type' => 'success', 'message' => 'Questions saved.'];
        } elseif ($updated === 'reset') {
            $notices[] = ['type' => 'success', 'message' => 'Questions reset to the default set.'];
        }

        if ($error === 'invalid') {
            $reason = get_transient(self::errorTransient());
            delete_transient(self::errorTransient());
            $reason = is_string($reason) && $reason !== '' ? $reason : 'the questions are not valid.';
            $notices[] = ['type' => 'error', 'message' => 'Not saved: ' . $reason];
        } elseif ($error === 'save') {
            $notices[] = ['type' => 'error', 'message' => 'The questions could not be saved. Please try again.'];
        }

        return $notices;
    }

    /** "c1_a1 – c1_a3", or the single field name for one question. */
    private static function fieldRange(int $committee, int $count): string
    {
        $first = 'c' . $committee . '_a1';

        return $count <= 1 ? $first : $first . ' – c' . $committee . '_a' . $count;
    }

    private static function errorTransient(): string
    {
        return 'confur_questions_error_' . get_current_user_id();
    }

    /** @param array<string, string> $args */
    private function pageUrl(array $args): string
    {
        return add_query_arg(['page' => self::PAGE_SLUG] + $args, admin_url('admin.php'));
    }
}
