<?php

namespace Confur\Admin;

use Confur\Config\Constants;
use Confur\Repositories\AnswerRepository;

/**
 * Dashboard widget showing committees and questions updated in last 24 hours with groups
 */
class ConfurHeadsUp
{
	private AnswerRepository $answerRepository;

	public function __construct()
	{
		$this->answerRepository = new AnswerRepository();
	}

	/**
	 * Initialize the dashboard widget
	 */
	public function init(): void
	{
		// Only load in admin area
		if (!is_admin()) {
			return;
		}

		add_action('wp_dashboard_setup', [$this, 'registerWidget']);
		add_action('wp_ajax_confur_refresh_headsup', [$this, 'ajaxRefreshWidget']);
	}

	/**
	 * Register the dashboard widget
	 */
	public function registerWidget(): void
	{
		wp_add_dashboard_widget(
			'confur_headsup_widget',
			'Questions for Conference - Recent Activity (24hrs)',
			[$this, 'renderWidget']
		);
	}

	/**
	 * AJAX handler to refresh widget content
	 */
	public function ajaxRefreshWidget(): void
	{
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'confur_headsup_refresh')) {
			wp_send_json_error(['message' => 'Invalid security token']);
			return;
		}

		// Get the widget content
		ob_start();
		$this->renderWidgetContent();
		$content = ob_get_clean();

		wp_send_json_success([
			'content' => $content,
			'updated' => current_time('mysql')
		]);
	}

	/**
	 * Render the widget wrapper with AJAX functionality
	 */
	public function renderWidget(): void
	{
		// Add inline CSS and JS
		$this->addWidgetStyles();
		$this->addWidgetScript();

		echo '<div id="confur-headsup-container">';
		echo '<div id="confur-headsup-content">';
		$this->renderWidgetContent();
		echo '</div>';

		// Footer with last updated and refresh button
		echo '<div class="confur-headsup-footer">';
		echo '<span class="confur-last-updated-time">Last updated: <span id="confur-update-time">' . current_time('M j, Y g:i A') . '</span></span>';
		echo '<button type="button" id="confur-refresh-btn" class="button button-small">';
		echo '<span class="dashicons dashicons-update"></span> Refresh';
		echo '</button>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the main widget content (used both for initial load and AJAX refresh)
	 */
	private function renderWidgetContent(): void
	{
		// Get recent updates
		$recentUpdates = $this->getRecentUpdates();

		// Display the list
		$this->renderUpdatesList($recentUpdates);
	}

	/**
	 * Add widget-specific styles
	 */
	private function addWidgetStyles(): void
	{
		echo '<style>
            #confur-headsup-container {
                position: relative;
            }
            #confur-headsup-content.loading {
                opacity: 0.5;
                pointer-events: none;
            }
            .confur-headsup-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 15px;
                padding-top: 10px;
                border-top: 1px solid #e0e0e0;
            }
            .confur-last-updated-time {
                font-size: 12px;
                color: #646970;
            }
            #confur-refresh-btn {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            #confur-refresh-btn .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            #confur-refresh-btn.rotating .dashicons {
                animation: confur-spin 1s linear infinite;
            }
            @keyframes confur-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .confur-updates-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .confur-committee-item {
                margin-bottom: 15px;
                background: #f6f7f7;
                border-radius: 4px;
                padding: 10px;
            }
            .confur-committee-title {
                font-weight: 600;
                font-size: 13px;
                color: #2271b1;
                margin-bottom: 8px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 5px;
                user-select: none;
            }
            .confur-committee-title:hover {
                color: #135e96;
            }
            .confur-committee-toggle {
                display: inline-block;
                transition: transform 0.2s;
            }
            .confur-committee-item.collapsed .confur-committee-toggle {
                transform: rotate(-90deg);
            }
            .confur-committee-item.collapsed .confur-questions-list {
                display: none;
            }
            .confur-questions-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .confur-question-item {
                margin-bottom: 10px;
                padding-left: 15px;
                border-left: 3px solid #2271b1;
            }
            .confur-question-item:last-child {
                margin-bottom: 0;
            }
            .confur-question-title {
                font-weight: 600;
                font-size: 12px;
                color: #1d2327;
                margin-bottom: 5px;
            }
            .confur-groups-list {
                list-style: none;
                margin: 0;
                padding: 0;
                padding-left: 15px;
            }
            .confur-group-item {
                font-size: 12px;
                padding: 4px 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .confur-group-link {
                color: #2271b1;
                text-decoration: none;
                flex: 1;
            }
            .confur-group-link:hover {
                text-decoration: underline;
            }
            .confur-group-time {
                color: #646970;
                font-size: 11px;
                margin-left: 10px;
                white-space: nowrap;
            }
            .confur-no-data {
                padding: 20px;
                text-align: center;
                color: #646970;
                font-style: italic;
            }
            .confur-recent-badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 8px;
                font-size: 10px;
                font-weight: 600;
                background: #ff9800;
                color: #fff;
                margin-left: 5px;
            }
        </style>';
	}

	/**
	 * Add widget JavaScript for AJAX refresh
	 */
	private function addWidgetScript(): void
	{
		$nonce = wp_create_nonce('confur_headsup_refresh');
		$ajaxUrl = admin_url('admin-ajax.php');

		echo '<script>
        jQuery(document).ready(function($) {
            // Load saved collapse state from user meta
            function loadCollapseState() {
                var state = localStorage.getItem("confur_headsup_collapse_" + ' . get_current_user_id() . ');
                if (state) {
                    try {
                        var collapsed = JSON.parse(state);
                        collapsed.forEach(function(committeeNum) {
                            $(".confur-committee-item[data-committee=\"" + committeeNum + "\"]").addClass("collapsed");
                        });
                    } catch(e) {
                        console.error("Failed to parse collapse state", e);
                    }
                }
            }
            
            // Save collapse state
            function saveCollapseState() {
                var collapsed = [];
                $(".confur-committee-item.collapsed").each(function() {
                    collapsed.push($(this).data("committee"));
                });
                localStorage.setItem("confur_headsup_collapse_" + ' . get_current_user_id() . ', JSON.stringify(collapsed));
            }
            
            // Toggle committee collapse
            $(document).on("click", ".confur-committee-title", function(e) {
                e.preventDefault();
                $(this).closest(".confur-committee-item").toggleClass("collapsed");
                saveCollapseState();
            });
            
            // Load state on page load
            loadCollapseState();
            
            // Handle refresh button
            $("#confur-refresh-btn").on("click", function() {
                var $btn = $(this);
                var $content = $("#confur-headsup-content");
                
                // Prevent double clicks
                if ($btn.prop("disabled")) {
                    return;
                }
                
                // Show loading state
                $btn.prop("disabled", true).addClass("rotating");
                $content.addClass("loading");
                
                $.ajax({
                    url: "' . $ajaxUrl . '",
                    type: "POST",
                    data: {
                        action: "confur_refresh_headsup",
                        nonce: "' . $nonce . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            $content.html(response.data.content);
                            
                            // Restore collapse state after refresh
                            loadCollapseState();
                            
                            // Update timestamp
                            var date = new Date(response.data.updated);
                            var formatted = date.toLocaleString("en-US", {
                                month: "short",
                                day: "numeric", 
                                year: "numeric",
                                hour: "numeric",
                                minute: "2-digit",
                                hour12: true
                            });
                            $("#confur-update-time").text(formatted);
                        } else {
                            alert("Failed to refresh: " + (response.data.message || "Unknown error"));
                        }
                    },
                    error: function() {
                        alert("Failed to refresh widget. Please try again.");
                    },
                    complete: function() {
                        $btn.prop("disabled", false).removeClass("rotating");
                        $content.removeClass("loading");
                    }
                });
            });
        });
        </script>';
	}

	/**
	 * Get recent updates from last 24 hours
	 */
	private function getRecentUpdates(): array
	{
		$groupAnswers = $this->answerRepository->getGroupAnswers();
		$updates = [];
		$now = current_time('timestamp');
		$twentyFourHoursAgo = $now - (24 * 60 * 60);

		foreach ($groupAnswers as $fieldName => $answers) {
			// Parse field name (e.g., "c1_a1" -> Committee 1, Answer 1)
			if (preg_match('/^c(\d+)_a(\d+)$/', $fieldName, $matches)) {
				$committeeNum = (int)$matches[1];
				$questionNum = (int)$matches[2];

				foreach ($answers as $answer) {
					// Check if updated in last 24 hours
					if (!empty($answer['updated'])) {
						$updateTime = strtotime($answer['updated']);

						if ($updateTime !== false && $updateTime >= $twentyFourHoursAgo) {
							// Build the link to the Results page with anchor
							$resultsPageUrl = admin_url('admin.php?page=confur-reporting#c' . $committeeNum . '_a' . $questionNum);

							if (!isset($updates[$committeeNum])) {
								$updates[$committeeNum] = [];
							}

							if (!isset($updates[$committeeNum][$questionNum])) {
								$updates[$committeeNum][$questionNum] = [];
							}

							$updates[$committeeNum][$questionNum][] = [
								'group_name' => $answer['meetingName'],
								'updated' => $updateTime,
								'url' => $resultsPageUrl
							];
						}
					}
				}
			}
		}

		// Sort committees by number
		ksort($updates);

		// Sort questions within each committee and groups by most recent first
		foreach ($updates as $committeeNum => &$questions) {
			ksort($questions);

			foreach ($questions as &$groups) {
				usort($groups, function($a, $b) {
					return $b['updated'] - $a['updated'];
				});
			}
		}

		return $updates;
	}

	/**
	 * Render the updates list
	 */
	private function renderUpdatesList(array $updates): void
	{
		if (empty($updates)) {
			echo '<div class="confur-no-data">';
			echo '<p>No updates in the last 24 hours</p>';
			echo '</div>';
			return;
		}

		echo '<ul class="confur-updates-list">';

		foreach ($updates as $committeeNum => $questions) {
			echo '<li class="confur-committee-item" data-committee="' . esc_attr($committeeNum) . '">';
			echo '<div class="confur-committee-title">';
			echo '<span class="confur-committee-toggle">▼</span>';
			echo 'Committee ' . esc_html($committeeNum);
			echo '</div>';

			echo '<ul class="confur-questions-list">';

			foreach ($questions as $questionNum => $groups) {
				echo '<li class="confur-question-item">';
				echo '<div class="confur-question-title">Question ' . esc_html($questionNum);
				echo '<span class="confur-recent-badge">UPDATED</span>';
				echo '</div>';

				echo '<ul class="confur-groups-list">';

				foreach ($groups as $group) {
					$timeAgo = human_time_diff($group['updated'], current_time('timestamp'));

					echo '<li class="confur-group-item">';
					echo '<a href="' . esc_url($group['url']) . '" class="confur-group-link">';
					echo esc_html($group['group_name']);
					echo '</a>';
					echo '<span class="confur-group-time">' . esc_html($timeAgo) . ' ago</span>';
					echo '</li>';
				}

				echo '</ul>'; // .confur-groups-list
				echo '</li>'; // .confur-question-item
			}

			echo '</ul>'; // .confur-questions-list
			echo '</li>'; // .confur-committee-item
		}

		echo '</ul>'; // .confur-updates-list
	}
}