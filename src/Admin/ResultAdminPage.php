<?php

namespace Confur\Admin;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;

/**
 * Admin page for Conference Reports
 * Self-contained version with all report generation code included
 */
class ResultAdminPage
{
    private const PAGE_SLUG = 'confur-reporting';
    private const CAPABILITY = 'manage_options';
    private const MENU_TITLE = 'Results';
    private const PAGE_TITLE = 'Current Results';

    private AnswerRepository $answerRepository;

    public function __construct()
    {
        $this->answerRepository = new AnswerRepository();
    }

    /**
     * Initialize the admin page
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    /**
     * Register the admin page as a submenu under the Answers menu
     */
    public function registerAdminPage(): void
    {
        add_submenu_page(
                'edit.php?post_type=answer',  // Parent slug - Answers custom post type
                self::PAGE_TITLE,              // Page title
                self::MENU_TITLE,              // Menu title
                self::CAPABILITY,              // Capability
                self::PAGE_SLUG,               // Menu slug
                [$this, 'renderPage']          // Callback function
        );
    }

    /**
     * Enqueue admin-specific CSS and JavaScript
     *
     * @param string $hook Current admin page hook
     */
    public function enqueueAdminAssets(string $hook): void
    {
        // Only load on our reporting page (submenu under answer post type)
        if ('answer_page_' . self::PAGE_SLUG !== $hook) {
            return;
        }

        // Enqueue admin styles inline
        wp_register_style('confur-reporting-admin', false);
        wp_enqueue_style('confur-reporting-admin');
        wp_add_inline_style('confur-reporting-admin', $this->getAdminStyles());

        // Enqueue admin scripts inline
        wp_register_script('confur-reporting-admin', false);
        wp_enqueue_script('confur-reporting-admin');
        wp_add_inline_script('confur-reporting-admin', $this->getAdminScripts());
    }

