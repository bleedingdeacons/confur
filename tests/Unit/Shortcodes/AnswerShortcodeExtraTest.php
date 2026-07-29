<?php

namespace Tests\Unit\Shortcodes;

use Confur\Config\Constants;
use Confur\Shortcodes\AnswerShortcode;
use PHPUnit\Framework\TestCase;

/**
 * Covers AnswerShortcode paths the main suite misses: the allocated-committee
 * display and the header's paired-meeting branch.
 *
 * @covers \Confur\Shortcodes\AnswerShortcode
 */
class AnswerShortcodeExtraTest extends TestCase
{
    private AnswerShortcode $sc;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['confur_fields'] = [];
        $GLOBALS['confur_titles'] = [];
        $this->sc = new AnswerShortcode();
    }

    public function testAllocatedCommitteeEmptyWhenNoMeeting(): void
    {
        $GLOBALS['confur_fields'][0] = [Constants::MEETING_FIELD => null];
        $this->assertSame('', $this->sc->generateAllocatedCommittee());
    }

    public function testAllocatedCommitteeEmptyWhenNoAllocation(): void
    {
        $GLOBALS['confur_fields'][0] = [Constants::MEETING_FIELD => 100];
        $GLOBALS['confur_fields'][100] = [Constants::ALLOCATION_FIELD => ''];
        $this->assertSame('', $this->sc->generateAllocatedCommittee());
    }

    public function testAllocatedCommitteeCommitteeSeven(): void
    {
        $GLOBALS['confur_fields'][0] = [Constants::MEETING_FIELD => 100];
        $GLOBALS['confur_fields'][100] = [Constants::ALLOCATION_FIELD => '7'];
        $out = $this->sc->generateAllocatedCommittee();
        $this->assertStringContainsString('Last Question', $out);
    }

    public function testAllocatedCommitteeNumericCommittee(): void
    {
        $GLOBALS['confur_fields'][0] = [Constants::MEETING_FIELD => 100];
        $GLOBALS['confur_fields'][100] = [Constants::ALLOCATION_FIELD => '3'];
        $out = $this->sc->generateAllocatedCommittee();
        $this->assertStringContainsString('Committee 3', $out);
    }

    public function testHeaderIncludesFellowMeeting(): void
    {
        $GLOBALS['confur_fields'][0] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
        ];
        $GLOBALS['confur_titles'] = [100 => 'Monday Group', 200 => 'Tuesday Group'];

        $out = $this->sc->generateHeader();
        $this->assertStringContainsString('Monday Group and Tuesday Group', $out);
    }
}
