<?php

namespace Confur\Admin;

use Confur\Shortcodes\ReportingShortcode;

/**
 * Admin page for Conference Reports
 */
class ReportingAdminPage
{
	private const PAGE_SLUG = 'confur-reporting';
	private const CAPABILITY = 'manage_options';
	private const MENU_TITLE = 'Reports';
	private const PAGE_TITLE = 'Conference Reports';

	private ReportingShortcode $reportingShortcode;

	public function __construct()
	{
		$this->reportingShortcode = new ReportingShortcode();
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

		// Get report content from ReportingShortcode
		$reportContent = $this->reportingShortcode->render();

		// Define allowed HTML for the report content
		$allowed_html = wp_kses_allowed_html('post');
		$allowed_html['style'] = [];
		$allowed_html['script'] = [];

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
				// Output report content - already contains inline styles/scripts from ReportingShortcode
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
		// Check if there's data to display
		// You can add more sophisticated checks here
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