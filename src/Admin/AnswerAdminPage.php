<?php

namespace Confur\Admin;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;

/**
 * Admin page for displaying answer submissions
 */
class AnswerAdminPage
{
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
		// Only load in admin area
		if (!is_admin()) {
			return;
		}

		add_action('admin_menu', [$this, 'addAdminMenu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
	}

	/**
	 * Add admin menu item as submenu under ACF Answers
	 */
	public function addAdminMenu(): void
	{
		add_submenu_page(
			'edit.php?post_type=answer',   // Parent slug (ACF Answers menu)
			'Answer Submissions',           // Page title
			'Submissions',                  // Menu title
			'manage_options',               // Capability
			'confur-answer-submissions',    // Menu slug
			[$this, 'renderAdminPage']     // Callback
		);
	}

	/**
	 * Enqueue admin styles and scripts
	 */
	public function enqueueAdminAssets($hook): void
	{
		// Only load on our admin page
		if ($hook !== 'answer_page_confur-answer-submissions') {
			return;
		}

		// Inline CSS for the admin page
		$custom_css = "
			.confur-answers-table {
				width: 100%;
				border-collapse: collapse;
				margin-top: 20px;
				background: #fff;
				box-shadow: 0 1px 3px rgba(0,0,0,0.1);
			}
			.confur-answers-table th {
				background: #f0f0f1;
				padding: 12px;
				text-align: left;
				font-weight: 600;
				border-bottom: 2px solid #c3c4c7;
			}
			.confur-answers-table td {
				padding: 12px;
				border-bottom: 1px solid #e0e0e0;
			}
			.confur-answers-table tr:hover {
				background: #f6f7f7;
			}
			.status-badge {
				display: inline-block;
				padding: 4px 12px;
				border-radius: 12px;
				font-size: 12px;
				font-weight: 600;
				text-transform: uppercase;
			}
			.status-completed {
				background: #d4edda;
				color: #155724;
			}
			.status-draft {
				background: #fff3cd;
				color: #856404;
			}
			.status-not-started {
				background: #f8d7da;
				color: #721c24;
			}
			.answer-name a {
				color: #2271b1;
				text-decoration: none;
				font-weight: 500;
			}
			.answer-name a:hover {
				color: #135e96;
				text-decoration: underline;
			}
			.confur-answers-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 20px;
			}
			.confur-answers-stats {
				display: flex;
				gap: 20px;
			}
			.stat-box {
				background: #fff;
				padding: 15px 20px;
				border-radius: 4px;
				box-shadow: 0 1px 3px rgba(0,0,0,0.1);
				text-align: center;
			}
			.stat-box .number {
				font-size: 24px;
				font-weight: 700;
				color: #2271b1;
			}
			.stat-box .label {
				font-size: 12px;
				color: #646970;
				text-transform: uppercase;
			}
		";
		wp_add_inline_style('wp-admin', $custom_css);
	}

	/**
	 * Render the admin page
	 */
	public function renderAdminPage(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		$answers = $this->getAnswerData();
		$stats = $this->calculateStats($answers);

		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<div class="confur-answers-header">
				<div class="confur-answers-stats">
					<div class="stat-box">
						<div class="number"><?php echo esc_html($stats['total']); ?></div>
						<div class="label">Total</div>
					</div>
					<div class="stat-box">
						<div class="number"><?php echo esc_html($stats['completed']); ?></div>
						<div class="label">Completed</div>
					</div>
					<div class="stat-box">
						<div class="number"><?php echo esc_html($stats['draft']); ?></div>
						<div class="label">Draft</div>
					</div>
					<div class="stat-box">
						<div class="number"><?php echo esc_html($stats['not_started']); ?></div>
						<div class="label">Not Started</div>
					</div>
				</div>
			</div>

			<?php if (empty($answers)): ?>
				<div class="notice notice-info">
					<p><?php _e('No answer submissions found.', 'confur'); ?></p>
				</div>
			<?php else: ?>
				<table class="confur-answers-table">
					<thead>
					<tr>
						<th>Answer Name</th>
						<th>Meeting</th>
						<th>Email</th>
						<th>Status</th>
						<th>Last Saved</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ($answers as $answer): ?>
						<tr>
							<td class="answer-name">
								<a href="<?php echo esc_url(get_edit_post_link($answer['id'])); ?>">
									<?php echo esc_html($answer['name']); ?>
								</a>
							</td>
							<td><?php echo esc_html($answer['meeting']); ?></td>
							<td><?php echo esc_html($answer['email']); ?></td>
							<td>
									<span class="status-badge status-<?php echo esc_attr($answer['status_class']); ?>">
										<?php echo esc_html($answer['status_label']); ?>
									</span>
							</td>
							<td><?php echo esc_html($answer['last_saved']); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get answer data for display
	 *
	 * @return array Answer data
	 */
	private function getAnswerData(): array
	{
		$answers = [];
		$allAnswerIds = $this->answerRepository->getAllAnswers();

		foreach ($allAnswerIds as $postId) {
			$post = get_post($postId);
			if (!$post) {
				continue;
			}

			$meeting = get_field(Constants::MEETING_FIELD, $postId);
			$email = get_field(Constants::EMAIL_FIELD, $postId);
			$updated = get_field(Constants::UPDATED_FIELD, $postId);
			$status = get_field(Constants::STATUS_FIELD, $postId);

			// Get meeting name
			$meetingName = $meeting ? get_the_title($meeting) : 'N/A';

			// Get status information
			$statusInfo = $this->getStatusInfo($status);

			// Format last saved date
			$lastSaved = 'N/A';
			if (!empty($updated)) {
				$lastSaved = $updated;
			} elseif ($post->post_modified) {
				$lastSaved = date('F j, Y g:i a', strtotime($post->post_modified));
			}

			$answers[] = [
				'id' => $postId,
				'name' => $post->post_title ?: '(No Title)',
				'meeting' => $meetingName,
				'email' => $email ?: 'N/A',
				'status_label' => $statusInfo['label'],
				'status_class' => $statusInfo['class'],
				'last_saved' => $lastSaved
			];
		}

		// Sort by last modified date (newest first)
		usort($answers, function($a, $b) {
			return strcmp($b['last_saved'], $a['last_saved']);
		});

		return $answers;
	}

	/**
	 * Get status display information
	 *
	 * @param string $status Status value
	 * @return array Status info with label and class
	 */
	private function getStatusInfo($status): array
	{
		switch ($status) {
			case Constants::STATUS_COMPLETED:
			case 'completed':
				return [
					'label' => 'Completed',
					'class' => 'completed'
				];

			case Constants::STATUS_DRAFT:
			case 'draft':
				return [
					'label' => 'Draft',
					'class' => 'draft'
				];

			default:
				return [
					'label' => 'Not Started',
					'class' => 'not-started'
				];
		}
	}

	/**
	 * Calculate statistics
	 *
	 * @param array $answers Answer data
	 * @return array Statistics
	 */
	private function calculateStats(array $answers): array
	{
		$stats = [
			'total' => count($answers),
			'completed' => 0,
			'draft' => 0,
			'not_started' => 0
		];

		foreach ($answers as $answer) {
			switch ($answer['status_class']) {
				case 'completed':
					$stats['completed']++;
					break;
				case 'draft':
					$stats['draft']++;
					break;
				case 'not-started':
					$stats['not_started']++;
					break;
			}
		}

		return $stats;
	}
}