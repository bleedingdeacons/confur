<?php

namespace Confur\Admin;

use Confur\Application\DuplicateFinder;
use Confur\Application\RegisterGroup;
use Confur\Config\Constants;
use Confur\Domain\Answer;
use Confur\Repositories\AnswerRepository;
use Confur\Services\AdminAssets;
use Confur\Services\Mailer;
use Confur\Storage\AnswerStore;
use Confur\Utils\HtmlHelper;
use Unity\Contacts\Interfaces\Contact;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\GroupRepository;

/**
 * Admin page for displaying answer submissions
 */
class StatusAdminPage
{
    private AnswerStore $store;
    private DuplicateFinder $duplicates;

    /**
     * The groups come from Unity: TSML for Unity binds GroupRepository in
     * Unity's container. It is resolved on first use, not here, because
     * TSML for Unity may bind it after Confur has booted. Tests pass a
     * repository directly.
     */
    public function __construct(
        private ?Container $container = null,
        private ?GroupRepository $groups = null,
    ) {
        $this->store = new AnswerStore();
        $this->duplicates = new DuplicateFinder($this->store);
    }

    private function groups(): GroupRepository
    {
        if ($this->groups === null) {
            $groups = $this->container?->get(GroupRepository::class);

            if (!$groups instanceof GroupRepository) {
                throw new \RuntimeException('Unity has no GroupRepository: is TSML for Unity active?');
            }

            $this->groups = $groups;
        }

        return $this->groups;
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

        // Add screen options
        add_action('load-questions-for-conference_page_confur-answer-submissions', [$this, 'addScreenOptions']);

        // Handle saving screen options via AJAX
        add_action('wp_ajax_confur_save_screen_option', [$this, 'handleSaveScreenOption']);
    }

    /**
     * Add screen options for the status page
     */
    public function addScreenOptions(): void
    {
        add_filter('screen_settings', [$this, 'renderScreenOptions'], 10, 2);
    }

    /**
     * Handle AJAX save of screen option
     */
    public function handleSaveScreenOption(): void
    {
        check_ajax_referer('confur_screen_options', 'nonce');

        $show_cancellations = isset($_POST['show_cancellations']) && $_POST['show_cancellations'] === '1' ? 1 : 0;
        update_user_meta(get_current_user_id(), 'confur_show_cancellations', $show_cancellations);

        wp_send_json_success(['saved' => true]);
    }

    /**
     * Render the screen options
     */
    public function renderScreenOptions(string $settings, \WP_Screen $screen): string
    {
        if ($screen->id !== 'questions-for-conference_page_confur-answer-submissions') {
            return $settings;
        }

        $show_cancellations = get_user_meta(get_current_user_id(), 'confur_show_cancellations', true);
        // Default to showing cancellations if not set
        if ($show_cancellations === '') {
            $show_cancellations = 1;
        }

        // The toggle's behaviour is in assets/admin/status.js.
        $settings .= '<fieldset class="confur-screen-options" style="margin: 10px 0;">';
        $settings .= '<legend style="font-weight: bold;">Confur Options</legend>';
        $settings .= '<label style="display: block; margin: 5px 0;">';
        $settings .= '<input type="checkbox" id="confur_show_cancellations" value="1" ' . checked($show_cancellations, 1, false) . ' />';
        $settings .= ' Show Cancellations';
        $settings .= '</label>';
        $settings .= '</fieldset>';

        return $settings;
    }

    /**
     * Check if cancellations should be shown
     */
    private function shouldShowCancellations(): bool
    {
        $show_cancellations = get_user_meta(get_current_user_id(), 'confur_show_cancellations', true);
        // Default to showing cancellations if not set
        if ($show_cancellations === '') {
            return true;
        }
        return (bool) $show_cancellations;
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
            'edit_answers',                 // Capability — see renderAdminPage()
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
        }

