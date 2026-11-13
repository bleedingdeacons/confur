<?php
/**
 * Questions for Conference → Questions. Rendered by QuestionSetAdminPage::renderAdminPage().
 *
 * @var list<array{type: string, message: string}> $notices Outcome of the last save.
 * @var list<array{committee: string, questions: string, isAll: bool, fields: string}> $rows One per committee, then the spare rows.
 * @var bool $noAllCommittees The saved set has no questions for all committees.
 * @var bool $overriddenByFilter A confur_question_set filter changes what is in force.
 * @var bool $customised A set has been saved (so Reset to Default is offered).
 * @var string $action The admin-post action.
 * @var string $nonceAction
 * @var string $nonceField
 */

?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php foreach ($notices as $notice) : ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?><?php echo $notice['type'] === 'success' ? ' is-dismissible' : ''; ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
    <?php endforeach; ?>

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

    <?php if ($overriddenByFilter) : ?>
        <div class="notice notice-warning inline"><p>
            Code on this site changes the question set through the <code>confur_question_set</code>
            filter, so what is in force differs from what is saved here.
        </p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field($nonceAction, $nonceField); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">

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
                <tr>
                    <td>
                        <input type="number" min="1" step="1" class="small-text"
                               name="committee[<?php echo esc_attr((string) $index); ?>]"
                               aria-label="Committee number"
                               value="<?php echo esc_attr($row['committee']); ?>">
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
                            <?php checked($row['isAll']); ?>>
                    </td>
                    <td>
                        <?php if ($row['fields'] !== '') : ?>
                            <code><?php echo esc_html($row['fields']); ?></code>
                        <?php else : ?>
                            <span class="description">New committee</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="2"><em>No questions for all committees</em></td>
                <td>
                    <input type="radio" name="all_committees" value="none"
                           aria-label="No questions for all committees"
                        <?php checked($noAllCommittees); ?>>
                </td>
                <td></td>
            </tr>
            </tbody>
        </table>
        <p class="description">
            Set a committee's questions to 0 to remove it. Answers already given to removed
            questions are kept and still shown on the Results page; they just stop counting
            towards Complete.
        </p>
        <p class="description">
            The committee chosen under <strong>All Committees</strong> holds the questions every
            committee answers together. It keeps its number for its answer fields, but is named
            "All Committees" everywhere else, and has a section of its own on the Results page.
            A cycle need not have any: choose <em>No questions for all committees</em>.
        </p>

        <p class="submit">
            <?php submit_button('Save Questions', 'primary', 'submit', false); ?>
            <?php if ($customised) : ?>
                <?php submit_button('Reset to Default', 'secondary', 'reset_to_default', false, [
                    'onclick' => "return confirm('Replace the saved questions with the default set?');",
                ]); ?>
            <?php endif; ?>
        </p>
    </form>
</div>
