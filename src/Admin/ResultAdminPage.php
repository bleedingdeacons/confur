<?php

namespace Confur\Admin;

use Confur\Config\Constants;
use Confur\Domain\AnswerKey;
use Confur\Domain\QuestionSet;
use Confur\Repositories\AnswerRepository;
use Confur\Services\AdminAssets;

/**
 * Admin page for Conference Reports
 * Self-contained version with all report generation code included
 */
class ResultAdminPage
{
    private const PAGE_SLUG = 'confur-reporting';
    private const CAPABILITY = 'read';
    private const MENU_TITLE = 'Results';
    private const PAGE_TITLE = 'Result Report';

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
     * Register the admin page as a submenu under Confur
     */
    public function registerAdminPage(): void
    {
        add_submenu_page(
            'confur',                      // Parent slug (Confur menu)
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
        // Only load on our reporting page
        if ('questions-for-conference_page_' . self::PAGE_SLUG !== $hook) {
            return;
        }

        AdminAssets::style('results');
        // The copy-to-clipboard buttons.
        AdminAssets::script('results');
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

        Template::render('admin/results', [
            'pageTitle' => self::PAGE_TITLE,
            'generatedAt' => current_time('F j, Y g:i a'),
            'reportHtml' => $this->generateReportContent(),
        ]);
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
        // Every committee is named by the question set: "Committee N", or
        // "All Committees" for the questions every committee answers together.
        // Nothing on this page needs to know which number, if any, that is.
        $questions = QuestionSet::current();

        // Generate individual sections
        $allAnswerTable = $this->generateAnswerTable($questions, $answers);
        $coverage = $this->generateCoverageTable($questions, $answers);
        $linksTable = $this->generateLinksTable($questions);

        // Return report without registration section
        return sprintf(
            '<h2 id="answer_table">Navigation</h2>%s<h2 id="answer_table">Answers</h2>%s<h2 id="coverage">Coverage</h2>%s',
            $linksTable,
            $allAnswerTable,
            $coverage
        );
    }

    /**
     * Generate answer table
     *
     * @param QuestionSet $questions Names the committees
     * @param array<string, mixed> $answers All answers
     * @return string Rendered HTML
     */
    private function generateAnswerTable(QuestionSet $questions, array $answers): string
    {
        $html = '';

        $html .= '<table id="all_answers" style="border-collapse: collapse; width: 100%;">';
        $groupedAnswers = [];
        $addedAnchors = [];

        foreach ($answers as $fieldName => $rows) {
            $key = AnswerKey::parse((string) $fieldName);

            if ($key === null) {
                continue;
            }

            $groupedAnswers[$key->committee][$key->question] = $rows;
        }

        ksort($groupedAnswers);

        foreach ($groupedAnswers as $committeeNumber => $questionsByCommittee) {
            $html .= $this->renderCommitteeSection(
                (string) $committeeNumber,
                $questions->committeeLabel($committeeNumber),
                $questionsByCommittee,
                $addedAnchors
            );
        }

        $html .= '</table>';

        return $html;
    }

    /**
     * Render committee section
     *
     * @param string $committeeNumber Committee number
     * @param string $label What the committee is called, e.g. "Committee 2"
     * @param array<int, mixed> $questionsByCommittee Question number => rows
     * @param array<string, mixed> $addedAnchors Added anchors tracker
     * @return string Rendered HTML
     */
    private function renderCommitteeSection(string $committeeNumber, string $label, array $questionsByCommittee, array &$addedAnchors): string
    {
        $committeeId = "committee_{$committeeNumber}";

        $html = "<tr><td colspan='2' class='committee-header'>" . esc_html($label) . ' ';
        $html .= "<button class='copy-btn' onclick=\"copyCommitteeToClipboard('{$committeeId}', {$committeeNumber})\">📋 Copy " . esc_html($label) . '</button>';

        foreach ($questionsByCommittee as $questionNumber => $rows) {
            $html .= "<button class='copy-all-answers-btn' onclick=\"copyAllAnswersToClipboard('{$committeeNumber}', {$questionNumber})\">Copy All Answers {$questionNumber}</button>";
        }

        $html .= "</td></tr>";
        $html .= "<tr><td colspan='2'><div id='{$committeeId}' data-label='" . esc_attr($label) . "'>";

        foreach ($questionsByCommittee as $questionNumber => $rows) {
            foreach ($rows as $row) {
                $status = $row['status'];
                if (Constants::STATUS_DRAFT === $status || Constants::STATUS_COMPLETED === $status) {
                    $html .= $this->renderAnswerGroup($committeeNumber, $label, (string) $questionNumber, $row, $addedAnchors);
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
     * @param string $label What the committee is called, e.g. "Committee 2"
     * @param string $questionNumber Question number
     * @param array<string, mixed> $row Answer data
     * @param array<string, mixed> $addedAnchors Added anchors tracker
     * @return string Rendered HTML
     */
    private function renderAnswerGroup(string $committeeNumber, string $label, string $questionNumber, array $row, array &$addedAnchors): string
    {
        $groupId = $row['groupId'];
        $fellowGroupId = $row['fellowGroupId'];
        $group = (string) $row['groupName'];
        // Member-written, so every value is escaped where it is placed. They
        // used to go in raw, which let an answer containing ' or <script>
        // break out of its attribute on this (admin) screen. The copy buttons
        // read them back with getAttribute()/textContent, which unescape.
        $email = esc_html((string) $row['email']);
        $time = esc_html((string) $row['updated']);
        $answer = (string) $row['answer'];
        $status = (string) $row['status'];

        $anchorKey = "c{$committeeNumber}_a{$questionNumber}";
        $anchorId = "";
        if (!isset($addedAnchors[$anchorKey])) {
            $anchorId = "id='{$anchorKey}'";
            $addedAnchors[$anchorKey] = true;
        }

        // Both groups' names for a pair.
        $groupHeader = $group;
        if (!empty($fellowGroupId) && $fellowGroupId != $groupId) {
            $fellowGroupName = get_the_title($fellowGroupId);
            if (!empty($fellowGroupName)) {
                $groupHeader = "{$group} & {$fellowGroupName}";
            }
        }

        $html = sprintf(
            "<div class='answer-group' %s data-committee='%s' data-question='%s' data-group='%s' data-status='%s'>",
            $anchorId,
            esc_attr($committeeNumber),
            esc_attr($questionNumber),
            esc_attr($group),
            esc_attr($status)
        );
        $html .= "<div class='question-header'>" . esc_html($groupHeader) . ' - ' . esc_html($status) . '</div>';
        $html .= '<div><strong>' . esc_html($label) . " - Question {$questionNumber}</strong></div>";
        $html .= "<div><strong>Updated:</strong> {$time}</div>";
        $html .= "<div><strong>Email:</strong> {$email}</div>";
        $html .= '<div><strong>Answer:</strong><div class="answer">' . esc_html($answer) . '</div></div>';
        $html .= "</div>";

        return $html;
    }
    /**
     * Generate coverage table
     *
     * @param QuestionSet $questions Names the committees
     * @param array<string, mixed> $data Answer data
     * @return string Rendered HTML
     */
    private function generateCoverageTable(QuestionSet $questions, array $data): string
    {
        $results = [];

        foreach ($data as $fieldName => $answers) {
            $key = AnswerKey::parse((string) $fieldName);

            if ($key === null) {
                continue;
            }

            $committee = $key->committee;
            $answer = $key->question;

            $wordCounts = array_map(function ($response) {
                return str_word_count($response['answer']);
            }, $answers);

            $responseCount = count($answers);
            $totalWords = array_sum($wordCounts);
            $avgWordCount = $responseCount > 0 ? $totalWords / $responseCount : 0;
            // Keyed off $wordCounts, not $responseCount: they have the same
            // length, but only the former proves to the type checker that
            // min()/max() are not being handed an empty array.
            $minWordCount = $wordCounts === [] ? 0 : min($wordCounts);
            $maxWordCount = $wordCounts === [] ? 0 : max($wordCounts);

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

        $html = "<table style='border-collapse: collapse;'>";
        $html .= "<tr><th>Committee</th><th>Answer</th><th>Response Count</th><th>Average Word Count</th><th>Lowest Word Count</th><th>Highest Word Count</th></tr>";

        foreach ($results as $result) {
            $link = $this->createAnswerLink(
                (int) $result['committee'],
                (int) $result['answer'],
                'Answer ' . $result['answer']
            );

            $html .= sprintf(
                "<tr><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%d</td><td>%d</td></tr>",
                esc_html($questions->committeeLabel($result['committee'])),
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
     * @param QuestionSet $questions The committees to link, and their names
     * @return string Rendered HTML
     */
    private function generateLinksTable(QuestionSet $questions): string
    {
        $cells = '';

        // One column per committee in the set, linking each question.
        foreach ($questions->committees() as $committee) {
            $cells .= sprintf(
                '<td class="answerLinks">%s</td>',
                $this->createAnswerLinks($questions->committeeLabel($committee), $committee, $questions->questionCount($committee))
            );
        }

        return '<table id="answer_links"><tbody><tr>' . $cells . '</tr></tbody></table>';
    }

    /**
     * Create answer links for a committee
     *
     * @param string $label What the committee is called, e.g. "Committee 2"
     * @param int $committeeNumber Committee number
     * @param int $answerCount Number of answers
     * @return string Rendered HTML
     */
    private function createAnswerLinks(string $label, int $committeeNumber, int $answerCount): string
    {
        $html = '<strong>' . esc_html($label) . '</strong><ul>';

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
}