        // Check permissions - user must be able to edit answers
        if (!current_user_can('edit_answers')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Get and validate answer ID
        $answerId = isset($_POST['answer_id']) ? intval($_POST['answer_id']) : 0;
        if ($answerId <= 0) {
            wp_send_json_error(['message' => 'Invalid answer ID']);
        }

        // Verify the post exists and is an answer type
        $post = get_post($answerId);
        if (!$post || $post->post_type !== Constants::ANSWER_CUSTOM_TYPE) {
            wp_send_json_error(['message' => 'Answer not found']);
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
        }

        // Check permissions - user must be able to edit answers
        if (!current_user_can('edit_answers')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Get and validate answer ID
        $answerId = isset($_POST['answer_id']) ? intval($_POST['answer_id']) : 0;
        if ($answerId <= 0) {
            wp_send_json_error(['message' => 'Invalid answer ID']);
        }

        // Verify the post exists and is an answer type
        $post = get_post($answerId);
        if (!$post || $post->post_type !== Constants::ANSWER_CUSTOM_TYPE) {
            wp_send_json_error(['message' => 'Answer not found']);
        }

        // Get the email, group(s), and answer URL
        $email = get_field(Constants::EMAIL_FIELD, $answerId);
        // Normalised: ACF can return the group as a post object or array,
        // which get_the_title() would not take.
        $groupId = AnswerStore::normalizePostId(get_field(Constants::GROUP_FIELD, $answerId));
        $fellowGroupId = AnswerStore::normalizePostId(get_field(Constants::FELLOW_GROUP_FIELD, $answerId));

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => 'No valid email address found for this registration']);
        }

        if ($groupId === null) {
            wp_send_json_error(['message' => 'No group associated with this registration']);
        }

        $groupName = RegisterGroup::groupName($groupId, $fellowGroupId);

        // Get the answer URL. get_permalink() is string|false; an unresolvable
        // one means there is no answer page to point the recipient at, so
        // stop rather than send a confirmation with a blank link.
        $answerUrl = get_permalink($answerId);
        if ($answerUrl === false) {
            // No return needed: wp_send_json_error() is declared never.
            wp_send_json_error(['message' => 'Could not resolve the answer URL']);
        }

        // Send the confirmation email
        $result = Mailer::fromOptions()->sendConfirmation($email, $groupName, $answerUrl);

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
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueueAdminAssets($hook): void
    {
        // Only load on our admin page
        if ($hook !== 'questions-for-conference_page_confur-answer-submissions') {
            return;
        }

        AdminAssets::style('status');
        AdminAssets::script('status', ['jquery'], 'confurStatus', [
            'cancelNonce' => wp_create_nonce('confur_cancel_duplicate'),
            'resendNonce' => wp_create_nonce('confur_resend_confirmation'),
            'screenOptionsNonce' => wp_create_nonce('confur_screen_options'),
        ]);
    }

    /**
     * Render the admin page
     */
    public function renderAdminPage(): void
    {
        // 'edit_answers', not 'read'. This table carries every group's
        // contact names, telephone numbers and registration email, which is
        // not Subscriber-level information — and 'read' is held by every
        // logged-in user. The rest of this class already gates on
        // 'edit_answers'; this path was the outlier.
        if (!current_user_can('edit_answers')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'confur'));
        }

        // One read of the registrations, shared by the table and the
        // duplicate warning.
        $answers = $this->store->all();

        $rows = $this->getAllGroupsData(AnswerRepository::registeredRows($answers));
        $stats = $this->calculateStats($rows);
        $duplicates = $this->duplicateGroups($answers);

        // Filter out cancelled items if screen option is disabled
        if (!$this->shouldShowCancellations()) {
            $rows = array_filter($rows, fn (array $row): bool => $row['status_class'] !== 'cancelled');
        }

        Template::render('admin/status', [
            'rows' => $rows,
            'stats' => $stats,
            'duplicates' => $duplicates,
            'duplicateAnswerIds' => array_merge([], ...array_column($duplicates, 'answerIds')),
        ]);
    }

    /**
     * Sets of duplicate registrations, for the warning and the Cancel buttons.
     *
     * @param list<Answer> $answers
     * @return list<array{name: string, count: int, answerIds: list<int>}>
     */
    private function duplicateGroups(array $answers): array
    {
        $groups = [];

        foreach ($this->duplicates->groups($answers) as $group) {
            $first = $group[0];

            $groups[] = [
                'name' => sprintf(
                    '%s (%s)',
                    $first->groupId !== null ? RegisterGroup::groupName($first->groupId, $first->fellowGroupId) : '',
                    strtolower(trim($first->email ?? ''))
                ),
                'count' => count($group),
                'answerIds' => array_map(static fn (Answer $a): int => $a->postId, $group),
            ];
        }

        return $groups;
    }

    /**
     * One row per Unity group, or one per registration for a group that has
     * registered (a pair appears once, under its first group).
     *
     * @param array<int, array<string, mixed>> $registered AnswerRepository::registeredRows()
     * @return array<int, array<string, mixed>> Rows for the template
     */
    private function getAllGroupsData(array $registered): array
    {
        $rows = [];

        // Registrations by group; a pair is listed under both its groups. The
        // ids are already plain ints (AnswerStore normalises ACF's shapes).
        $registeredLookup = [];
        foreach ($registered as $item) {
            foreach ([$item['groupId'], $item['fellowGroupId'] ?? null] as $id) {
                if (is_int($id)) {
                    $registeredLookup[$id][] = $item;
                }
            }
        }

        // A pair would otherwise show under each of its groups.
        $displayedAnswerIds = [];

        foreach ($this->groups()->findAll() as $group) {
            $groupId = $group->getId();
            $contacts = $group->getContacts();

            $contact1Html = isset($contacts[0]) ? $this->contactTelephoneLink($contacts[0]) : '-';
            $contact2Html = isset($contacts[1]) ? $this->contactTelephoneLink($contacts[1]) : '-';

            // A group can run more than one meeting; list them all.
            $days = [];
            $times = [];
            foreach ($group->getMeetings() as $meeting) {
                $days[] = $this->getDayName($meeting->getDay());
                $times[] = $meeting->getTime();
            }
            $day = $days === [] ? '-' : implode(', ', $days);
            $time = $times === [] ? '-' : implode(', ', $times);

            if (!isset($registeredLookup[$groupId])) {
                $rows[] = [
                    'id' => $groupId,
                    'answer_id' => null,
                    'name' => $group->getTitle(),
                    'is_registered' => false,
                    'edit_url' => '',
                    'group_url' => $group->getLink(),
                    'status_label' => 'Unregistered',
                    'status_class' => 'unregistered',
                    'email_html' => '-',
                    'contact1_html' => $contact1Html,
                    'contact2_html' => $contact2Html,
                    'day' => $day,
                    'time' => $time,
                    'last_saved' => '-',
                    'row_class' => 'unregistered-row',
                ];
                continue;
            }

            foreach ($registeredLookup[$groupId] as $item) {
                if (isset($displayedAnswerIds[$item['answersId']])) {
                    continue;
                }
                $displayedAnswerIds[$item['answersId']] = true;

                $updated = trim((string) $item['updated']);
                $status = !empty($item['status']) ? (string) $item['status'] : 'Not Started';
                $statusInfo = $this->getStatusInfo($status);

                $emailHtml = !empty($item['email'])
                    ? HtmlHelper::createLink(
                        HtmlHelper::createEmailToAddress($item['email'], 'Questions for Conference'),
                        '',
                        $item['email']
                    )
                    : '-';

                $rows[] = [
                    'id' => $groupId,
                    'answer_id' => $item['answersId'],
                    'name' => RegisterGroup::groupName((int) $item['groupId'], $item['fellowGroupId'] ?? null),
                    'is_registered' => true,
                    'edit_url' => get_edit_post_link($item['answersId']),
                    'group_url' => $group->getLink(),
                    'status_label' => $statusInfo['label'],
                    'status_class' => $statusInfo['class'],
                    'email_html' => $emailHtml,
                    'contact1_html' => $contact1Html,
                    'contact2_html' => $contact2Html,
                    'day' => $day,
                    'time' => $time,
                    'last_saved' => $updated !== '' ? $updated : 'Not Started',
                    'row_class' => $statusInfo['class'] === 'cancelled' ? 'cancelled-row' : 'registered-row',
                ];
            }
        }

        // Registered first, then by name.
        usort($rows, static function (array $a, array $b): int {
            if ($a['is_registered'] !== $b['is_registered']) {
                return $a['is_registered'] ? -1 : 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $rows;
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

        // Duck-typed on purpose — anything exposing get_weekday() is accepted,
        // not just WP_Locale, and there is a test pinning that. Routed through
        // is_callable() rather than method_exists() because the latter narrows
        // its argument to class-string|object, which has no methods to call, so
        // the call could not be checked at all.
        $getWeekday = isset($wp_locale) && is_object($wp_locale)
            ? [$wp_locale, 'get_weekday']
            : null;

        if (is_callable($getWeekday)) {
            return (string) $getWeekday($dayNumber);
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
     * @return string Rendered HTML
     */
    private function contactTelephoneLink(Contact $contact): string
    {
        return esc_html($contact->getName()) . ' ' . HtmlHelper::createLink(
            HtmlHelper::createPhoneToAddress($contact->getPhone()),
            '',
            $contact->getPhone()
        );
    }

    /**
     * Get status display information
     *
     * @param string $status Status value
     * @return array<string, string> Status info with label and class
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
     * @param array<int, array<string, mixed>> $rows Group rows
     * @return array<string, mixed> Statistics
     */
    private function calculateStats(array $rows): array
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

        // Track distinct group IDs
        $distinctGroupIds = [];
        $registeredGroupIds = [];

        foreach ($rows as $row) {
            $groupId = $row['id'];

            // Track all distinct groups
            $distinctGroupIds[$groupId] = true;

            if ($row['is_registered']) {
                // Track distinct registered groups
                $registeredGroupIds[$groupId] = true;

                // Count statuses (these can be multiple per group)
                switch ($row['status_class']) {
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
        $stats['total'] = count($distinctGroupIds);
        $stats['registered'] = count($registeredGroupIds);
        $stats['unregistered'] = $stats['total'] - $stats['registered'];

        return $stats;
    }
}
