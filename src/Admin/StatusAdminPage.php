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
use Confur\Repositories\MeetingRepository;
use Confur\Utils\HtmlHelper;

/**
 * Admin page for displaying answer submissions
 */
class StatusAdminPage
{
    private AnswerStore $store;
    private MeetingRepository $meetingRepository;
    private DuplicateFinder $duplicates;

    public function __construct()
    {
        $this->store = new AnswerStore();
        $this->meetingRepository = new MeetingRepository();
        $this->duplicates = new DuplicateFinder($this->store);
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

        // Get the email, meeting info, and answer URL
        $email = get_field(Constants::EMAIL_FIELD, $answerId);
        // Normalised: ACF can return the meeting as a post object or array,
        // which get_the_title() would not take.
        $meetingId = AnswerStore::normalizePostId(get_field(Constants::MEETING_FIELD, $answerId));
        $fellowMeetingId = AnswerStore::normalizePostId(get_field(Constants::FELLOW_MEETING_FIELD, $answerId));

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => 'No valid email address found for this registration']);
        }

        if ($meetingId === null) {
            wp_send_json_error(['message' => 'No meeting associated with this registration']);
        }

        $meetingName = RegisterGroup::meetingName($meetingId, $fellowMeetingId);

        // Get the answer URL. get_permalink() is string|false; an unresolvable
        // one means there is no answer page to point the recipient at, so
        // stop rather than send a confirmation with a blank link.
        $answerUrl = get_permalink($answerId);
        if ($answerUrl === false) {
            // No return needed: wp_send_json_error() is declared never.
            wp_send_json_error(['message' => 'Could not resolve the answer URL']);
        }

        // Send the confirmation email
        $result = Mailer::fromOptions()->sendConfirmation($email, $meetingName, $answerUrl);

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
        // 'edit_answers', not 'read'. This table carries every meeting's
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

        $allMeetings = $this->getAllMeetingsData(AnswerRepository::registeredRows($answers));
        $stats = $this->calculateStats($allMeetings);
        $duplicates = $this->duplicateGroups($answers);

        // Filter out cancelled items if screen option is disabled
        if (!$this->shouldShowCancellations()) {
            $allMeetings = array_filter($allMeetings, fn (array $meeting): bool => $meeting['status_class'] !== 'cancelled');
        }

        Template::render('admin/status', [
            'allMeetings' => $allMeetings,
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
                    $first->meetingId !== null ? RegisterGroup::meetingName($first->meetingId, $first->fellowMeetingId) : '',
                    strtolower(trim($first->email ?? ''))
                ),
                'count' => count($group),
                'answerIds' => array_map(static fn (Answer $a): int => $a->postId, $group),
            ];
        }

        return $groups;
    }

    /**
     * Get all meetings data (registered and unregistered)
     *
     * @param array<int, array<string, mixed>> $registered AnswerRepository::registeredRows()
     * @return array<int, array<string, mixed>> Meeting data
     */
    private function getAllMeetingsData(array $registered): array
    {
        $allMeetings = [];

        // Get all meetings from repository
        $meetings = $this->meetingRepository->getMeetings();

        // Lookup of registrations by meeting; a paired registration is listed
        // under both its meetings. The ids are already plain ints (AnswerStore
        // normalises ACF's object/array shapes when it loads them).
        $registeredLookup = [];
        foreach ($registered as $item) {
            foreach ([$item['meetingId'], $item['fellowMeetingId'] ?? null] as $id) {
                if (is_int($id)) {
                    $registeredLookup[$id][] = $item;
                }
            }
        }

        // Track which answer IDs we've already displayed to avoid duplicates
        $displayedAnswerIds = [];

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
                    // Skip if we've already displayed this answer (for paired registrations)
                    if (isset($displayedAnswerIds[$item['answersId']])) {
                        continue;
                    }
                    $displayedAnswerIds[$item['answersId']] = true;

                    // Build meeting name - include fellow meeting if present
                    $meetingName = get_the_title($item['meetingId']);
                    if (!empty($item['fellowMeetingId'])) {
                        $fellowMeetingName = get_the_title($item['fellowMeetingId']);
                        if (!empty($fellowMeetingName)) {
                            $meetingName .= ' and ' . $fellowMeetingName;
                        }
                    }

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
        usort($allMeetings, function ($a, $b) {
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
     * @param array<string, mixed> $contact Contact data
     * @return string Rendered HTML
     */
    private function contactTelephoneLink(array $contact): string
    {
        return esc_html($contact['name']) . ' ' . HtmlHelper::createLink(
            HtmlHelper::createPhoneToAddress($contact['phone']),
            '',
            $contact['phone']
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
     * @param array<int, array<string, mixed>> $allMeetings Meeting data
     * @return array<string, mixed> Statistics
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
}
