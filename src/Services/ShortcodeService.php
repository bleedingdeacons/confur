<?php

namespace Confur\Services;

use Confur\Shortcodes\StepShortcode;
use Confur\Shortcodes\TraditionShortcode;
use Confur\Shortcodes\AnswerShortcode;
use Confur\Shortcodes\GeneralShortcodes;

/**
 * Registers all plugin shortcodes
 *
 * This class is the one list of the tags Confur owns. Anything else that needs
 * the tag names — the Divi builder guard in Plugin, the docs — reads TAGS
 * rather than keeping its own copy.
 */
class ShortcodeService
{
    /**
     * Tags also shipped by the Amber plugin. Each is registered only if not
     * already present, so whichever plugin loads first wins and the other
     * no-ops. Both plugins ship the same implementation, so the resulting
     * behaviour is the same either way.
     */
    public const SHARED_TAGS = [
        'open_new_link',
        'open_email',
        'pdf_link',
        'days_remaining',
    ];

    /** Tags only Confur registers. */
    public const OWN_TAGS = [
        'step',
        'tradition',
        'answer',
        'committee',
        'start_committee',
        'end_committee',
        'question',
        'header',
        'configure_form',
        'status',
        'progress_table',
        'control',
        'allocated_committee',
    ];

    /** Every tag Confur may register. */
    public const TAGS = [...self::OWN_TAGS, ...self::SHARED_TAGS];

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
        foreach ($this->handlers() as $tag => $handler) {
            if (in_array($tag, self::SHARED_TAGS, true) && shortcode_exists($tag)) {
                continue;
            }

            add_shortcode($tag, $handler);
        }
    }

    /**
     * Tag => handler, in registration order. Keyed by every entry of TAGS.
     *
     * @return array<non-empty-string, callable>
     */
    private function handlers(): array
    {
        return [
            // Steps and Traditions
            'step' => [$this->stepShortcode, 'render'],
            'tradition' => [$this->traditionShortcode, 'render'],

            // General shortcodes (shared with Amber)
            'open_new_link' => [$this->generalShortcodes, 'openBlank'],
            'open_email' => [$this->generalShortcodes, 'linkEmail'],
            'pdf_link' => [$this->generalShortcodes, 'generatePdfLink'],
            'days_remaining' => [$this->generalShortcodes, 'generateDaysRemaining'],

            // Answer shortcodes
            'answer' => [$this->answerShortcode, 'generateAnswerField'],
            'committee' => [$this->answerShortcode, 'generateCommittee'],
            'start_committee' => [$this->answerShortcode, 'generateStartCommittee'],
            'end_committee' => [$this->answerShortcode, 'generateEndCommittee'],
            'question' => [$this->answerShortcode, 'generateQuestion'],
            'header' => [$this->answerShortcode, 'generateHeader'],
            'configure_form' => [$this->answerShortcode, 'configureCustomForm'],
            'status' => [$this->answerShortcode, 'generateStatus'],
            'progress_table' => [$this->answerShortcode, 'generateProgressTable'],
            'control' => [$this->answerShortcode, 'generateControl'],
            'allocated_committee' => [$this->answerShortcode, 'generateAllocatedCommittee'],
        ];
    }
}
