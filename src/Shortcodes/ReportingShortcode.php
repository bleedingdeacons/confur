<?php

namespace Confur\Shortcodes;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;
use Confur\Repositories\MeetingRepository;
use Confur\Utils\HtmlHelper;

/**
 * Reporting shortcode handler
 */
class ReportingShortcode
{
	private AnswerRepository $answerRepository;
	private MeetingRepository $meetingRepository;

	public function __construct()
	{
		$this->answerRepository = new AnswerRepository();
		$this->meetingRepository = new MeetingRepository();
	}

	/**
	 * Render report
	 *
	 * @return string Rendered HTML
	 */
	public function render(): string
	{
		$meetings = $this->meetingRepository->getMeetings();
		$registered = $this->answerRepository->getRegisteredGroups();
		$answers = $this->answerRepository->getGroupAnswers();

		$allAnswerTable = $this->generateAnswerTable($answers);
		$unregisteredTable = $this->generateUnregisteredTable($meetings, $answers, $registered);
		$registeredTable = $this->generateRegisteredTable($registered, $meetings);
		$coverage = $this->generateCoverageTable($answers);
		$linksTable = $this->generateLinksTable();

		return sprintf(
			'<h1>Reporting</h1>%s<h2 id="answer_table">Answers</h2>%s<h2 id="coverage">Coverage</h2>%s<h2 id="registration">Registration</h2><h3 id="registered">Registered</h3>%s<h3 id="unregistered">Unregistered</h3>%s',
			$linksTable,
			$allAnswerTable,
			$coverage,
			$registeredTable,
			$unregisteredTable
		);
	}

	/**
	 * Generate coverage table
	 *
	 * @param array $data Answer data
	 * @return string Rendered HTML
	 */
	private function generateCoverageTable(array $data): string
	{
		$results = [];

		foreach ($data as $committeeAnswer => $answers) {
			[$committee, $answer] = explode("_", $committeeAnswer);
			$committee = ltrim($committee, "c");
			$answer = ltrim($answer, "a");

			$wordCounts = array_map(function($response) {
				return str_word_count($response[5]);
			}, $answers);

			$responseCount = count($answers);
			$totalWords = array_sum($wordCounts);
			$avgWordCount = $responseCount > 0 ? $totalWords / $responseCount : 0;
			$minWordCount = $responseCount > 0 ? min($wordCounts) : 0;
			$maxWordCount = $responseCount > 0 ? max($wordCounts) : 0;

			$results[] = [
				"committee" => $committee,
				"answer" => $answer,
				"response_count" => $responseCount,
				"average_word_count" => round($avgWordCount, 2),
				"lowest_word_count" => $minWordCount,
				"highest_word_count" => $maxWordCount
			];
		}

		usort($results, function ($a, $b) {
			return $a['committee'] <=> $b['committee'] ?: $a['answer'] <=> $b['answer'];
		});

		$html = "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
		$html .= "<tr><th>Committee</th><th>Answer</th><th>Response Count</th><th>Average Word Count</th><th>Lowest Word Count</th><th>Highest Word Count</th></tr>";

		foreach ($results as $result) {
			$link = $this->createAnswerLink(
				$result['committee'],
				$result['answer'],
				'Answer ' . $result['answer']
			);

			$html .= sprintf(
				"<tr><td>Committee %s</td><td>%s</td><td>%d</td><td>%s</td><td>%d</td><td>%d</td></tr>",
				$result['committee'],
				$link,
				$result['response_count'],
				$result['average_word_count'],
				$result['lowest_word_count'],
				$result['highest_word_count']
			);
		}

		$html .= "</table>";

		return $html;
	}

	/**
	 * Generate links table
	 *
	 * @return string Rendered HTML
	 */
	private function generateLinksTable(): string
	{
		$html = '<table id="answer_links"><tbody>';

		$group = '<strong>Navigation</strong><ul><li><a href="#registered">Registered</a></li><li><a href="#unregistered">Unregistered</a></li><li><a href="#coverage">Coverage</a></li></ul>';

		$committee1 = $this->createAnswerLinks(1, 4);
		$committee2 = $this->createAnswerLinks(2, 4);
		$committee3 = $this->createAnswerLinks(3, 4);
		$committee4 = $this->createAnswerLinks(4, 4);
		$committee5 = $this->createAnswerLinks(5, 3);
		$committee6 = $this->createAnswerLinks(6, 2);

		$class = 'class="answerLinks"';

		$html .= sprintf(
			"<tr><td %s>%s</td><td %s>%s</td><td %s>%s</td><td %s>%s</td><td %s>%s</td><td %s>%s</td><td %s>%s</td></tr>",
			$class, $group,
			$class, $committee1,
			$class, $committee2,
			$class, $committee3,
			$class, $committee4,
			$class, $committee5,
			$class, $committee6
		);

		$html .= "</tbody></table>";

		return $html;
	}

