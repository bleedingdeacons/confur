<?php

namespace Confur\Services;

use Confur\Shortcodes\StepShortcode;
use Confur\Shortcodes\TraditionShortcode;
use Confur\Shortcodes\AnswerShortcode;
use Confur\Shortcodes\GeneralShortcodes;

/**
 * Registers all plugin shortcodes
 */
class ShortcodeService
{
	private StepShortcode $stepShortcode;
	private TraditionShortcode $traditionShortcode;
	private AnswerShortcode $answerShortcode;
	private GeneralShortcodes $generalShortcodes;

	public function __construct()
	{
		$this->stepShortcode = new StepShortcode();
		$this->traditionShortcode = new TraditionShortcode();
		$this->answerShortcode = new AnswerShortcode();
		$this->generalShortcodes = new GeneralShortcodes();
	}

	/**
	 * Register all shortcodes
	 */
	public function registerShortcodes(): void
	{
		// Steps and Traditions
		add_shortcode('step', [$this->stepShortcode, 'render']);
		add_shortcode('tradition', [$this->traditionShortcode, 'render']);

		// General shortcodes
		add_shortcode('open_new_link', [$this->generalShortcodes, 'openBlank']);
		add_shortcode('open_email', [$this->generalShortcodes, 'linkEmail']);
		add_shortcode('pdf_link', [$this->generalShortcodes, 'generatePdfLink']);
		add_shortcode('days_remaining', [$this->generalShortcodes, 'generateDaysRemaining']);

		// Answer shortcodes
		add_shortcode('answer', [$this->answerShortcode, 'generateAnswerField']);
		add_shortcode('committee', [$this->answerShortcode, 'generateCommittee']);
		add_shortcode('start_committee', [$this->answerShortcode, 'generateStartCommittee']);
		add_shortcode('end_committee', [$this->answerShortcode, 'generateEndCommittee']);
		add_shortcode('question', [$this->answerShortcode, 'generateQuestion']);
		add_shortcode('header', [$this->answerShortcode, 'generateHeader']);
		add_shortcode('configure_form', [$this->answerShortcode, 'configureCustomForm']);
		add_shortcode('status', [$this->answerShortcode, 'generateStatus']);
		add_shortcode('progress_table', [$this->answerShortcode, 'generateProgressTable']);
		add_shortcode('control', [$this->answerShortcode, 'generateControl']);


	}
}