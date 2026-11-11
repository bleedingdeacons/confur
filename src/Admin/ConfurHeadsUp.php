<?php

namespace Confur\Admin;

use Confur\Config\Constants;
use Confur\Domain\AnswerKey;
use Confur\Repositories\AnswerRepository;
use Confur\Services\AdminAssets;

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
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
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
        // CSS and JS are enqueued from assets/admin/ (enqueueAssets).
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
     * admin_enqueue_scripts: the widget's CSS and JS, on the dashboard only.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueueAssets($hook): void
    {
        if ($hook !== 'index.php') {
            return;
        }

        AdminAssets::style('dashboard');
        AdminAssets::script('dashboard', ['jquery'], 'confurDashboard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('confur_headsup_refresh'),
            // Keys the widget's remembered collapse state to the user.
            'userId' => get_current_user_id(),
        ]);
    }

    /**
     * Get recent updates from last 24 hours
     *
     * @return array<int, array<int, list<array<string, mixed>>>> Committee => question => updates
     */
    private function getRecentUpdates(): array
    {
        $groupAnswers = $this->answerRepository->getGroupAnswers();
        $updates = [];
        $now = current_time('timestamp');
        $twentyFourHoursAgo = $now - (24 * 60 * 60);

        foreach ($groupAnswers as $fieldName => $answers) {
            $key = AnswerKey::parse((string) $fieldName);

            if ($key !== null) {
                $committeeNum = $key->committee;
                $questionNum = $key->question;

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
                usort($groups, function ($a, $b) {
                    return $b['updated'] - $a['updated'];
                });
            }
        }

        return $updates;
    }

    /**
     * Render the updates list
     *
     * @param array<int, array<int, list<array<string, mixed>>>> $updates
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
            echo '<li class="confur-committee-item" data-committee="' . esc_attr((string) $committeeNum) . '">';
            echo '<div class="confur-committee-title">';
            echo '<span class="confur-committee-toggle">▼</span>';
            echo 'Committee ' . esc_html((string) $committeeNum);
            echo '</div>';

            echo '<ul class="confur-questions-list">';

            foreach ($questions as $questionNum => $groups) {
                echo '<li class="confur-question-item">';
                echo '<div class="confur-question-title">Question ' . esc_html((string) $questionNum);
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
