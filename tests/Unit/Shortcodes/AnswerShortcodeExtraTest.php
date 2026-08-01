<?php

namespace Tests\Unit\Shortcodes;

use Confur\Config\Constants;
use Confur\Shortcodes\AnswerShortcode;
use Tests\ConfurTestCase;

/**
 * Covers AnswerShortcode paths the main suite misses: the allocated-committee
 * display and the header's paired-meeting branch.
 *
 * @covers \Confur\Shortcodes\AnswerShortcode
 */
class AnswerShortcodeExtraTest extends ConfurTestCase
{
    private AnswerShortcode $sc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sc = new AnswerShortcode();
    }

    public function testAllocatedCommitteeEmptyWhenNoMeeting(): void
    {
        $this->fields[0] = [Constants::MEETING_FIELD => null];
        $this->assertSame('', $this->sc->generateAllocatedCommittee());
    }

    public function testAllocatedCommitteeEmptyWhenNoAllocation(): void
    {
        $this->fields[0] = [Constants::MEETING_FIELD => 100];
        $this->fields[100] = [Constants::ALLOCATION_FIELD => ''];
        $this->assertSame('', $this->sc->generateAllocatedCommittee());
    }

    public function testAllocatedCommitteeCommitteeSeven(): void
    {
        $this->fields[0] = [Constants::MEETING_FIELD => 100];
        $this->fields[100] = [Constants::ALLOCATION_FIELD => '7'];
        $out = $this->sc->generateAllocatedCommittee();
        $this->assertStringContainsString('Last Question', $out);
    }

    public function testAllocatedCommitteeNumericCommittee(): void
    {
        $this->fields[0] = [Constants::MEETING_FIELD => 100];
        $this->fields[100] = [Constants::ALLOCATION_FIELD => '3'];
        $out = $this->sc->generateAllocatedCommittee();
        $this->assertStringContainsString('Committee 3', $out);
    }

    public function testHeaderIncludesFellowMeeting(): void
    {
        $this->fields[0] = [
            Constants::MEETING_FIELD => 100,
            Constants::FELLOW_MEETING_FIELD => 200,
        ];
        $this->seedTitles([100 => 'Monday Group', 200 => 'Tuesday Group']);

        $out = $this->sc->generateHeader();
        $this->assertStringContainsString('Monday Group and Tuesday Group', $out);
    }
}
