<?php

namespace Confur\Admin;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;
use Confur\Repositories\MeetingRepository;
use Confur\Utils\HtmlHelper;

/**
 * Admin page for displaying answer submissions
 */
class StatusAdminPage
{
    private AnswerRepository $answerRepository;
    private MeetingRepository $meetingRepository;

    public function __construct()
    {
        $this->answerRepository = new AnswerRepository();
        $this->meetingRepository = new MeetingRepository();
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
                'Status',                       // Page title
                'Status',                       // Menu title
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
			.confur-answers-table tr.registered-row {
				background-color: #e8f5e9;
			}
			.confur-answers-table tr.registered-row:hover {
				background-color: #c8e6c9;
			}
			.confur-answers-table tr.unregistered-row {
				background-color: #ffebee;
			}
			.confur-answers-table tr.unregistered-row:hover {
				background-color: #ffcdd2;
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
			.status-unregistered {
				background: #f8d7da;
				color: #721c24;
			}
			.status-cancelled {
				background: #e2e3e5;
				color: #383d41;
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
				flex-wrap: wrap;
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
			.contact-info {
				font-size: 13px;
			}
			.contact-info a {
				color: #2271b1;
				text-decoration: none;
			}
			.contact-info a:hover {
				text-decoration: underline;
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

        $allMeetings = $this->getAllMeetingsData();
        $stats = $this->calculateStats($allMeetings);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="confur-answers-header">
                <div class="confur-answers-stats">
                    <div class="stat-box">
                        <div class="number"><?php echo esc_html($stats['total']); ?></div>
                        <div class="label">Total Meetings</div>
                    </div>
                    <div class="stat-box">
                        <div class="number"><?php echo esc_html($stats['registered']); ?></div>
                        <div class="label">Registered</div>
                    </div>
                    <div class="stat-box">
                        <div class="number"><?php echo esc_html($stats['unregistered']); ?></div>
                        <div class="label">Unregistered</div>
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
                        <div class="number"><?php echo esc_html($stats['cancelled']); ?></div>
                        <div class="label">Cancelled</div>
                    </div>
                </div>
            </div>

            <?php if (empty($allMeetings)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No meetings found.', 'confur'); ?></p>
                </div>
            <?php else: ?>
                <table class="confur-answers-table">
                    <thead>
                    <tr>
                        <th>Meeting Name</th>
                        <th>Status</th>
                        <th>Email</th>
                        <th>Contact 1</th>
                        <th>Contact 2</th>
                        <th>Meeting Day</th>
                        <th>Meeting Time</th>
                        <th>Last Saved</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allMeetings as $meeting): ?>
                        <tr class="<?php echo esc_attr($meeting['row_class']); ?>">
                            <td class="answer-name">
                                <?php if ($meeting['is_registered']): ?>
                                    <a href="<?php echo esc_url($meeting['edit_url']); ?>">
                                        <?php echo esc_html($meeting['name']); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo esc_url($meeting['meeting_url']); ?>" target="_blank">
                                        <?php echo esc_html($meeting['name']); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
								<span class="status-badge status-<?php echo esc_attr($meeting['status_class']); ?>">
									<?php echo esc_html($meeting['status_label']); ?>
								</span>
                            </td>
                            <td>
                                <?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                echo $meeting['email_html'];
                                ?>
                            </td>
                            <td class="contact-info">
                                <?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                echo $meeting['contact1_html'];
                                ?>
                            </td>
                            <td class="contact-info">
                                <?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                echo $meeting['contact2_html'];
                                ?>
                            </td>
                            <td><?php echo esc_html($meeting['day']); ?></td>
                            <td><?php echo esc_html($meeting['time']); ?></td>
                            <td><?php echo esc_html($meeting['last_saved']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get all meetings data (registered and unregistered)
     *
     * @return array Meeting data
     */
    private function getAllMeetingsData(): array
    {
        $allMeetings = [];

        // Get all meetings from repository
        $meetings = $this->meetingRepository->getMeetings();

        // Get registered groups
        $registered = $this->answerRepository->getRegisteredGroups();

        // Create lookup for registered meetings
        $registeredLookup = [];
        foreach ($registered as $item) {
            $registeredLookup[$item['meeting']] = $item;
        }

        // Process all meetings
        foreach ($meetings as $meeting) {
            $meetingId = $meeting['id'];
            $contacts = $this->meetingRepository->getMeetingContacts($meetingId);

            // Get contact HTML
            $contact1Html = isset($contacts[0]) ? $this->contactTelephoneLink($contacts[0]) : '-';
            $contact2Html = isset($contacts[1]) ? $this->contactTelephoneLink($contacts[1]) : '-';

            // Convert day number to day name
            $dayName = $this->getDayName($meeting['day']);

            if (isset($registeredLookup[$meetingId])) {
                // Registered meeting
                $item = $registeredLookup[$meetingId];
                $meetingName = get_the_title($item['meeting']);
                $updated = trim($item['updated']);
                $status = isset($item['state']) && !empty($item['state']) ? $item['state'] : 'Not Started';

                if (empty($updated)) {
                    $updated = "Not Started";
                }

                // Get status information
                $statusInfo = $this->getStatusInfo($status);

                // Create email link
                $emailHtml = !empty($item['email'])
                        ? HtmlHelper::createLink(
                                HtmlHelper::createEmailToAddress($item['email'], "Questions for Conference"),
                                '',
                                $item['email']
                        )
                        : '-';

                $allMeetings[] = [
                        'id' => $meetingId,
                        'name' => $meetingName,
                        'is_registered' => true,
                        'edit_url' => get_edit_post_link($item['answers']),
                        'meeting_url' => $meeting['url'],
                        'status_label' => $statusInfo['label'],
                        'status_class' => $statusInfo['class'],
                        'email_html' => $emailHtml,
                        'contact1_html' => $contact1Html,
                        'contact2_html' => $contact2Html,
                        'day' => $dayName,
                        'time' => $meeting['time'],
                        'last_saved' => $updated,
                        'row_class' => 'registered-row'
                ];
            } else {
                // Unregistered meeting
                $allMeetings[] = [
                        'id' => $meetingId,
                        'name' => $meeting['name'],
                        'is_registered' => false,
                        'edit_url' => '',
                        'meeting_url' => $meeting['url'],
                        'status_label' => 'Unregistered',
                        'status_class' => 'unregistered',
                        'email_html' => '-',
                        'contact1_html' => $contact1Html,
                        'contact2_html' => $contact2Html,
                        'day' => $dayName,
                        'time' => $meeting['time'],
                        'last_saved' => '-',
                        'row_class' => 'unregistered-row'
                ];
            }
        }

        // Sort by registration status (registered first), then by meeting name
        usort($allMeetings, function($a, $b) {
            if ($a['is_registered'] != $b['is_registered']) {
                return $b['is_registered'] - $a['is_registered']; // Registered first
            }
            return strcmp($a['name'], $b['name']);
        });

        return $allMeetings;
    }

    /**
     * Convert day number to day name
     *
     * @param mixed $day Day number (0-6) or string
     * @return string Day name
     */
    private function getDayName($day): string
    {
        // If already a string, return it
        if (!is_numeric($day)) {
            return (string)$day;
        }

        // Convert number to day name using WordPress global function
        // WordPress uses 0 = Sunday, 1 = Monday, etc.
        $dayNumber = (int)$day;

        // Use WordPress's global $wp_locale object for internationalization support
        global $wp_locale;

        if (isset($wp_locale) && method_exists($wp_locale, 'get_weekday')) {
            return $wp_locale->get_weekday($dayNumber);
        }

        // Fallback to built-in PHP function if $wp_locale is not available
        $days = [
                0 => __('Sunday', 'confur'),
                1 => __('Monday', 'confur'),
                2 => __('Tuesday', 'confur'),
                3 => __('Wednesday', 'confur'),
                4 => __('Thursday', 'confur'),
                5 => __('Friday', 'confur'),
                6 => __('Saturday', 'confur')
        ];

        return isset($days[$dayNumber]) ? $days[$dayNumber] : (string)$day;
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

            case Constants::STATUS_CANCELLED:
            case 'cancelled':
                return [
                        'label' => 'Cancelled',
                        'class' => 'cancelled'
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
     * @param array $allMeetings Meeting data
     * @return array Statistics
     */
    private function calculateStats(array $allMeetings): array
    {
        $stats = [
                'total' => count($allMeetings),
                'registered' => 0,
                'unregistered' => 0,
                'completed' => 0,
                'draft' => 0,
                'not_started' => 0,
                'cancelled' => 0
        ];

        foreach ($allMeetings as $meeting) {
            if ($meeting['is_registered']) {
                $stats['registered']++;

                switch ($meeting['status_class']) {
                    case 'completed':
                        $stats['completed']++;
                        break;
                    case 'draft':
                        $stats['draft']++;
                        break;
                    case 'not-started':
                        $stats['not_started']++;
                        break;
                    case 'cancelled':
                        $stats['cancelled']++;
                        break;
                }
            } else {
                $stats['unregistered']++;
            }
        }

        return $stats;
    }
}