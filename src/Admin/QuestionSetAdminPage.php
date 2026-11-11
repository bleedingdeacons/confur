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
     * removed; the spare blank rows are how one is added.
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
            $rows[] = ['committee' => (string) $committee, 'questions' => (string) $saved->questionCount($committee)];
        }

        for ($i = 0; $i < self::SPARE_ROWS; $i++) {
            $rows[] = ['committee' => '', 'questions' => ''];
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php $this->renderNotices(); ?>

            <p>
                Each committee has a number of questions, answered in fields named
                <code>c&lt;committee&gt;_a&lt;question&gt;</code>. A group can mark its answers
                <strong>Complete</strong> only when every question here has an answer.
            </p>
            <p>
                Answer pages are built from shortcodes, so they have to match: each question needs
                <code>[answer committee="N" question="M"]</code> on the page.
                Questions beyond the bundled set are saved and reported, but have no field on the
                answer post's own edit screen unless one is added to the ACF field group.
            </p>

            <?php if (!$questions->equals($saved)) : ?>
                <div class="notice notice-warning inline"><p>
                    Code on this site changes the question set through the <code>confur_question_set</code>
                    filter, so what is in force differs from what is saved here.
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">

                <table class="widefat striped" style="max-width: 640px">
                    <thead>
                    <tr>
                        <th scope="col">Committee</th>
                        <th scope="col">Questions</th>
                        <th scope="col">All Committees</th>
                        <th scope="col">Answer fields</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $index => $row) : ?>
                        <?php
                        $number = $row['committee'];
                        $isAll = $number !== '' && $saved->isAllCommittees((int) $number);
                        ?>
                        <tr>
                            <td>
                                <input type="number" min="1" step="1" class="small-text"
                                       name="committee[<?php echo esc_attr((string) $index); ?>]"
                                       aria-label="Committee number"
                                       value="<?php echo esc_attr($number); ?>">
                            </td>
                            <td>
                                <input type="number" min="0" step="1" class="small-text"
                                       name="questions[<?php echo esc_attr((string) $index); ?>]"
                                       aria-label="Number of questions"
                                       value="<?php echo esc_attr($row['questions']); ?>">
                            </td>
                            <td>
                                <input type="radio" name="all_committees"
                                       aria-label="Show as All Committees"
                                       value="<?php echo esc_attr((string) $index); ?>"
                                    <?php checked($isAll); ?>>
                            </td>
                            <td>
                                <?php if ($number !== '') : ?>
                                    <code><?php echo esc_html(self::fieldRange((int) $number, (int) $row['questions'])); ?></code>
                                <?php else : ?>
                                    <span class="description">New committee</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">
                    Set a committee's questions to 0 to remove it. Answers already given to removed
                    questions are kept and still shown on the Results page; they just stop counting
                    towards Complete. If no committee is chosen as All Committees, the highest-numbered one is.
                </p>

                <p class="submit">
                    <?php submit_button('Save Questions', 'primary', 'submit', false); ?>
                    <?php if (QuestionSetStore::isCustomised()) : ?>
                        <?php submit_button('Reset to Default', 'secondary', 'reset_to_default', false, [
                            'onclick' => "return confirm('Replace the saved questions with the default set?');",
                        ]); ?>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
    }

    private function renderNotices(): void
    {
        $updated = isset($_GET['updated']) ? sanitize_text_field(wp_unslash($_GET['updated'])) : '';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';

        if ($updated === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Questions saved.</p></div>';
        } elseif ($updated === 'reset') {
            echo '<div class="notice notice-success is-dismissible"><p>Questions reset to the default set.</p></div>';
        }

        if ($error === 'invalid') {
            $reason = get_transient(self::errorTransient());
            delete_transient(self::errorTransient());
            $reason = is_string($reason) && $reason !== '' ? $reason : 'the questions are not valid.';
            echo '<div class="notice notice-error"><p>Not saved: ' . esc_html($reason) . '</p></div>';
        } elseif ($error === 'save') {
            echo '<div class="notice notice-error"><p>The questions could not be saved. Please try again.</p></div>';
        }
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
