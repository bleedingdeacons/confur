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
	public function generateAnswerField(array $atts = []): string
	{
		$committee = isset($atts['committee']) ? sanitize_text_field($atts['committee']) : '';
		$question = isset($atts['question']) ? sanitize_text_field($atts['question']) : '';

		$name = 'c' . esc_attr($committee) . '_a' . esc_attr($question);

		$existingValue = $this->answerRepository->getValue($name);
		$safeValue = !empty($existingValue) ? esc_textarea($existingValue) : '';

		$label = sprintf(
			'<label class="answer" for="%s">Answer %s.%s</label>',
			esc_attr($name),
			esc_html($committee),
			esc_html($question)
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

		$name = 'c' . $committee . '_q' . $question;
		$content = do_shortcode($content);

		return sprintf(
			'<h3 id="%s">Question %s.%s</h3>%s',
			$name,
			$committee,
			$question,
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
		$number = trim($atts['number']);
		$id = 'c' . $number;

		$content = do_shortcode($content);

		return sprintf(
			'<div id="g_%s"><h2>Committee %s</h2>%s</div>',
			$id,
			$number,
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
		$meetingId = get_field('meeting');
		$meetingTitle = get_the_title($meetingId);

		return sprintf(
			'<h2>Results from the %s Group</h2>',
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
		$html = '<div id="progress"><h3>Progress</h3>';
		$html .= '<table><tbody>';

		for ($count = 1; $count <= 6; $count++) {
			$html .= sprintf(
				'<tr><td><a href="#g_c%1$d" class="status-link"><strong>Committee No. %1$d</a></strong></td><td id="s_c%1$d">Not Started</td></tr>',
				$count
			);
		}

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

		$extendBy = intval($atts['extend_by']);
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