<?php

namespace Confur\Shortcodes;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;
use DateTime;
use DateTimeImmutable;
use Exception;

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
	 * Generate answer field
	 *
	 * @param array $atts Shortcode attributes
	 * @return string Rendered HTML
	 */
	/**
	 * Generate answer field
	 *
	 * @param array $atts Shortcode attributes
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
	 * @param array $atts Shortcode attributes
	 * @param string|null $content Shortcode content
	 * @return string Rendered HTML
	 */
	public function generateQuestion(array $atts = [], ?string $content = null): string
	{
		$question = trim($atts['number']);
		$committee = trim($atts['committee']);
		$hidden = isset($atts['hidden']) && filter_var($atts['hidden'], FILTER_VALIDATE_BOOLEAN);

		$name = 'c' . $committee . '_q' . $question;
		$content = do_shortcode($content);

		$headerText = $hidden
			? sprintf('Question %s', $question)
			: sprintf('Question %s.%s', $committee, $question);

		return sprintf(
			'<h3 id="%s">%s</h3>%s',
			$name,
			$headerText,
			$content
		);
	}

	/**
	 * Generate committee
	 *
	 * @param array $atts Shortcode attributes
	 * @param string|null $content Shortcode content
	 * @return string Rendered HTML
	 */
	public function generateCommittee(array $atts = [], ?string $content = null): string
	{
		$number = sanitize_text_field(trim($atts['number']));
		$id = 'c' . $number;

		// Check if name attribute exists, otherwise use default "Committee {number}"
		$title = isset($atts['name']) ? sanitize_text_field(trim($atts['name'])) : 'Committee ' . $number;

		$content = do_shortcode($content);

		return sprintf(
			'<div id="g_%s"><h2>%s</h2>%s</div>',
			$id,
			$title,
			$content
		);
	}

	/**
	 * Generate start committee
	 *
	 * @param array $atts Shortcode attributes
	 * @return string Rendered HTML
	 */
	public function generateStartCommittee(array $atts = []): string
	{
		$number = trim($atts['number']);
		$id = 'c' . $number;

		return sprintf(
			'<div id="%s"><h2>Committee %s</h2>',
			$id,
			$number
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
		$meetingId = get_field(Constants::MEETING_FIELD);
		$fellow_meetingId = get_field(Constants::FELLOW_MEETING_FIELD);
		$meetingTitle = get_the_title($meetingId);

		if (! empty($fellow_meetingId ) ) {
			$meetingTitle .= ' and ' . get_the_title($fellow_meetingId);
		}

		return sprintf(
			'<h2>%s Answers</h2>',
			esc_html($meetingTitle)
		);
	}

	/**
	 * Configure custom form
	 *
	 * @param array $atts Shortcode attributes
	 * @return string Rendered HTML
	 */
	public function configureCustomForm(array $atts): string
	{
		error_log('Action: ' . $atts['action']);

		$hidden = sprintf(
			'<input type="hidden" name="post_id" value="%d"/>',
			get_the_ID()
		);

		$action = sprintf(
			'<input type="hidden" name="action" value="%s">',
			esc_attr($atts['action'])
		);

		return $hidden . $action;
	}

	/**
	 * Generate status
	 *
	 * @param array $atts Shortcode attributes
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
		$html = '<div id="progress">';
		$html .= '<table><tbody>';

		for ($count = 1; $count <= 6; $count++) {
			$html .= sprintf(
				'<tr><td><a href="#g_c%1$d" class="status-link"><strong>Committee %1$d</a></strong></td><td class="value" id="s_c%1$d">Not Started</td></tr>',
				$count
			);
		}

		// Work around for extra questions not grouped by 'Committee'
		$count = 7;
		$html .= sprintf(
			'<tr><td><a href="#g_c%1$d" class="status-link"><strong>All Committees</a></strong></td><td class="value" id="s_c%1$d">Not Started</td></tr>',
			 $count);

		$html .= '</tbody></table></div>';

		return $html;
	}

	/**
	 * Generate control
	 *
	 * @param array $atts Shortcode attributes
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
	 * Generate days remaining
	 *
	 * @param array $atts Shortcode attributes
	 * @return string Rendered HTML
	 */
	public function generateDaysRemaining(array $atts): string
	{
		$atts = shortcode_atts([
			'end_date' => '',
			'extend_by' => 0
		], $atts);

		if (empty($atts['end_date'])) {
			return 'Please provide an end date.';
		}

		$timezone = wp_timezone();
		$endDateString = trim($atts['end_date']);
		$endDateObj = false;

		if (strpos($endDateString, ':') !== false) {
			$formats = ['Y-m-d H:i', 'Y-m-d H:i:s'];
			foreach ($formats as $format) {
				$endDateObj = DateTime::createFromFormat($format, $endDateString, $timezone);
				if ($endDateObj !== false) {
					break;
				}
			}
			if ($endDateObj === false) {
				try {
					$endDateObj = new DateTime($endDateString, $timezone);
				} catch (Exception $e) {
					return 'Invalid date format. Use "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" (24-hour format).';
				}
			}
		} else {
			$endDateObj = DateTime::createFromFormat('Y-m-d', $endDateString, $timezone);
			if ($endDateObj === false) {
				try {
					$endDateObj = new DateTime($endDateString, $timezone);
				} catch (Exception $e) {
					return 'Invalid date format. Use "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" (24-hour format).';
				}
			}
		}

		$extendBy = (int) $atts['extend_by'];
		if ($extendBy > 0) {
			$endDateObj->modify("+{$extendBy} days");
		}

		if ($endDateObj->format('H:i:s') === '00:00:00') {
			$formattedDate = $endDateObj->format('d/m/Y');
		} else {
			$formattedDate = $endDateObj->format('d/m/Y H:i');
		}

		$currentTime = new DateTime('now', $timezone);
		$diffSeconds = $endDateObj->getTimestamp() - $currentTime->getTimestamp();

		if ($diffSeconds < 0) {
			return 'The date has already passed.';
		}

		if ($diffSeconds < 24 * 60 * 60) {
			$hoursRemaining = ceil($diffSeconds / 3600);
			$timeRemainingText = "<strong>$hoursRemaining</strong> hours remaining.";
		} else {
			$daysRemaining = ceil($diffSeconds / (60 * 60 * 24));
			$timeRemainingText = "<strong>$daysRemaining</strong> days remaining.";
		}

		$extensionText = ($extendBy > 0)
			? " (extended by $extendBy day" . ($extendBy > 1 ? 's' : '') . ")"
			: '';

		return "<strong>Deadline:</strong> $formattedDate$extensionText - $timeRemainingText";
	}
}