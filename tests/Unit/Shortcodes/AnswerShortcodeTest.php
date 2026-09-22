<?php

namespace Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use Confur\Repositories\AnswerRepository;
use Confur\Shortcodes\AnswerShortcode;
use Mockery;

/*
 * Test class for AnswerShortcode
 *
 * This test works in both environments:
 * - Inside WordPress (Local by Flywheel) - uses WordPress functions
 * - Outside WordPress (standalone) - uses mocked functions from bootstrap
 */

// Track if we're in WordPress environment
function answerShortcodeInWordPress(): bool
{
    return defined('ABSPATH') && function_exists('get_post_meta');
}

beforeAll(function () {
    // Detect WordPress environment
    if (answerShortcodeInWordPress()) {
        echo "Running tests inside WordPress environment\n";
    } else {
        echo "Running tests in standalone mode (mocked WordPress functions)\n";
    }
});

beforeEach(function () {
    // Mock the AnswerRepository
    $this->answerRepositoryMock = Mockery::mock(AnswerRepository::class);

    // Create instance with mocked repository
    $this->shortcode = new AnswerShortcode();

    // Use reflection to inject the mock
    $reflection = new \ReflectionClass($this->shortcode);
    $property = $reflection->getProperty('answerRepository');
    $property->setValue($this->shortcode, $this->answerRepositoryMock);
});

afterEach(function () {
    // Clean up WordPress globals if we modified them
    if (answerShortcodeInWordPress()) {
        global $post;
        $post = null;
    }
});

it('generates an answer field with no existing value', function () {
    $this->answerRepositoryMock
        ->shouldReceive('getValue')
        ->with('c1_a5')
        ->once()
        ->andReturn('');

    $result = $this->shortcode->generateAnswerField([
        'committee' => '1',
        'question' => '5'
    ]);

    expect($result)->toContain(
        '<label class="answer" for="c1_a5">Answer 1.5</label>',
        '<textarea class="answer" name="c1_a5" id="c1_a5"',
        '<textarea class="existing-answer" id="e_c1_a5"',
    );
});

it('generates an answer field with an existing value', function () {
    $existingAnswer = 'This is my existing answer';

    $this->answerRepositoryMock
        ->shouldReceive('getValue')
        ->with('c2_a3')
        ->once()
        ->andReturn($existingAnswer);

    $result = $this->shortcode->generateAnswerField([
        'committee' => '2',
        'question' => '3'
    ]);

    expect($result)->toContain($existingAnswer, 'name="c2_a3"');
});

it('generates a hidden answer field', function () {
    $this->answerRepositoryMock
        ->shouldReceive('getValue')
        ->with('c1_a1')
        ->once()
        ->andReturn('');

    $result = $this->shortcode->generateAnswerField([
        'committee' => '1',
        'question' => '1',
        'hidden' => 'true'
    ]);

    expect($result)->toContain('Answer 1</label>')
        ->not->toContain('Answer 1.1</label>');
});