    /**
     * Render the admin page
     */
    public function renderPage(): void
    {
        // Check user capabilities
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'confur'));
        }

        // Get report content
        $reportContent = $this->generateReportContent();

        // Render the admin page
        ?>
        <div class="wrap confur-reporting-admin">
            <div class="confur-reporting-header">
                <h1 class="wp-heading-inline">
                    <span class="dashicons dashicons-analytics"></span>
                    <?php echo esc_html(self::PAGE_TITLE); ?>
                </h1>
                <div class="confur-reporting-actions">
                    <?php $this->renderActionButtons(); ?>
                </div>
            </div>

            <hr class="wp-header-end">

            <?php $this->renderNotices(); ?>

            <div class="confur-reporting-content">
                <?php
                // Output report content - already contains inline styles/scripts
                // We output it directly since it's from our own trusted source
                echo $reportContent; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </div>

            <div class="confur-reporting-footer">
                <p class="description">
                    Report generated on: <strong><?php echo esc_html(current_time('F j, Y g:i a')); ?></strong>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Generate report content
     *
     * @return string Rendered HTML
     */
    private function generateReportContent(): string
    {
        // Get answers data
        $answers = $this->answerRepository->getGroupAnswers();

        // Generate individual sections
        $allAnswerTable = $this->generateAnswerTable($answers);
        $coverage = $this->generateCoverageTable($answers);
        $linksTable = $this->generateLinksTable();

        // Return report without registration section
        return sprintf(
                '<h1>Reporting</h1>%s<h2 id="answer_table">Answers</h2>%s<h2 id="coverage">Coverage</h2>%s',
                $linksTable,
                $allAnswerTable,
                $coverage
        );
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
     * Generate links table without navigation column
     *
     * @return string Rendered HTML
     */
    private function generateLinksTable(): string
    {
        $html = '<table id="answer_links"><tbody>';

        $committee1 = $this->createAnswerLinks(1, 4);
        $committee2 = $this->createAnswerLinks(2, 4);
        $committee3 = $this->createAnswerLinks(3, 4);
        $committee4 = $this->createAnswerLinks(4, 4);
        $committee5 = $this->createAnswerLinks(5, 3);
        $committee6 = $this->createAnswerLinks(6, 2);

        $class = 'class="answerLinks"';

        $html .= sprintf(
                "<tr><td %s>%s</td><td %s>%s</td><td %s>%s</td><td %s>%s</td><td %s>%s</td><td %s>%s</td></tr>",
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
     * Render action buttons in the header
     */
    private function renderActionButtons(): void
    {
        ?>
        <button type="button" class="button button-primary" onclick="window.print();">
            <span class="dashicons dashicons-printer"></span>
            Print Report
        </button>
        <button type="button" class="button" onclick="confurReportingRefresh();">
            <span class="dashicons dashicons-update"></span>
            Refresh
        </button>
        <button type="button" class="button" onclick="confurReportingExportCSV();">
            <span class="dashicons dashicons-download"></span>
            Export CSV
        </button>
        <?php
    }

    /**
     * Render admin notices
     */
    private function renderNotices(): void
    {
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong>Tip:</strong> Use the navigation links to jump to different sections of the report.
                Click the copy buttons to copy committee data to your clipboard.
            </p>
        </div>
        <?php
    }

    /**
     * Get admin-specific CSS styles
     *
     * @return string CSS styles
     */
    private function getAdminStyles(): string
    {
        return '
			/* Reporting Admin Page Styles */
			.confur-reporting-admin {
				background: #fff;
				padding: 20px;
				margin: 20px 20px 20px 0;
				box-shadow: 0 1px 3px rgba(0,0,0,0.1);
			}

			.confur-reporting-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 20px;
				flex-wrap: wrap;
				gap: 15px;
			}

			.confur-reporting-header h1 {
				display: flex;
				align-items: center;
				gap: 10px;
				margin: 0;
			}

			.confur-reporting-header .dashicons {
				font-size: 28px;
				width: 28px;
				height: 28px;
			}

			.confur-reporting-actions {
				display: flex;
				gap: 10px;
				flex-wrap: wrap;
			}

			.confur-reporting-actions .button {
				display: inline-flex;
				align-items: center;
				gap: 5px;
			}

			.confur-reporting-actions .button .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
			}

			.confur-reporting-content {
				margin-top: 20px;
			}

			.confur-reporting-footer {
				margin-top: 40px;
				padding-top: 20px;
				border-top: 1px solid #ddd;
				text-align: right;
			}

			/* Enhanced table styles for admin */
			.confur-reporting-content table {
				width: 100%;
				border-collapse: collapse;
				margin: 20px 0;
				background: #fff;
				box-shadow: 0 1px 3px rgba(0,0,0,0.05);
			}

			.confur-reporting-content table th {
				background-color: #f0f0f1;
				font-weight: 600;
				text-align: left;
				padding: 12px;
				border: 1px solid #c3c4c7;
			}

			.confur-reporting-content table td {
				padding: 10px 12px;
				border: 1px solid #c3c4c7;
			}

			.confur-reporting-content table tr:nth-child(even) {
				background-color: #f9f9f9;
			}

			.confur-reporting-content table tr:hover {
				background-color: #f0f0f1;
			}

			/* Committee header styling */
			.confur-reporting-content .committee-header {
				background-color: #2271b1 !important;
				color: #fff !important;
				font-weight: bold;
				padding: 12px !important;
			}

			/* Copy buttons in admin context */
			.confur-reporting-content .copy-btn,
			.confur-reporting-content .copy-all-answers-btn {
				background-color: #2271b1 !important;
				color: white !important;
				border: none !important;
				border-radius: 3px !important;
				padding: 6px 12px !important;
				cursor: pointer;
				font-size: 12px;
				margin-left: 10px;
				transition: background-color 0.2s;
			}

			.confur-reporting-content .copy-btn:hover,
			.confur-reporting-content .copy-all-answers-btn:hover {
				background-color: #135e96 !important;
			}

			/* Answer group styling */
			.confur-reporting-content .answer-group {
				border: 1px solid #c3c4c7;
				padding: 15px;
				margin-bottom: 15px;
				background-color: #fff;
				border-radius: 4px;
				box-shadow: 0 1px 2px rgba(0,0,0,0.05);
			}

			.confur-reporting-content .question-header {
				background-color: #f0f0f1;
				font-weight: 600;
				padding: 10px;
				margin: -15px -15px 15px -15px;
				border-radius: 4px 4px 0 0;
			}

			.confur-reporting-content .answer {
				margin-top: 10px;
				padding: 10px;
				background-color: #f9f9f9;
				border-left: 3px solid #2271b1;
				line-height: 1.6;
			}

			/* Answer links table */
			.confur-reporting-content #answer_links {
				background: #f0f0f1;
				margin: 20px 0;
			}

			.confur-reporting-content #answer_links td {
				vertical-align: top;
				padding: 15px;
			}

			.confur-reporting-content #answer_links ul {
				margin: 5px 0;
				padding-left: 20px;
			}

			.confur-reporting-content #answer_links li {
				margin: 5px 0;
			}

			.confur-reporting-content #answer_links a {
				color: #2271b1;
				text-decoration: none;
			}

			.confur-reporting-content #answer_links a:hover {
				color: #135e96;
				text-decoration: underline;
			}

			/* Headings in report */
			.confur-reporting-content h1,
			.confur-reporting-content h2,
			.confur-reporting-content h3 {
				margin-top: 30px;
				margin-bottom: 15px;
				color: #1d2327;
			}

			.confur-reporting-content h1 {
				font-size: 28px;
				font-weight: 600;
				border-bottom: 2px solid #2271b1;
				padding-bottom: 10px;
			}

			.confur-reporting-content h2 {
				font-size: 23px;
				font-weight: 600;
				border-bottom: 1px solid #c3c4c7;
				padding-bottom: 8px;
			}

			.confur-reporting-content h3 {
				font-size: 19px;
				font-weight: 600;
			}

			/* Print styles */
			@media print {
				.confur-reporting-header,
				.confur-reporting-actions,
				.notice,
				.confur-reporting-footer,
				.copy-btn,
				.copy-all-answers-btn {
					display: none !important;
				}

				.confur-reporting-admin {
					box-shadow: none;
					padding: 0;
					margin: 0;
				}

				.confur-reporting-content table {
					page-break-inside: auto;
				}

				.confur-reporting-content tr {
					page-break-inside: avoid;
					page-break-after: auto;
				}

				.answer-group {
					page-break-inside: avoid;
				}
			}

			/* Responsive adjustments */
			@media screen and (max-width: 782px) {
				.confur-reporting-header {
					flex-direction: column;
					align-items: flex-start;
				}

				.confur-reporting-actions {
					width: 100%;
				}

				.confur-reporting-actions .button {
					flex: 1;
					justify-content: center;
				}

				.confur-reporting-content table {
					font-size: 14px;
				}

				.confur-reporting-content table td,
				.confur-reporting-content table th {
					padding: 8px;
				}
			}
		';
    }

    /**
     * Get admin-specific JavaScript
     *
     * @return string JavaScript code
     */
    private function getAdminScripts(): string
    {
        return '
			function copyCommitteeToClipboard(committeeId, committeeNumber) {
				var committeeDiv = document.getElementById(committeeId);
				if (!committeeDiv) {
					alert("Committee section not found");
					return;
				}
				
				var answerGroups = committeeDiv.getElementsByClassName("answer-group");
				var clipboardText = "Committee " + committeeNumber + "\\n";

				for (var i = 0; i < answerGroups.length; i++) {
					var questionNumber = answerGroups[i].getAttribute("data-question");
					var meeting = answerGroups[i].getAttribute("data-meeting");
					var answer = answerGroups[i].getAttribute("data-answer");

					clipboardText += "\\nQuestion: " + questionNumber + "\\n";
					clipboardText += "Meeting: " + meeting + "\\n";
					clipboardText += answer + "\\n";
					clipboardText += "\\n";
				}

				navigator.clipboard.writeText(clipboardText).then(function() {
					alert("Copied Committee " + committeeNumber + " to clipboard!");
				}, function(err) {
					console.error("Error copying text: ", err);
					alert("Failed to copy to clipboard. Please ensure you are using HTTPS.");
				});
			}

			function copyAllAnswersToClipboard(committeeNumber, questionNumber) {
				var committeeDiv = document.getElementById("committee_" + committeeNumber);
				if (!committeeDiv) {
					alert("Committee section not found");
					return;
				}
				
				var answerGroups = committeeDiv.getElementsByClassName("answer-group");
				var clipboardText = "All Answers for Committee " + committeeNumber + " - Question " + questionNumber + "\\n";

				for (var i = 0; i < answerGroups.length; i++) {
					var currentCommitteeNumber = answerGroups[i].getAttribute("data-committee");
					var currentQuestionNumber = answerGroups[i].getAttribute("data-question");
					
					if (currentCommitteeNumber == committeeNumber && currentQuestionNumber == questionNumber) {
						var meeting = answerGroups[i].getAttribute("data-meeting");
						var answer = answerGroups[i].getAttribute("data-answer");
						
						clipboardText += "\\nMeeting: " + meeting + "\\n";
						clipboardText += answer + "\\n";
						clipboardText += "\\n";
					}
				}

				navigator.clipboard.writeText(clipboardText).then(function() {
					alert("Copied all answers for Committee " + committeeNumber + " - Question " + questionNumber + " to clipboard!");
				}, function(err) {
					console.error("Error copying text: ", err);
					alert("Failed to copy to clipboard. Please ensure you are using HTTPS.");
				});
			}

			function confurReportingRefresh() {
				location.reload();
			}

			function confurReportingExportCSV() {
				alert("CSV export functionality can be implemented based on specific requirements.");
			}
		';
    }
}