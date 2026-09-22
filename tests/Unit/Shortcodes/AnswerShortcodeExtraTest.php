<?php

namespace Tests\Unit\Shortcodes;

use Confur\Config\Constants;
use Confur\Shortcodes\AnswerShortcode;

/*
 * Covers AnswerShortcode paths the main suite misses: the allocated-committee
 * display and the header's paired-meeting branch.
 */

covers(AnswerShortcode::class);

beforeEach(function () {
    $this->sc = new AnswerShortcode();
});

it('renders no allocated committee when there is no meeting', function () {
    $this->fields[0] = [Constants::MEETING_FIELD => null];
    expect($this->sc->generateAllocatedCommittee())->toBe('');
});

it('renders no allocated committee when there is no allocation', function () {
    $this->fields[0] = [Constants::MEETING_FIELD => 100];
    $this->fields[100] = [Constants::ALLOCATION_FIELD => ''];
    expect($this->sc->generateAllocatedCommittee())->toBe('');
});

it('renders committee seven as the Last Question', function () {
    $this->fields[0] = [Constants::MEETING_FIELD => 100];
    $this->fields[100] = [Constants::ALLOCATION_FIELD => '7'];
    $out = $this->sc->generateAllocatedCommittee();
    expect($out)->toContain('Last Question');
});

it('renders a numeric allocated committee', function () {
    $this->fields[0] = [Constants::MEETING_FIELD => 100];
    $this->fields[100] = [Constants::ALLOCATION_FIELD => '3'];
    $out = $this->sc->generateAllocatedCommittee();
    expect($out)->toContain('Committee 3');
});

it('includes the fellow meeting in the header', function () {
    $this->fields[0] = [
        Constants::MEETING_FIELD => 100,
        Constants::FELLOW_MEETING_FIELD => 200,
    ];
    $this->seedTitles([100 => 'Monday Group', 200 => 'Tuesday Group']);

    $out = $this->sc->generateHeader();
    expect($out)->toContain('Monday Group and Tuesday Group');
});
