<?php

namespace Tests\Unit\Shortcodes;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;
use Confur\Shortcodes\AnswerShortcode;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test class for AnswerShortcode
 *
 * This test works in both environments:
 * - Inside WordPress (Local by Flywheel) - uses WordPress functions
 * - Outside WordPress (standalone) - uses mocked functions from bootstrap
 */
class AnswerShortcodeTest extends TestCase
{
	private AnswerShortcode $shortcode;
	private $answerRepositoryMock;

	// Track if we're in WordPress environment
	private static $inWordPress;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// Detect WordPress environment
		self::$inWordPress = defined('ABSPATH') && function_exists('get_post_meta');

		if (self::$inWordPress) {
			echo "Running tests inside WordPress environment\n";
		} else {
			echo "Running tests in standalone mode (mocked WordPress functions)\n";
		}
	}

	protected function setUp(): void
	{
		parent::setUp();

		// Mock the AnswerRepository
		$this->answerRepositoryMock = Mockery::mock(AnswerRepository::class);

		// Create instance with mocked repository
		$this->shortcode = new AnswerShortcode();

		// Use reflection to inject the mock
		$reflection = new \ReflectionClass($this->shortcode);
		$property = $reflection->getProperty('answerRepository');
		$property->setValue($this->shortcode, $this->answerRepositoryMock);
	}

	protected function tearDown(): void
	{
		Mockery::close();

		// Clean up WordPress globals if we modified them
		if (self::$inWordPress) {
			global $post;
			$post = null;
		}

		parent::tearDown();
	}

	/** @test */
	public function it_generates_answer_field_with_no_existing_value()
	{
		$this->answerRepositoryMock
			->shouldReceive('getValue')
			->with('c1_a5')
			->once()
			->andReturn('');

		$result = $this->shortcode->generateAnswerField([
			'committee' => '1',
			'question' => '5'
		]);

		$this->assertStringContainsString('<label class="answer" for="c1_a5">Answer 1.5</label>', $result);
		$this->assertStringContainsString('<textarea class="answer" name="c1_a5" id="c1_a5"', $result);
		$this->assertStringContainsString('<textarea class="existing-answer" id="e_c1_a5"', $result);
	}

	/** @test */
	public function it_generates_answer_field_with_existing_value()
	{
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

		$this->assertStringContainsString($existingAnswer, $result);
		$this->assertStringContainsString('name="c2_a3"', $result);
	}

	/** @test */
	public function it_generates_hidden_answer_field()
	{
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

		$this->assertStringContainsString('Answer 1</label>', $result);
		$this->assertStringNotContainsString('Answer 1.1</label>', $result);
	}

	/** @test */
	public function it_escapes_html_in_answer_values()
	{
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

		$this->assertStringNotContainsString('<script>', $result);
		$this->assertStringContainsString('&lt;script&gt;', $result);
	}

	/** @test */
	public function it_generates_question_with_committee_and_number()
	{
		$result = $this->shortcode->generateQuestion(
			['number' => '3', 'committee' => '2'],
			'What is your question?'
		);

		$this->assertStringContainsString('<h3 id="c2_q3">Question 2.3</h3>', $result);
		$this->assertStringContainsString('What is your question?', $result);
	}

	/** @test */
	public function it_generates_hidden_question()
	{
		$result = $this->shortcode->generateQuestion(
			['number' => '5', 'committee' => '1', 'hidden' => 'true'],
			'Hidden question content'
		);

		$this->assertStringContainsString('Question 5</h3>', $result);
		$this->assertStringNotContainsString('Question 1.5', $result);
	}

	/** @test */
	public function it_generates_committee_with_default_name()
	{
		$result = $this->shortcode->generateCommittee(
			['number' => '3'],
			'<p>Committee content</p>'
		);

		$this->assertStringContainsString('<div id="g_c3">', $result);
		$this->assertStringContainsString('<h2>Committee 3</h2>', $result);
		$this->assertStringContainsString('<p>Committee content</p>', $result);
	}

	/** @test */
	public function it_generates_committee_with_custom_name()
	{
		$result = $this->shortcode->generateCommittee(
			['number' => '1', 'name' => 'Finance Committee'],
			'<p>Finance content</p>'
		);

		$this->assertStringContainsString('<h2>Finance Committee</h2>', $result);
		$this->assertStringNotContainsString('Committee 1', $result);
	}

	/** @test */
	public function it_generates_start_committee()
	{
		$result = $this->shortcode->generateStartCommittee(['number' => '4']);

		$this->assertEquals('<div id="c4"><h2>Committee 4</h2>', $result);
	}

	/** @test */
	public function it_generates_end_committee()
	{
		$result = $this->shortcode->generateEndCommittee();

		$this->assertEquals('</div>', $result);
	}

	/** @test */
	public function it_generates_header_with_single_meeting()
	{
		// get_the_title mock returns "Post Title {id}" so the header will include that
		$result = $this->shortcode->generateHeader();
		$this->assertStringContainsString('<h2>', $result);
		$this->assertStringContainsString('Answers from', $result);
		$this->assertStringContainsString('</h2>', $result);
	}

	/** @test */
	public function it_configures_custom_form()
	{
		$result = $this->shortcode->configureCustomForm(['action' => 'save_answers']);

		// Check for action hidden field
		$this->assertStringContainsString('<input type="hidden" name="action" value="save_answers">', $result);
		$this->assertStringNotContainsString('answer_submission_nonce', $result);
	}

	/** @test */
	public function it_generates_status()
	{
		$result = $this->shortcode->generateStatus(['position' => 'top']);

		$this->assertStringContainsString('<p class="middle important" id="topDirty">', $result);
		$this->assertStringContainsString('You have made unsaved changes!', $result);
	}

	/** @test */
	public function it_generates_progress_table()
	{
		$result = $this->shortcode->generateProgressTable();

		$this->assertStringContainsString('<div id="progress">', $result);
		$this->assertStringContainsString('<table><tbody>', $result);

		// Check for all 6 committees
		for ($i = 1; $i <= 6; $i++) {
			$this->assertStringContainsString("href=\"#g_c{$i}\"", $result);
			$this->assertStringContainsString("Committee {$i}", $result);
			$this->assertStringContainsString("id=\"s_c{$i}\"", $result);
		}

		// Check for "All Committees" row
		$this->assertStringContainsString('All Committees', $result);
		$this->assertStringContainsString('id="s_c7"', $result);

		$this->assertStringContainsString('Not Started', $result);
	}

	/** @test */
	public function it_generates_control_with_position()
	{
		$result = $this->shortcode->generateControl(['position' => 'bottom']);

		$this->assertStringContainsString('<span id="bottomSaveState"></span>', $result);
		$this->assertStringContainsString('<span id="bottomSaveTime"></span>', $result);
		$this->assertStringContainsString('id="bottomSubmit"', $result);
		$this->assertStringContainsString('id="bottomFinish"', $result);
		$this->assertStringContainsString('Save Draft', $result);
		$this->assertStringContainsString('Save Complete', $result);
		$this->assertStringContainsString('disabled', $result);
	}

	/** @test */
	public function it_sanitizes_attributes_in_answer_field()
	{
		$this->answerRepositoryMock
			->shouldReceive('getValue')
			->once()
			->andReturn('');

		$result = $this->shortcode->generateAnswerField([
			'committee' => '1<script>',
			'question' => '2"><script>alert("xss")</script>'
		]);

		$this->assertStringNotContainsString('<script>', $result);
	}

	/** @test */
	public function it_handles_empty_attributes_gracefully()
	{
		$this->answerRepositoryMock
			->shouldReceive('getValue')
			->with('c_a')
			->once()
			->andReturn('');

		$result = $this->shortcode->generateAnswerField([]);

		$this->assertStringContainsString('name="c_a"', $result);
	}

	/** @test */
	public function it_processes_shortcodes_in_question_content()
	{
		$content = '[some_shortcode]Content[/some_shortcode]';

		$result = $this->shortcode->generateQuestion(
			['number' => '1', 'committee' => '1'],
			$content
		);

		// do_shortcode() either processes it (WordPress) or returns as-is (mocked)
		$this->assertStringContainsString('<h3 id="c1_q1">', $result);
		$this->assertStringContainsString('Content', $result);
	}

	/** @test */
	public function it_trims_whitespace_from_attributes()
	{
		$result = $this->shortcode->generateQuestion(
			['number' => '  5  ', 'committee' => '  2  '],
			'Question content'
		);

		$this->assertStringContainsString('id="c2_q5"', $result);
		$this->assertStringNotContainsString('c  2  _q  5  ', $result);
	}
}