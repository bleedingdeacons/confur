<?php

namespace Confur\Shortcodes;

use Confur\Utils\HtmlHelper;

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
}