	/**
	 * Create answer links for a committee
	 *
	 * @param int $committeeNumber Committee number
	 * @param int $answerCount Number of answers
	 * @return string Rendered HTML
	 */
	private function createAnswerLinks(int $committeeNumber, int $answerCount): string
	{
		$html = "<strong>Committee {$committeeNumber}</strong><ul>";

		for ($count = 1; $count <= $answerCount; $count++) {
			$link = $this->createAnswerLink($committeeNumber, $count, 'Answer ' . $count);
			$html .= "<li>{$link}</li>";
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * Create answer link
	 *
	 * @param int $committeeNumber Committee number
	 * @param int $answerNumber Answer number
	 * @param string $content Link content
	 * @return string Rendered HTML
	 */
	private function createAnswerLink(int $committeeNumber, int $answerNumber, string $content): string
	{
		return sprintf(
			'<a href="#c%d_a%d">%s</a>',
			$committeeNumber,
			$answerNumber,
			$content
		);
	}

	/**
	 * Generate registered table
	 *
	 * @param array $registered Registered groups
	 * @param array $meetings All meetings
	 * @return string Rendered HTML
	 */
	private function generateRegisteredTable(array $registered, array $meetings): string
	{
		$totalMeetings = count($meetings);
		$registeredCount = $this->getUniqueCount($registered, 'meeting');
		$percentageRegistered = $this->calculatePercentage($registeredCount, $totalMeetings);

		$htmlSummary = sprintf(
			'<p>Total Meetings: <strong>%d</strong> out of <strong>%d</strong> (Approx %d%%)</p>',
			$registeredCount,
			$totalMeetings,
			$percentageRegistered
		);

		$html = '<table border="1" cellspacing="0" cellpadding="5">';
		$html .= '<tr><th>Name</th><th>Status</th><th>Updated</th><th>Email</th><th>Contact 1</th><th>Contact 2</th></tr>';

		$statusCounts = [];

		foreach ($registered as $item) {
			$meetingName = get_the_title($item['meeting']);
			$meetingUrl = get_permalink($item['answers']);
			$updated = trim($item['updated']);
			$status = isset($item['state']) && !empty($item['state']) ? $item['state'] : 'Not Started';

			if (empty($updated)) {
				$updated = "Not Started";
			}

			$contacts = $this->meetingRepository->getMeetingContacts($item['meeting']);

			$contact1 = isset($contacts[0]) ? '<td>' . $this->contactTelephoneLink($contacts[0]) . '</td>' : '<td>-</td>';
			$contact2 = isset($contacts[1]) ? '<td>' . $this->contactTelephoneLink($contacts[1]) . '</td>' : '<td>-</td>';

			$emailLink = !empty($item['email'])
				? HtmlHelper::createLink(
					HtmlHelper::createEmailToAddress($item['email'], "Questions for Conference"),
					'',
					$item['email']
				)
				: '-';

			$meetingLink = HtmlHelper::createLink($meetingUrl, '', $meetingName);

			$html .= "<tr><td>{$meetingLink}</td><td>{$status}</td><td>{$updated}</td><td>{$emailLink}</td>$contact1 $contact2</tr>";

			$statusCounts[$status] = isset($statusCounts[$status]) ? $statusCounts[$status] + 1 : 1;
		}

		$html .= "</table>";

		$statusHtml = $this->generateStatusCountsTable($statusCounts);

		return $htmlSummary . $statusHtml . $html;
	}

	/**
	 * Generate status counts table
	 *
	 * @param array $statusCounts Status counts
	 * @return string Rendered HTML
	 */
	private function generateStatusCountsTable(array $statusCounts): string
	{
		$html = '<table border="1" cellspacing="0" cellpadding="5">';
		$html .= '<thead><tr>';

		foreach ($statusCounts as $status => $count) {
			$html .= "<th>{$status}</th>";
		}
		$html .= '</tr></thead><tbody><tr>';

		foreach ($statusCounts as $count) {
			$html .= "<td>{$count}</td>";
		}

		$html .= "</tr></tbody></table>";

		return $html;
	}

	/**
	 * Generate unregistered table
	 *
	 * @param array $meetings All meetings
	 * @param array $answers All answers
	 * @param array $registered Registered groups
	 * @return string Rendered HTML
	 */
	private function generateUnregisteredTable(array $meetings, array $answers, array $registered): string
	{
		$registeredMeetings = array_unique(array_column($registered, "meeting"));

		$unregistered = array_filter($meetings, function ($item) use ($registeredMeetings) {
			return !in_array($item["id"], $registeredMeetings);
		});

		$totalMeetings = count($meetings);
		$unregisteredCount = count($unregistered);
		$percentageUnregistered = $this->calculatePercentage($unregisteredCount, $totalMeetings);

		$htmlSummary = sprintf(
			'<p>Total Meetings: <strong>%d</strong> out of <strong>%d</strong> (Approx %d%%)</p>',
			$unregisteredCount,
			$totalMeetings,
			$percentageUnregistered
		);

		$html = '<table border="1" cellspacing="0" cellpadding="5">';
		$html .= '<tr><th>Name</th><th>Contact 1</th><th>Contact 2</th><th>Day</th><th>Time</th></tr>';

		foreach ($unregistered as $meeting) {
			$contacts = $this->meetingRepository->getMeetingContacts($meeting['id']);

			$contact1 = isset($contacts[0]) ? '<td>' . $this->contactTelephoneLink($contacts[0]) . '</td>' : '<td>-</td>';
			$contact2 = isset($contacts[1]) ? '<td>' . $this->contactTelephoneLink($contacts[1]) . '</td>' : '<td>-</td>';

			$meetingLink = HtmlHelper::createLink($meeting['url'], '', $meeting['name']);

			$html .= sprintf(
				"<tr><td>%s</td>%s %s<td>%s</td><td>%s</td></tr>",
				$meetingLink,
				$contact1,
				$contact2,
				$meeting['day'],
				$meeting['time']
			);
		}

		$html .= "</table>";

		return $htmlSummary . $html;
	}

	/**
	 * Generate answer table
	 *
	 * @param array $answers All answers
	 * @return string Rendered HTML
	 */
	private function generateAnswerTable(array $answers): string
	{
		$html = $this->getAnswerTableStyles();

		$html .= '<table id="all_answers" border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
		$groupedAnswers = [];
		$addedAnchors = [];

		foreach ($answers as $key => $rows) {
			[$committeeNumber, $questionNumber] = explode('_', $key);
			$committeeNumber = str_replace('c', '', $committeeNumber);
			$questionNumber = str_replace('a', '', $questionNumber);

			if (!isset($groupedAnswers[$committeeNumber])) {
				$groupedAnswers[$committeeNumber] = [];
			}

			$groupedAnswers[$committeeNumber][$questionNumber] = $rows;
		}

		ksort($groupedAnswers);

		foreach ($groupedAnswers as $committeeNumber => $questionsByCommittee) {
			$html .= $this->renderCommitteeSection($committeeNumber, $questionsByCommittee, $addedAnchors);
		}

		$html .= '</table>';
		$html .= $this->getClipboardScript();

		return $html;
	}

	/**
	 * Render committee section
	 *
	 * @param string $committeeNumber Committee number
	 * @param array $questionsByCommittee Questions
	 * @param array $addedAnchors Added anchors tracker
	 * @return string Rendered HTML
	 */
	private function renderCommitteeSection(string $committeeNumber, array $questionsByCommittee, array &$addedAnchors): string
	{
		$committeeId = "committee_{$committeeNumber}";

		$html = "<tr><td colspan='2' class='committee-header'>Committee {$committeeNumber} ";
		$html .= "<button class='copy-btn' onclick=\"copyCommitteeToClipboard('{$committeeId}', {$committeeNumber})\">📋 Copy Committee</button>";

		foreach ($questionsByCommittee as $questionNumber => $rows) {
			$html .= "<button class='copy-all-answers-btn' onclick=\"copyAllAnswersToClipboard('{$committeeNumber}', {$questionNumber})\">Copy All Answers {$questionNumber}</button>";
		}

		$html .= "</td></tr>";
		$html .= "<tr><td colspan='2'><div id='{$committeeId}'>";

		foreach ($questionsByCommittee as $questionNumber => $rows) {
			foreach ($rows as $row) {
				if (Constants::STATUS_CANCELLED != $row[6]) {
					$html .= $this->renderAnswerGroup($committeeNumber, $questionNumber, $row, $addedAnchors);
				}
			}
		}

		$html .= "</div></td></tr>";

		return $html;
	}

	/**
	 * Render answer group
	 *
	 * @param string $committeeNumber Committee number
	 * @param string $questionNumber Question number
	 * @param array $row Answer data
	 * @param array $addedAnchors Added anchors tracker
	 * @return string Rendered HTML
	 */
	private function renderAnswerGroup(string $committeeNumber, string $questionNumber, array $row, array &$addedAnchors): string
	{
		$time = $row[4];
		$meeting = $row[1];
		$email = $row[3];
		$answer = $row[5];

		$anchorKey = "c{$committeeNumber}_a{$questionNumber}";
		$anchorId = "";
		if (!isset($addedAnchors[$anchorKey])) {
			$anchorId = "id='{$anchorKey}'";
			$addedAnchors[$anchorKey] = true;
		}

		$html = "<div class='answer-group' $anchorId data-committee='{$committeeNumber}' data-question='{$questionNumber}' data-meeting='{$meeting}' data-answer='{$answer}'>";
		$html .= "<div class='question-header'>{$meeting}</div>";
		$html .= "<div><strong>Committee {$committeeNumber} - Question {$questionNumber}</strong></div>";
		$html .= "<div><strong>Updated:</strong> {$time}</div>";
		$html .= "<div><strong>Email:</strong> {$email}</div>";
		$html .= "<div><strong>Answer:</strong><div class=\"answer\">{$answer}</div></div>";
		$html .= "</div>";

		return $html;
	}

	/**
	 * Get answer table styles
	 *
	 * @return string CSS styles
	 */
	private function getAnswerTableStyles(): string
	{
		return '<style>
            .committee-header { background-color: #d9e1f2; font-weight: bold; }
            .copy-btn { margin-left: 10px; padding: 5px; cursor: pointer; background-color: #4CAF50; color: white; border: none; border-radius: 5px; }
            .copy-all-answers-btn { margin-left: 10px; padding: 5px; cursor: pointer; background-color: #4CAF50; color: white; border: none; border-radius: 5px; font-size: 12px; }
            .answer-group { border: 1px solid #ccc; padding: 10px; margin-bottom: 15px; background-color: #f9f9f9; }
            .question-header { background-color: #f2f2f2; font-weight: bold; padding: 5px; }
        </style>';
	}

	/**
	 * Get clipboard JavaScript
	 *
	 * @return string JavaScript code
	 */
	private function getClipboardScript(): string
	{
		return "<script>
            function copyCommitteeToClipboard(committeeId, committeeNumber) {
                var committeeDiv = document.getElementById(committeeId);
                var answerGroups = committeeDiv.getElementsByClassName('answer-group');
                var clipboardText = 'Committee ' + committeeNumber + '\\n';

                for (var i = 0; i < answerGroups.length; i++) {
                    var questionNumber = answerGroups[i].getAttribute('data-question');
                    var meeting = answerGroups[i].getAttribute('data-meeting');
                    var answer = answerGroups[i].getAttribute('data-answer');

                    clipboardText += '\\nQuestion: ' + questionNumber + '\\n';
                    clipboardText += 'Meeting: ' + meeting + '\\n';
                    clipboardText += answer + '\\n';
                    clipboardText += '\\n';
                }

                navigator.clipboard.writeText(clipboardText).then(function() {
                    alert('Copied Committee ' + committeeNumber + ' to clipboard!');
                }, function(err) {
                    console.error('Error copying text: ', err);
                });
            }

            function copyAllAnswersToClipboard(committeeNumber, questionNumber) {
                var committeeDiv = document.getElementById('committee_' + committeeNumber);
                var answerGroups = committeeDiv.getElementsByClassName('answer-group');
                var clipboardText = 'All Answers for Committee ' + committeeNumber + ' - Question ' + questionNumber + '\\n';

                for (var i = 0; i < answerGroups.length; i++) {
                    var currentCommitteeNumber = answerGroups[i].getAttribute('data-committee');
                    var currentQuestionNumber = answerGroups[i].getAttribute('data-question');
                    
                    if (currentCommitteeNumber == committeeNumber && currentQuestionNumber == questionNumber) {
                        var meeting = answerGroups[i].getAttribute('data-meeting');
                        var answer = answerGroups[i].getAttribute('data-answer');
                        
                        clipboardText += '\\nMeeting: ' + meeting + '\\n';
                        clipboardText += answer + '\\n';
                        clipboardText += '\\n';
                    }
                }

                navigator.clipboard.writeText(clipboardText).then(function() {
                    alert('Copied all answers for Committee ' + committeeNumber + ' - Question ' + questionNumber + ' to clipboard!');
                }, function(err) {
                    console.error('Error copying text: ', err);
                });
            }
        </script>";
	}

	/**
	 * Get unique count
	 *
	 * @param array $array Array to count
	 * @param string $field Field to count
	 * @return int Unique count
	 */
	private function getUniqueCount(array $array, string $field): int
	{
		$things = array_column($array, $field);
		$counts = array_count_values($things);

		return count(array_filter($counts, function ($count) {
			return $count == 1;
		}));
	}

	/**
	 * Calculate percentage
	 *
	 * @param int $part Part value
	 * @param int $total Total value
	 * @return int Percentage
	 */
	private function calculatePercentage(int $part, int $total): int
	{
		if ($total == 0) {
			return 0;
		}

		return (int) round(($part / $total) * 100);
	}

	/**
	 * Create contact telephone link
	 *
	 * @param array $contact Contact data
	 * @return string Rendered HTML
	 */
	private function contactTelephoneLink(array $contact): string
	{
		return $contact['name'] . ' ' . HtmlHelper::createLink(
				HtmlHelper::createPhoneToAddress($contact['phone']),
				'',
				$contact['phone']
			);
	}
}