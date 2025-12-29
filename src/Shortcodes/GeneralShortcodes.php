<?php

namespace Confur\Shortcodes;

use Confur\Utils\HtmlHelper;
use DateTime;
use Exception;

/**
 * General purpose shortcodes
 */
class GeneralShortcodes
{
	/**
	 * Open link in new tab
	 *
	 * @param array $atts Shortcode attributes
	 * @param string|null $content Shortcode content
	 * @return string Rendered HTML
	 */
	public function openBlank(array $atts = [], ?string $content = null): string
	{
		$atts = shortcode_atts([
			'href' => '#',
			'class' => ''
		], $atts);

		return HtmlHelper::createLink(
			esc_attr($atts['href']),
			esc_attr($atts['class']),
			$content
		);
	}

	/**
	 * Create email link
	 *
	 * @param array $atts Shortcode attributes
	 * @param string|null $content Shortcode content
	 * @return string Rendered HTML
	 */
	public function linkEmail(array $atts = [], ?string $content = null): string
	{
		$atts = shortcode_atts([
			'address' => '',
			'subject' => null
		], $atts);

		return HtmlHelper::createEmailAnchor(
			$atts['address'],
			$atts['subject'],
			$content
		);
	}

	/**
	 * Generate PDF link
	 *
	 * @param array $atts Shortcode attributes
	 * @param string|null $content Shortcode content
	 * @return string Rendered HTML
	 */
	public function generatePdfLink(array $atts, ?string $content = null): string
	{
		return HtmlHelper::generatePdfLink(
			$atts['url'],
			$atts['name'],
			$content
		);
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