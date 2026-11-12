<?php

namespace Confur\Shortcodes;

use Confur\Application\RegisterGroup;
use Confur\Config\Constants;
use Confur\Domain\QuestionSet;
use Confur\Repositories\AnswerRepository;
use Confur\Storage\AnswerStore;

/**
 * Answer-related shortcodes
 */
class AnswerShortcode
{
    private AnswerRepository $answerRepository;

    public function __construct()
    {
        $this->answerRepository = new AnswerRepository();
    }

    /**
     * @param string $selector
     * @param int|string|false $post_id
     * @param bool $format_value
     * @return mixed ACF returns whatever the field holds.
     */
    public function getField($selector, $post_id = false, $format_value = true)
    {
        return \get_field($selector, $post_id, $format_value);  // Calls global
    }

    /**
     * @return int|false Post ID, or false outside the loop.
     */
    public function getTheId()
    {
        return \get_the_ID();  // Calls global
    }

    /**
     * Generate answer field
     *
     * @param array<string, mixed> $atts Shortcode attributes
     * @return string Rendered HTML
     */
    public function generateAnswerField(array $atts = []): string
    {
        $committee = isset($atts['committee']) ? sanitize_text_field($atts['committee']) : '';
        $question = isset($atts['question']) ? sanitize_text_field($atts['question']) : '';
        $hidden = isset($atts['hidden']) && filter_var($atts['hidden'], FILTER_VALIDATE_BOOLEAN);

        $name = 'c' . esc_attr($committee) . '_a' . esc_attr($question);

        $existingValue = $this->answerRepository->getValue($name);
        $safeValue = !empty($existingValue) ? esc_textarea($existingValue) : '';

        $labelText = $hidden
            ? sprintf('Answer %s', esc_html($question))
            : sprintf('Answer %s.%s', esc_html($committee), esc_html($question));

        $label = sprintf(
            '<label class="answer" for="%s">%s</label>',
            esc_attr($name),
            $labelText
        );

        $textarea = sprintf(
            '<textarea class="answer" name="%s" id="%s" placeholder="">%s</textarea>',
            esc_attr($name),
            esc_attr($name),
            $safeValue
        );

        $textareaExisting = sprintf(
            '<textarea class="existing-answer" id="e_%s" readonly>%s</textarea>',
            esc_attr($name),
            $safeValue
        );

        return $label . $textarea . $textareaExisting;
    }

    /**
     * Generate question
     *
     * @param array<string, mixed> $atts Shortcode attributes
     * @param string|null $content Shortcode content
     * @return string Rendered HTML
     */
    public function generateQuestion(array $atts = [], ?string $content = null): string
    {
        $question = trim((string) ($atts['number'] ?? ''));
        $committee = trim((string) ($atts['committee'] ?? ''));
        $hidden = isset($atts['hidden']) && filter_var($atts['hidden'], FILTER_VALIDATE_BOOLEAN);

        $name = 'c' . $committee . '_q' . $question;
        // WordPress passes null for a self-closing shortcode, which is how
        // this one is often written.
        $content = do_shortcode($content ?? '');

        $headerText = $hidden
            ? sprintf('Question %s', $question)
            : sprintf('Question %s.%s', $committee, $question);

        return sprintf(
            '<h3 id="%s">%s</h3>%s',
            esc_attr($name),
            esc_html($headerText),
            $content
        );
    }

    /**
     * Generate committee
     *
     * @param array<string, mixed> $atts Shortcode attributes
     * @param string|null $content Shortcode content
     * @return string Rendered HTML
     */
    public function generateCommittee(array $atts = [], ?string $content = null): string
    {
        $number = sanitize_text_field(trim((string) ($atts['number'] ?? '')));
        $id = 'c' . $number;

        // Check if name attribute exists, otherwise use default "Committee {number}"
        $title = isset($atts['name']) ? sanitize_text_field(trim($atts['name'])) : 'Committee ' . $number;

        // WordPress passes null for a self-closing shortcode, which is how
        // this one is often written.
        $content = do_shortcode($content ?? '');

        return sprintf(
            '<div id="g_%s"><h2>%s</h2>%s</div>',
            esc_attr($id),
            esc_html($title),
            $content
        );
    }

    /**
     * Generate start committee
     *
     * @param array<string, mixed> $atts Shortcode attributes
     * @return string Rendered HTML
     */
    public function generateStartCommittee(array $atts = []): string
    {
        $number = trim((string) ($atts['number'] ?? ''));
        $id = 'c' . $number;

        return sprintf(
            '<div id="%s"><h2>Committee %s</h2>',
            esc_attr($id),
            esc_html($number)
        );
    }

