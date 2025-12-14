<?php

namespace Confur\Shortcodes;

use Confur\Utils\HtmlHelper;

/**
 * Tradition shortcode handler
 */
class TraditionShortcode
{
	private const TRADITIONS = [
		1 => 'Our common welfare should come first; personal recovery depends upon A.A. unity.',
		2 => 'For our group purpose there is but one ultimate authority—a loving God as He may express Himself in our group conscience. Our leaders are but trusted servants; they do not govern.',
		3 => 'The only requirement for A.A. membership is a desire to stop drinking.',
		4 => 'Each group should be autonomous except in matters affecting other groups or A.A. as a whole.',
		5 => 'Each group has but one primary purpose—to carry its message to the alcoholic who still suffers.',
		6 => 'An A.A. group ought never endorse, finance, or lend the A.A. name to any related facility or outside enterprise, lest problems of money, property, and prestige divert us from our primary purpose.',
		7 => 'Every A.A. group ought to be fully self-supporting, declining outside contributions.',
		8 => 'Alcoholics Anonymous should remain forever nonprofessional, but our service centers may employ special workers.',
		9 => 'A.A., as such, ought never be organized; but we may create service boards or committees directly responsible to those they serve.',
		10 => 'Alcoholics Anonymous has no opinion on outside issues; hence the A.A. name ought never be drawn into public controversy.',
		11 => 'Our public relations policy is based on attraction rather than promotion; we need always maintain personal anonymity at the level of press, radio, and films.',
		12 => 'Anonymity is the spiritual foundation of all our Traditions, ever reminding us to place principles before personalities.'
	];

	/**
	 * Render tradition shortcode
	 *
	 * @param array $atts Shortcode attributes
	 * @param string|null $content Shortcode content
	 * @return string Rendered HTML
	 */
	public function render(array $atts = [], ?string $content = null): string
	{
		$number = isset($atts['number']) ? (int) trim($atts['number']) : 0;

		if (!isset(self::TRADITIONS[$number])) {
			return '';
		}

		$traditionText = self::TRADITIONS[$number];
		$pdfUrl = "https://www.aa.org/sites/default/files/2022-01/en_tradition{$number}.pdf";
		$pdfName = "en_tradition{$number}.pdf";

		$pdfLink = HtmlHelper::generatePdfLink($pdfUrl, $pdfName, '(Long Form)');

		return "Tradition {$number}. \"{$traditionText}\" {$pdfLink}";
	}
}