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

        // Register AJAX handler for cancelling duplicates (for logged-in users)
        add_action('wp_ajax_confur_cancel_duplicate', [$this, 'handleCancelDuplicate']);

        // Register AJAX handler for resending confirmation email (for logged-in users)
        add_action('wp_ajax_confur_resend_confirmation', [$this, 'handleResendConfirmation']);
    }

    /**
     * Add admin menu item as submenu under Confur
     */
    public function addAdminMenu(): void
    {
        add_submenu_page(
                'confur',                       // Parent slug (Confur menu)
                'Status',                       // Page title
                'Status',                       // Menu title
                'read',                         // Capability
                'confur-answer-submissions',    // Menu slug
                [$this, 'renderAdminPage']     // Callback
        );
    }

    /**
     * Handle AJAX request to cancel a duplicate registration
     */
    public function handleCancelDuplicate(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'confur_cancel_duplicate')) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        // Check permissions - user must be able to edit answers
        if (!current_user_can('edit_answers')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        // Get and validate answer ID
        $answerId = isset($_POST['answer_id']) ? intval($_POST['answer_id']) : 0;
        if ($answerId <= 0) {
            wp_send_json_error(['message' => 'Invalid answer ID']);
            return;
        }

        // Verify the post exists and is an answer type
        $post = get_post($answerId);
        if (!$post || $post->post_type !== Constants::ANSWER_CUSTOM_TYPE) {
            wp_send_json_error(['message' => 'Answer not found']);
            return;
        }

        // Update the status to cancelled
        $result = update_field(Constants::STATUS_FIELD, Constants::STATUS_CANCELLED, $answerId);

        if ($result) {
            wp_send_json_success(['message' => 'Registration cancelled successfully']);
        } else {
            wp_send_json_error(['message' => 'Failed to cancel registration']);
        }
    }

    /**
     * Handle AJAX request to resend confirmation email
     */
    public function handleResendConfirmation(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'confur_resend_confirmation')) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        // Check permissions - user must be able to edit answers
        if (!current_user_can('edit_answers')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        // Get and validate answer ID
        $answerId = isset($_POST['answer_id']) ? intval($_POST['answer_id']) : 0;
        if ($answerId <= 0) {
            wp_send_json_error(['message' => 'Invalid answer ID']);
            return;
        }

        // Verify the post exists and is an answer type
        $post = get_post($answerId);
        if (!$post || $post->post_type !== Constants::ANSWER_CUSTOM_TYPE) {
            wp_send_json_error(['message' => 'Answer not found']);
            return;
        }

        // Get the email, meeting info, and answer URL
        $email = get_field(Constants::EMAIL_FIELD, $answerId);
        $meetingId = get_field(Constants::MEETING_FIELD, $answerId);
        $fellowMeetingId = get_field(Constants::FELLOW_MEETING_FIELD, $answerId);

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => 'No valid email address found for this registration']);
            return;
        }

        if (empty($meetingId)) {
            wp_send_json_error(['message' => 'No meeting associated with this registration']);
            return;
        }

        // Build meeting name (same logic as in AnswerHandler)
        $meetingName = get_the_title($meetingId);
        if (!empty($fellowMeetingId)) {
            $meetingName = substr($meetingName, 0, 85) . " and " . substr(get_the_title($fellowMeetingId), 0, 85);
        }

        // Get the answer URL
        $answerUrl = get_permalink($answerId);

        // Send the confirmation email
        $result = \Confur\Services\EmailService::sendConfirmation($email, $meetingName, $answerUrl);

        if ($result) {
            wp_send_json_success([
                    'message' => 'Confirmation email sent successfully to ' . $email
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to send confirmation email']);
        }
    }

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueueAdminAssets($hook): void
    {
        // Only load on our admin page
        if ($hook !== 'questions-for-conference_page_confur-answer-submissions') {
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
			.confur-answers-table tr.duplicate-row {
				background-color: #fff3e0;
			}
			.confur-answers-table tr.duplicate-row:hover {
				background-color: #ffe0b2;
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
				background: #6c757d;
				color: #ffffff;
			}
			.confur-answers-table tr.cancelled-row {
				background-color: #f5f5f5;
			}
			.confur-answers-table tr.cancelled-row:hover {
				background-color: #e0e0e0;
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
			.cancel-duplicate-btn {
				background: #dc3545;
				color: #fff;
				border: none;
				padding: 4px 10px;
				border-radius: 4px;
				font-size: 11px;
				cursor: pointer;
				margin-left: 8px;
				transition: background-color 0.2s;
			}
			.cancel-duplicate-btn:hover {
				background: #c82333;
			}
			.cancel-duplicate-btn:disabled {
				background: #6c757d;
				cursor: not-allowed;
			}
			.cancel-duplicate-btn .spinner {
				display: none;
				width: 12px;
				height: 12px;
				margin-left: 4px;
			}
			.cancel-duplicate-btn.loading .spinner {
				display: inline-block;
			}
			.duplicate-indicator {
				display: inline-block;
				background: #ff9800;
				color: #fff;
				font-size: 10px;
				padding: 2px 6px;
				border-radius: 3px;
				margin-left: 6px;
				font-weight: 600;
			}
			.resend-confirmation-btn {
				background: #2271b1;
				color: #fff;
				border: none;
				padding: 4px 10px;
				border-radius: 4px;
				font-size: 11px;
				cursor: pointer;
				transition: background-color 0.2s;
			}
			.resend-confirmation-btn:hover {
				background: #135e96;
			}
			.resend-confirmation-btn:disabled {
				background: #6c757d;
				cursor: not-allowed;
			}
			.resend-confirmation-btn .spinner {
				display: none;
				width: 12px;
				height: 12px;
				margin-left: 4px;
			}
			.resend-confirmation-btn.loading .spinner {
				display: inline-block;
			}
			.actions-cell {
				white-space: nowrap;
			}
			.actions-cell .cancel-duplicate-btn {
				margin-left: 0;
				margin-right: 6px;
			}
		";
        wp_add_inline_style('wp-admin', $custom_css);

        // Inline JavaScript for cancel button
        $custom_js = "
		jQuery(document).ready(function($) {
			$('.cancel-duplicate-btn').on('click', function(e) {
				e.preventDefault();
				
				var button = $(this);
				var answerId = button.data('answer-id');
				var meetingName = button.data('meeting-name');
				var row = button.closest('tr');
				
				if (!confirm('Are you sure you want to cancel this duplicate registration for \"' + meetingName + '\"?')) {
					return;
				}
				
				button.prop('disabled', true).addClass('loading');
				button.find('.btn-text').text('Cancelling...');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'confur_cancel_duplicate',
						answer_id: answerId,
						nonce: '" . wp_create_nonce('confur_cancel_duplicate') . "'
					},
					success: function(response) {
						if (response.success) {
							// Determine the old status before updating
							var statusBadge = row.find('.status-badge');
							var oldStatus = '';
							if (statusBadge.hasClass('status-completed')) {
								oldStatus = 'completed';
							} else if (statusBadge.hasClass('status-draft')) {
								oldStatus = 'draft';
							} else if (statusBadge.hasClass('status-not-started')) {
								oldStatus = 'not-started';
							}
							
							// Update the row to show cancelled status
							row.removeClass('duplicate-row registered-row').addClass('cancelled-row');
							statusBadge
								.removeClass('status-completed status-draft status-not-started')
								.addClass('status-cancelled')
								.text('Cancelled');
							button.remove();
							row.find('.duplicate-indicator').remove();
							
							// Check if only one active (non-cancelled) registration remains for this meeting
							// Find all rows with cancel buttons for the same meeting name
							var remainingDuplicateButtons = $('.cancel-duplicate-btn').filter(function() {
								return $(this).data('meeting-name') === meetingName;
							});
							
							// If only one cancel button remains, remove it and its duplicate indicator
							if (remainingDuplicateButtons.length === 1) {
								var lastRow = remainingDuplicateButtons.closest('tr');
								remainingDuplicateButtons.remove();
								lastRow.find('.duplicate-indicator').remove();
								lastRow.removeClass('duplicate-row').addClass('registered-row');
							}
							
							// Update the statistics counters
							var cancelledStat = $('.stat-box').eq(6).find('.stat-number');
							if (cancelledStat.length) {
								cancelledStat.text(parseInt(cancelledStat.text()) + 1);
							}
							
							// Decrement the old status counter
							if (oldStatus === 'completed') {
								var completedStat = $('.stat-box').eq(3).find('.stat-number');
								if (completedStat.length) {
									completedStat.text(parseInt(completedStat.text()) - 1);
								}
							} else if (oldStatus === 'draft') {
								var draftStat = $('.stat-box').eq(4).find('.stat-number');
								if (draftStat.length) {
									draftStat.text(parseInt(draftStat.text()) - 1);
								}
							} else if (oldStatus === 'not-started') {
								var notStartedStat = $('.stat-box').eq(5).find('.stat-number');
								if (notStartedStat.length) {
									notStartedStat.text(parseInt(notStartedStat.text()) - 1);
								}
							}
							
							// Show success message
							var notice = $('<div class=\"notice notice-success is-dismissible\"><p>Registration cancelled successfully.</p></div>');
							$('.wrap h1').after(notice);
							setTimeout(function() { notice.fadeOut(); }, 3000);
						} else {
							alert('Error: ' + (response.data.message || 'Failed to cancel registration'));
							button.prop('disabled', false).removeClass('loading');
							button.find('.btn-text').text('Cancel');
						}
					},
					error: function() {
						alert('An error occurred while cancelling the registration.');
						button.prop('disabled', false).removeClass('loading');
						button.find('.btn-text').text('Cancel');
					}
				});
			});

			$('.resend-confirmation-btn').on('click', function(e) {
				e.preventDefault();
				
				var button = $(this);
				var answerId = button.data('answer-id');
				var meetingName = button.data('meeting-name');
				
				if (!confirm('Resend confirmation email for \"' + meetingName + '\"?')) {
					return;
				}
				
				button.prop('disabled', true).addClass('loading');
				button.find('.btn-text').text('Sending...');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'confur_resend_confirmation',
						answer_id: answerId,
						nonce: '" . wp_create_nonce('confur_resend_confirmation') . "'
					},
					success: function(response) {
						if (response.success) {
							// Show success message
							var notice = $('<div class=\"notice notice-success is-dismissible\"><p>' + response.data.message + '</p></div>');
							$('.wrap h1').after(notice);
							setTimeout(function() { notice.fadeOut(); }, 5000);
							
							button.prop('disabled', false).removeClass('loading');
							button.find('.btn-text').text('Resend Email');
						} else {
							alert('Error: ' + (response.data.message || 'Failed to send confirmation email'));
							button.prop('disabled', false).removeClass('loading');
							button.find('.btn-text').text('Resend Email');
						}
					},
					error: function() {
						alert('An error occurred while sending the confirmation email.');
						button.prop('disabled', false).removeClass('loading');
						button.find('.btn-text').text('Resend Email');
					}
				});
			});
		});
		";
        wp_add_inline_script('jquery', $custom_js);
    }

    /**
     * Render the admin page
     */
    public function renderAdminPage(): void
    {
        if (!current_user_can('read')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $allMeetings = $this->getAllMeetingsData();
        $stats = $this->calculateStats($allMeetings);
        $duplicates = $this->findDuplicateRegistrations($allMeetings);

        // Create a set of meeting IDs that have duplicates for easy lookup
        $duplicateMeetingIds = array_keys($duplicates);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (!empty($duplicates)): ?>
                <div class="notice notice-warning">
                    <p><strong><?php _e('Warning: Duplicate registrations detected!', 'confur'); ?></strong></p>
                    <p><?php _e('The following meetings have multiple active (non-cancelled) registrations. Use the "Cancel" button to cancel duplicate entries:', 'confur'); ?></p>
                    <ul>
                        <?php foreach ($duplicates as $meetingId => $info): ?>
                            <li><?php echo esc_html($info['name']); ?> (<?php echo esc_html($info['count']); ?> registrations)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

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
                        <th>Meeting/Answers</th>
                        <th>Status</th>
                        <th>Registration Email</th>
                        <th>Contact 1</th>
                        <th>Contact 2</th>
                        <th>Meeting Day</th>
                        <th>Meeting Time</th>
                        <th>Last Saved</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allMeetings as $meeting):
                        // Check if this meeting is part of a duplicate set (and not cancelled)
                        $isDuplicate = in_array($meeting['id'], $duplicateMeetingIds)
                                       && $meeting['is_registered']
                                       && $meeting['status_class'] !== 'cancelled';
                        $rowClass = $isDuplicate ? 'duplicate-row' : $meeting['row_class'];
                        ?>
                        <tr class="<?php echo esc_attr($rowClass); ?>">
                            <td class="answer-name">
                                <?php if ($meeting['is_registered']): ?>
                                    <a href="<?php echo esc_url($meeting['edit_url']); ?>">
                                        <?php echo esc_html($meeting['name']); ?>
                                    </a>
                                    <?php if ($isDuplicate): ?>
                                        <span class="duplicate-indicator">DUPLICATE</span>
                                    <?php endif; ?>
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
                            <td class="actions-cell">
                                <?php if ($meeting['is_registered'] && !empty($meeting['answer_id'])): ?>
                                    <?php if ($isDuplicate): ?>
                                        <button type="button"
                                                class="cancel-duplicate-btn"
                                                data-answer-id="<?php echo esc_attr($meeting['answer_id']); ?>"
                                                data-meeting-name="<?php echo esc_attr($meeting['name']); ?>">
                                            <span class="btn-text">Cancel</span>
                                            <span class="spinner"></span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($meeting['status_class'] !== 'cancelled'): ?>
                                        <button type="button"
                                                class="resend-confirmation-btn"
                                                data-answer-id="<?php echo esc_attr($meeting['answer_id']); ?>"
                                                data-meeting-name="<?php echo esc_attr($meeting['name']); ?>">
                                            <span class="btn-text">Resend Email</span>
                                            <span class="spinner"></span>
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
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

        // Create lookup for registered meetings (supports multiple registrations per meeting)
        $registeredLookup = [];
        foreach ($registered as $item) {
            // Get meeting ID - handle if it's an object, array, or scalar
            $meetingId = $item['meetingId'];
            if (is_object($meetingId)) {
                $meetingId = $meetingId->ID;
            } elseif (is_array($meetingId)) {
                $meetingId = $meetingId['ID'] ?? null;
            }

            // Only add if we have a valid ID
            if ($meetingId && is_numeric($meetingId)) {
                $meetingIdInt = (int)$meetingId;
                // Store as array to support multiple registrations per meeting
                if (!isset($registeredLookup[$meetingIdInt])) {
                    $registeredLookup[$meetingIdInt] = [];
                }
                $registeredLookup[$meetingIdInt][] = $item;
            }
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
                // Registered meeting(s) - loop through all registrations for this meeting ID
                foreach ($registeredLookup[$meetingId] as $item) {
                    $meetingName = get_the_title($item['meetingId']);
                    $updated = trim($item['updated']);
                    $status = isset($item['status']) && !empty($item['status']) ? $item['status'] : 'Not Started';

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
                            'answer_id' => $item['answersId'],  // Added answer ID for cancel button
                            'name' => $meetingName,
                            'is_registered' => true,
                            'edit_url' => get_edit_post_link($item['answersId']),
                            'meeting_url' => $meeting['url'],
                            'status_label' => $statusInfo['label'],
                            'status_class' => $statusInfo['class'],
                            'email_html' => $emailHtml,
                            'contact1_html' => $contact1Html,
                            'contact2_html' => $contact2Html,
                            'day' => $dayName,
                            'time' => $meeting['time'],
                            'last_saved' => $updated,
                            'row_class' => $statusInfo['class'] === 'cancelled' ? 'cancelled-row' : 'registered-row'
                    ];
                }
            } else {
                // Unregistered meeting
                $allMeetings[] = [
                        'id' => $meetingId,
                        'answer_id' => null,
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
                'total' => 0,
                'registered' => 0,
                'unregistered' => 0,
                'completed' => 0,
                'draft' => 0,
                'not_started' => 0,
                'cancelled' => 0
        ];

        // Track distinct meeting IDs
        $distinctMeetingIds = [];
        $registeredMeetingIds = [];

        foreach ($allMeetings as $meeting) {
            $meetingId = $meeting['id'];

            // Track all distinct meetings
            $distinctMeetingIds[$meetingId] = true;

            if ($meeting['is_registered']) {
                // Track distinct registered meetings
                $registeredMeetingIds[$meetingId] = true;

                // Count statuses (these can be multiple per meeting)
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
            }
        }

        // Set distinct counts
        $stats['total'] = count($distinctMeetingIds);
        $stats['registered'] = count($registeredMeetingIds);
        $stats['unregistered'] = $stats['total'] - $stats['registered'];

        return $stats;
    }

    /**
     * Find meetings with duplicate active (non-cancelled) registrations
     *
     * @param array $allMeetings Meeting data
     * @return array Duplicates with meeting ID as key and info (name, count) as value
     */
    private function findDuplicateRegistrations(array $allMeetings): array
    {
        $activeCounts = [];

        foreach ($allMeetings as $meeting) {
            // Only count registered meetings that are not cancelled
            if ($meeting['is_registered'] && $meeting['status_class'] !== 'cancelled') {
                $meetingId = $meeting['id'];
                if (!isset($activeCounts[$meetingId])) {
                    $activeCounts[$meetingId] = [
                            'name' => $meeting['name'],
                            'count' => 0
                    ];
                }
                $activeCounts[$meetingId]['count']++;
            }
        }

        // Filter to only return those with more than one active registration
        return array_filter($activeCounts, function($info) {
            return $info['count'] > 1;
        });
    }
}