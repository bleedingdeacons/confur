<?php

namespace Confur\Admin;

use Confur\Config\Constants;
use Confur\Domain\AnswerKey;
use Confur\Domain\QuestionSet;
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

        $content = $this->renderWidgetContent();

        wp_send_json_success([
            'content' => $content,
            'updated' => current_time('mysql')
        ]);
    }

    /**
     * Render the widget. Its CSS and JS are enqueued separately (enqueueAssets).
     */
    public function renderWidget(): void
    {
        Template::render('admin/dashboard-widget', [
            'updatesHtml' => $this->renderWidgetContent(),
            'lastUpdated' => current_time('M j, Y g:i A'),
        ]);
    }

    /**
     * The list of recent updates, as HTML: used on load and for the AJAX refresh.
     */
    private function renderWidgetContent(): string
    {
        $questions = QuestionSet::current();
        $updates = $this->getRecentUpdates();
        $labels = [];

        foreach (array_keys($updates) as $committee) {
            $labels[$committee] = $questions->committeeLabel($committee);
        }

        return Template::capture('admin/dashboard-updates', [
            'updates' => $updates,
            'committeeLabels' => $labels,
            'now' => current_time('timestamp'),
        ]);
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
}