    /**
     * Generate end committee
     *
     * @return string Rendered HTML
     */
    public function generateEndCommittee(): string
    {
        return '</div>';
    }

    /**
     * Generate header
     *
     * @return string Rendered HTML
     */
    public function generateHeader(): string
    {
        $groupId = AnswerStore::normalizePostId(get_field(Constants::GROUP_FIELD));
        $fellowGroupId = AnswerStore::normalizePostId(get_field(Constants::FELLOW_GROUP_FIELD));

        return sprintf(
            '<h2>Answers from %s</h2>',
            esc_html($groupId !== null ? RegisterGroup::groupName($groupId, $fellowGroupId) : '')
        );
    }

    /**
     * [configure_form] — retired; renders nothing.
     *
     * It added the hidden "action" field the old admin-post.php submission
     * needed. Answers now save through the REST API, so the field has no
     * use, but existing answer pages still contain the shortcode: it stays
     * registered so they do not show the raw "[configure_form …]" text.
     *
     * @param array<string, mixed>|string $atts Shortcode attributes (unused)
     */
    public function configureCustomForm(array|string $atts = []): string
    {
        return '';
    }

    /**
     * Generate status
     *
     * @param array<string, mixed> $atts Shortcode attributes
     * @return string Rendered HTML
     */
    public function generateStatus(array $atts): string
    {
        return sprintf(
            '<p class="middle important" id="%sDirty">You have made unsaved changes!</p>',
            esc_attr($atts['position'])
        );
    }

    /**
     * Generate progress table
     *
     * @return string Rendered HTML
     */
    public function generateProgressTable(): string
    {
        $questions = QuestionSet::current();

        $html = '<div id="progress">';
        $html .= '<table><tbody>';

        // One row per committee; the client fills in each s_cN cell.
        foreach ($questions->committees() as $committee) {
            $html .= sprintf(
                '<tr><td><a href="#g_c%1$d" class="status-link"><strong>%2$s</a></strong></td><td class="value" id="s_c%1$d">Not Started</td></tr>',
                $committee,
                esc_html($questions->committeeLabel($committee))
            );
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * Generate control
     *
     * @param array<string, mixed> $atts Shortcode attributes
     * @return string Rendered HTML
     */
    public function generateControl(array $atts): string
    {
        $position = esc_attr($atts['position']);

        $html = sprintf('<div><strong>Status: </strong><span id="%sSaveState"></span></div>', $position);
        $html .= sprintf('<div><strong>Last Saved: </strong><span id="%sSaveTime"></span></div>', $position);
        $html .= '<div id="buttons">';
        $html .= sprintf(
            '<button type="button" class="submit" id="%sSubmit" name="submit_answers" value="Draft" disabled>Save Draft</button>',
            $position
        );
        $html .= sprintf(
            '<button type="button" class="submit" id="%sFinish" name="submit_answers" value="Complete" disabled>Save Complete</button>',
            $position
        );
        $html .= '</div>';

        return $html;
    }

    /**
     * Generate allocated committee display
     *
     * Displays "Allocated Committee (Number)" if the registration's group
     * has a populated allocation field. The All Committees committee displays
     * as "Last Question".
     *
     * @param array<string, mixed> $atts Shortcode attributes
     * @return string Rendered HTML or empty string if no allocation
     */
    public function generateAllocatedCommittee(array $atts = []): string
    {
        $groupId = AnswerStore::normalizePostId($this->getField(Constants::GROUP_FIELD));

        if ($groupId === null) {
            return '';
        }

        // The Allocation field group sits on the tsml_group post.
        $allocation = $this->getField(Constants::ALLOCATION_FIELD, $groupId);

        if (empty($allocation)) {
            return '';
        }

        // Sanitize the allocation value
        $committeeNumber = is_numeric($allocation) ? intval($allocation) : sanitize_text_field($allocation);

        // The All Committees committee holds a single closing question, so
        // it is described as "Last Question" rather than by number.
        if (is_int($committeeNumber) && QuestionSet::current()->isAllCommittees($committeeNumber)) {
            return sprintf(
                '<p class="allocated-committee">To start, your group has been allocated: <a href="#g_c%1$d" class="status-link">Last Question</a> (Under All Committee\'s)</p>',
                $committeeNumber
            );
        }

        return sprintf(
            '<p class="allocated-committee">To start, your group has been allocated: <a href="#g_c%1$d" class="status-link">Committee %1$d</a></p>',
            $committeeNumber
        );
    }
}