it('escapes html in answer values', function () {
    // wp-mocks' escaping stubs pass their input through by design, so a
    // test that is genuinely about escaping has to supply the real thing.
    // The answer value lands in a <textarea>, hence esc_textarea.
    $escape = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    Functions\when('esc_html')->alias($escape);
    Functions\when('esc_textarea')->alias($escape);

    $maliciousContent = '<script>alert("xss")</script>';

    $this->answerRepositoryMock
        ->shouldReceive('getValue')
        ->with('c1_a1')
        ->once()
        ->andReturn($maliciousContent);

    $result = $this->shortcode->generateAnswerField([
        'committee' => '1',
        'question' => '1'
    ]);

    expect($result)->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

it('generates a question with committee and number', function () {
    $result = $this->shortcode->generateQuestion(
        ['number' => '3', 'committee' => '2'],
        'What is your question?'
    );

    expect($result)->toContain('<h3 id="c2_q3">Question 2.3</h3>', 'What is your question?');
});

it('generates a hidden question', function () {
    $result = $this->shortcode->generateQuestion(
        ['number' => '5', 'committee' => '1', 'hidden' => 'true'],
        'Hidden question content'
    );

    expect($result)->toContain('Question 5</h3>')
        ->not->toContain('Question 1.5');
});

it('generates a committee with the default name', function () {
    $result = $this->shortcode->generateCommittee(
        ['number' => '3'],
        '<p>Committee content</p>'
    );

    expect($result)->toContain('<div id="g_c3">', '<h2>Committee 3</h2>', '<p>Committee content</p>');
});

it('generates a committee with a custom name', function () {
    $result = $this->shortcode->generateCommittee(
        ['number' => '1', 'name' => 'Finance Committee'],
        '<p>Finance content</p>'
    );

    expect($result)->toContain('<h2>Finance Committee</h2>')
        ->not->toContain('Committee 1');
});

it('generates the start of a committee', function () {
    $result = $this->shortcode->generateStartCommittee(['number' => '4']);

    expect($result)->toEqual('<div id="c4"><h2>Committee 4</h2>');
});

it('generates the end of a committee', function () {
    $result = $this->shortcode->generateEndCommittee();

    expect($result)->toEqual('</div>');
});

it('generates a header with a single meeting', function () {
    // get_the_title mock returns "Post Title {id}" so the header will include that
    $result = $this->shortcode->generateHeader();
    expect($result)->toContain('<h2>', 'Answers from', '</h2>');
});

it('configures a custom form', function () {
    $result = $this->shortcode->configureCustomForm(['action' => 'save_answers']);

    // Check for action hidden field
    expect($result)->toContain('<input type="hidden" name="action" value="save_answers">')
        ->not->toContain('answer_submission_nonce');
});

it('generates the status', function () {
    $result = $this->shortcode->generateStatus(['position' => 'top']);

    expect($result)->toContain('<p class="middle important" id="topDirty">', 'You have made unsaved changes!');
});

it('generates the progress table', function () {
    $result = $this->shortcode->generateProgressTable();

    expect($result)->toContain('<div id="progress">', '<table><tbody>');

    // Check for all 6 committees
    for ($i = 1; $i <= 6; $i++) {
        expect($result)->toContain("href=\"#g_c{$i}\"", "Committee {$i}", "id=\"s_c{$i}\"");
    }

    // Check for "All Committees" row
    expect($result)->toContain('All Committees', 'id="s_c7"')
        ->toContain('Not Started');
});

it('generates a control with a position', function () {
    $result = $this->shortcode->generateControl(['position' => 'bottom']);

    expect($result)->toContain(
        '<span id="bottomSaveState"></span>',
        '<span id="bottomSaveTime"></span>',
        'id="bottomSubmit"',
        'id="bottomFinish"',
        'Save Draft',
        'Save Complete',
        'disabled',
    );
});

it('sanitizes attributes in the answer field', function () {
    $this->answerRepositoryMock
        ->shouldReceive('getValue')
        ->once()
        ->andReturn('');

    $result = $this->shortcode->generateAnswerField([
        'committee' => '1<script>',
        'question' => '2"><script>alert("xss")</script>'
    ]);

    expect($result)->not->toContain('<script>');
});

it('handles empty attributes gracefully', function () {
    $this->answerRepositoryMock
        ->shouldReceive('getValue')
        ->with('c_a')
        ->once()
        ->andReturn('');

    $result = $this->shortcode->generateAnswerField([]);

    expect($result)->toContain('name="c_a"');
});

it('processes shortcodes in question content', function () {
    $content = '[some_shortcode]Content[/some_shortcode]';

    $result = $this->shortcode->generateQuestion(
        ['number' => '1', 'committee' => '1'],
        $content
    );

    // do_shortcode() either processes it (WordPress) or returns as-is (mocked)
    expect($result)->toContain('<h3 id="c1_q1">', 'Content');
});

it('trims whitespace from attributes', function () {
    $result = $this->shortcode->generateQuestion(
        ['number' => '  5  ', 'committee' => '  2  '],
        'Question content'
    );

    expect($result)->toContain('id="c2_q5"')
        ->not->toContain('c  2  _q  5  ');
});
