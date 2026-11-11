<?php

namespace Confur\Admin;

use Confur\Application\RegisterGroup;
use Confur\Config\Constants;
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

        $allMeetings = $this->getAllMeetingsData();
        $stats = $this->calculateStats($allMeetings);
        $duplicates = $this->findDuplicateRegistrations($allMeetings);

        // Filter out cancelled items if screen option is disabled
        $showCancellations = $this->shouldShowCancellations();
        if (!$showCancellations) {
            $allMeetings = array_filter($allMeetings, function ($meeting) {
                return $meeting['status_class'] !== 'cancelled';
            });
        }

        // Create a set of meeting IDs that have duplicates for easy lookup
        $duplicateMeetingIds = array_keys($duplicates);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (!empty($duplicates)) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php _e('Warning: Duplicate registrations detected!', 'confur'); ?></strong></p>
                    <p><?php _e('The following meetings have multiple active (non-cancelled) registrations. Use the "Cancel" button to cancel duplicate entries:', 'confur'); ?></p>
                    <ul>
                        <?php foreach ($duplicates as $meetingId => $info) : ?>
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

            <?php if (empty($allMeetings)) : ?>
                <div class="notice notice-info">
                    <p><?php _e('No meetings found.', 'confur'); ?></p>
                </div>
            <?php else : ?>
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
                    <?php foreach ($allMeetings as $meeting) :
                        // Check if this meeting is part of a duplicate set (and not cancelled)
                        $isDuplicate = in_array($meeting['id'], $duplicateMeetingIds)
                                       && $meeting['is_registered']
                                       && $meeting['status_class'] !== 'cancelled';
                        $rowClass = $isDuplicate ? 'duplicate-row' : $meeting['row_class'];
                        ?>
                        <tr class="<?php echo esc_attr($rowClass); ?>">
                            <td class="answer-name">
                                <?php if ($meeting['is_registered']) : ?>
                                    <a href="<?php echo esc_url($meeting['edit_url']); ?>">
                                        <?php echo esc_html($meeting['name']); ?>
                                    </a>
                                    <?php if ($isDuplicate) : ?>
                                        <span class="duplicate-indicator">DUPLICATE</span>
                                    <?php endif; ?>
                                <?php else : ?>
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
                                // Anchor markup built by HtmlHelper::createLink(),
                                // which escapes href, class and content itself.
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                echo $meeting['email_html'];
                                ?>
                            </td>
                            <td class="contact-info">
                                <?php
                                // Contact name is escaped in contactTelephoneLink();
                                // the anchor is escaped by createLink().
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
                                <?php if ($meeting['is_registered'] && !empty($meeting['answer_id'])) : ?>
                                    <?php if ($isDuplicate) : ?>
                                        <button type="button"
                                                class="cancel-duplicate-btn"
                                                data-answer-id="<?php echo esc_attr($meeting['answer_id']); ?>"
                                                data-meeting-name="<?php echo esc_attr($meeting['name']); ?>">
                                            <span class="btn-text">Cancel</span>
                                            <span class="spinner"></span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($meeting['status_class'] !== 'cancelled') : ?>
                                        <button type="button"
                                                class="resend-confirmation-btn"
                                                data-answer-id="<?php echo esc_attr($meeting['answer_id']); ?>"
                                                data-meeting-name="<?php echo esc_attr($meeting['name']); ?>">
                                            <span class="btn-text">Resend Email</span>
                                            <span class="spinner"></span>
                                        </button>
                                    <?php endif; ?>
                                <?php else : ?>
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
     * @return array<int, array<string, mixed>> Meeting data
     */
    private function getAllMeetingsData(): array
    {
        $allMeetings = [];

        // Get all meetings from repository
        $meetings = $this->meetingRepository->getMeetings();

        // Get registered groups
        $registered = $this->answerRepository->getRegisteredGroups();

        // Create lookup for registered meetings (supports multiple registrations per meeting)
        // For paired registrations, add entry under both meeting IDs
        $registeredLookup = [];
        foreach ($registered as $item) {
            // Get meeting ID - handle if it's an object, array, or scalar
            // An object here is a post-like row — WP_Post, or a plain object
            // straight off a query. Casting it to an array reads the public ID
            // without naming a class, and collapses the object and array cases
            // into the one expression they always morally were.
            $meetingId = $item['meetingId'];
            if (is_object($meetingId)) {
                $meetingId = (array) $meetingId;
            }
            if (is_array($meetingId)) {
                $meetingId = $meetingId['ID'] ?? null;
            }

            // Get fellow meeting ID
            $fellowMeetingId = $item['fellowMeetingId'] ?? null;
            if (is_object($fellowMeetingId)) {
                $fellowMeetingId = (array) $fellowMeetingId;
            }
            if (is_array($fellowMeetingId)) {
                $fellowMeetingId = $fellowMeetingId['ID'] ?? null;
            }

            // Add under primary meeting ID
            if ($meetingId && is_numeric($meetingId)) {
                $meetingIdInt = (int)$meetingId;
                if (!isset($registeredLookup[$meetingIdInt])) {
                    $registeredLookup[$meetingIdInt] = [];
                }
                $registeredLookup[$meetingIdInt][] = $item;
            }

            // Also add under fellow meeting ID if it exists (for paired registrations)
            if ($fellowMeetingId && is_numeric($fellowMeetingId)) {
                $fellowMeetingIdInt = (int)$fellowMeetingId;
                if (!isset($registeredLookup[$fellowMeetingIdInt])) {
                    $registeredLookup[$fellowMeetingIdInt] = [];
                }
                $registeredLookup[$fellowMeetingIdInt][] = $item;
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

    /**
     * Find meetings with duplicate active (non-cancelled) registrations
     *
     * @param array<int, array<string, mixed>> $allMeetings Meeting data
     * @return array<int, array<string, mixed>> Duplicates with meeting ID as key and info (name, count) as value
     */
    private function findDuplicateRegistrations(array $allMeetings): array
    {
        // Get all registered groups with full data
        $registered = $this->answerRepository->getRegisteredGroups();

        // Build a list of unique registrations (by answer ID) with their meeting combinations
        // We need to track the FIRST entry for each answer ID (which has the correct fellowMeetingId)
        $registrations = [];
        $answerData = [];

        // First pass: collect the primary entry for each answer (the one with fellowMeetingId if paired)
        foreach ($registered as $item) {
            $answerId = $item['answersId'];

            // Only keep the first entry for each answer (which has the full meeting data)
            if (!isset($answerData[$answerId])) {
                $answerData[$answerId] = $item;
            }
        }

        error_log("findDuplicateRegistrations - Found " . count($answerData) . " unique answers");

        // Second pass: group by email + meeting combination
        foreach ($answerData as $answerId => $item) {
            $status = $item['status'] ?? '';

            // Skip cancelled registrations (check for the exact constant value)
            if ($status === Constants::STATUS_CANCELLED) {
                error_log("findDuplicateRegistrations - Skipping cancelled answer $answerId");
                continue;
            }

            $meetingId = $item['meetingId'];
            $fellowMeetingId = $item['fellowMeetingId'];

            $email = strtolower(trim($item['email'] ?? ''));

            if (empty($meetingId) || empty($email)) {
                error_log("findDuplicateRegistrations - Skipping answer $answerId due to empty meetingId or email");
                continue;
            }

            // Build sorted meeting IDs array for comparison
            $meetingIds = [(int)$meetingId];
            $isPaired = !empty($fellowMeetingId);
            if ($isPaired) {
                $meetingIds[] = (int)$fellowMeetingId;
            }
            sort($meetingIds);

            // Create a unique key for this meeting combination + email.
            // Deliberately not logged: it embeds the registrant's address.
            $key = $email . '|' . ($isPaired ? 'paired' : 'single') . '|' . implode('-', $meetingIds);

            if (!isset($registrations[$key])) {
                // Build name for display
                $name = get_the_title($meetingId);
                if ($isPaired) {
                    $name .= ' and ' . get_the_title($fellowMeetingId);
                }

                $registrations[$key] = [
                    'meetingIds' => $meetingIds,
                    'email' => $email,
                    'isPaired' => $isPaired,
                    'count' => 0,
                    'name' => $name
                ];
            }

            $registrations[$key]['count']++;
        }

        // Not dumped: $registrations is keyed by registrant email address.
        error_log('findDuplicateRegistrations - Registration groups: ' . count($registrations));

        // Filter to only duplicates and reformat for display
        $duplicates = [];
        foreach ($registrations as $key => $info) {
            if ($info['count'] > 1) {
                // Use first meeting ID as the key for the duplicates array
                $firstMeetingId = $info['meetingIds'][0];
                $duplicates[$firstMeetingId] = [
                    'name' => $info['name'] . ' (' . $info['email'] . ')',
                    'count' => $info['count']
                ];
                error_log("findDuplicateRegistrations - Found duplicate: " . $info['name'] . " count=" . $info['count']);
            }
        }

        error_log("findDuplicateRegistrations - Total duplicates found: " . count($duplicates));

        return $duplicates;
    }
}