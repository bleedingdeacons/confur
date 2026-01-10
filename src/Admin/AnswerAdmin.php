<?php

namespace Confur\Admin;

use Confur\Config\Constants;
use WP_Post;
use WP_Query;

/**
 * Answer Admin
 * 
 * Adds custom columns and bulk actions to the admin table view for answers.
 */
class AnswerAdmin
{
    /**
     * Constructor - register hooks
     */
    public function __construct()
    {
        // Only run in admin
        if (!is_admin()) {
            return;
        }

        // Custom columns
        add_filter('manage_' . Constants::ANSWER_CUSTOM_TYPE . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . Constants::ANSWER_CUSTOM_TYPE . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . Constants::ANSWER_CUSTOM_TYPE . '_sortable_columns', [$this, 'makeColumnsSortable']);
        add_filter('pre_get_posts', [$this, 'handleCustomColumnSorting']);
        
        // Bulk actions
        add_filter('bulk_actions-edit-' . Constants::ANSWER_CUSTOM_TYPE, [$this, 'addBulkActions']);
        add_filter('handle_bulk_actions-edit-' . Constants::ANSWER_CUSTOM_TYPE, [$this, 'handleBulkActions'], 10, 3);
        add_action('admin_notices', [$this, 'displayBulkActionNotice']);
        
        // Admin styles
        add_action('admin_head', [$this, 'addAdminColumnStyles']);
    }

    /**
     * Add custom columns to the answers admin table
     * 
     * @param array $columns Current admin columns
     * @return array Modified admin columns
     */
    public function addCustomColumns(array $columns): array
    {
        $newColumns = [];
        
        foreach ($columns as $key => $value) {
            $newColumns[$key] = $value;
            
            if ($key === 'title') {
                $newColumns['answer_status'] = 'Status';
                $newColumns['answer_email'] = 'Email';
                $newColumns['answer_updated'] = 'Last Updated';
            }
        }
        
        return $newColumns;
    }

    /**
     * Populate the custom columns with data
     * 
     * @param string $columnName Name of the column
     * @param int $postId Post ID
     */
    public function populateCustomColumns(string $columnName, int $postId): void
    {
        switch ($columnName) {
            case 'answer_status':
                $this->displayStatus($postId);
                break;
                
            case 'answer_email':
                $this->displayEmail($postId);
                break;
                
            case 'answer_updated':
                $this->displayUpdated($postId);
                break;
        }
    }

    /**
     * Make certain columns sortable
     * 
     * @param array $columns Current sortable columns
     * @return array Modified sortable columns
     */
    public function makeColumnsSortable(array $columns): array
    {
        $columns['answer_status'] = 'answer_status';
        $columns['answer_email'] = 'answer_email';
        $columns['answer_updated'] = 'answer_updated';
        return $columns;
    }

    /**
     * Display the answer status with styling
     * 
     * @param int $postId Post ID
     */
    private function displayStatus(int $postId): void
    {
        $status = get_field(Constants::STATUS_FIELD, $postId);
        
        if (empty($status)) {
            $status = 'Not Started';
        }
        
        $statusClass = $this->getStatusClass($status);
        
        echo '<span class="answer-status-badge status-' . esc_attr($statusClass) . '">' . 
             esc_html($status) . '</span>';
    }

    /**
     * Get CSS class for status
     * 
     * @param string $status Status value
     * @return string CSS class name
     */
    private function getStatusClass(string $status): string
    {
        switch ($status) {
            case Constants::STATUS_COMPLETED:
            case 'Complete':
            case 'completed':
                return 'completed';
                
            case Constants::STATUS_DRAFT:
            case 'Draft':
            case 'draft':
                return 'draft';
                
            case Constants::STATUS_CANCELLED:
            case 'Cancelled':
            case 'cancelled':
                return 'cancelled';
                
            default:
                return 'not-started';
        }
    }

    /**
     * Display the email as a mailto link
     * 
     * @param int $postId Post ID
     */
    private function displayEmail(int $postId): void
    {
        $email = get_field(Constants::EMAIL_FIELD, $postId);
        
        if (empty($email)) {
            echo '-';
            return;
        }
        
        echo '<a href="mailto:' . esc_attr($email) . '">' . 
             esc_html($email) . '</a>';
    }

    /**
     * Display the last updated date
     * 
     * @param int $postId Post ID
     */
    private function displayUpdated(int $postId): void
    {
        $updated = get_field(Constants::UPDATED_FIELD, $postId);
        
        if (empty($updated)) {
            echo '-';
            return;
        }
        
        echo esc_html($updated);
    }

    /**
     * Handle custom column sorting
     * 
     * @param WP_Query $query The query object
     * @return WP_Query Modified query
     */
    public function handleCustomColumnSorting(WP_Query $query): WP_Query
    {
        if (!is_admin() || !$query->is_main_query()) {
            return $query;
        }
        
        if ($query->get('post_type') !== Constants::ANSWER_CUSTOM_TYPE) {
            return $query;
        }
        
        $orderby = $query->get('orderby');
        
        switch ($orderby) {
            case 'answer_status':
                $query->set('meta_key', Constants::STATUS_FIELD);
                $query->set('orderby', 'meta_value');
                break;
                
            case 'answer_email':
                $query->set('meta_key', Constants::EMAIL_FIELD);
                $query->set('orderby', 'meta_value');
                break;
                
            case 'answer_updated':
                $query->set('meta_key', Constants::UPDATED_FIELD);
                $query->set('orderby', 'meta_value');
                break;
        }
        
        return $query;
    }

    /**
     * Add bulk actions to the dropdown
     * 
     * @param array $actions Current bulk actions
     * @return array Modified bulk actions
     */
    public function addBulkActions(array $actions): array
    {
        $actions['mark_cancelled'] = 'Mark as Cancelled';
        return $actions;
    }

    /**
     * Handle bulk actions
     * 
     * @param string $redirectTo Redirect URL
     * @param string $action Action being performed
     * @param array $postIds Array of post IDs
     * @return string Modified redirect URL
     */
    public function handleBulkActions(string $redirectTo, string $action, array $postIds): string
    {
        if ($action !== 'mark_cancelled') {
            return $redirectTo;
        }
        
        $updatedCount = 0;
        
        foreach ($postIds as $postId) {
            $result = update_field(Constants::STATUS_FIELD, Constants::STATUS_CANCELLED, $postId);
            
            if ($result) {
                $updatedCount++;
            }
        }
        
        return add_query_arg([
            'bulk_cancelled' => $updatedCount,
        ], $redirectTo);
    }

    /**
     * Display notice after bulk action
     */
    public function displayBulkActionNotice(): void
    {
        if (!isset($_REQUEST['bulk_cancelled'])) {
            return;
        }
        
        $count = intval($_REQUEST['bulk_cancelled']);
        
        $message = sprintf(
            _n(
                '%d answer marked as cancelled.',
                '%d answers marked as cancelled.',
                $count,
                'confur'
            ),
            $count
        );
        
        echo '<div class="notice notice-success is-dismissible"><p>' . 
             esc_html($message) . '</p></div>';
    }

    /**
     * Add admin column styles
     */
    public function addAdminColumnStyles(): void
    {
        $screen = get_current_screen();
        
        if (!$screen || $screen->post_type !== Constants::ANSWER_CUSTOM_TYPE) {
            return;
        }
        
        echo '<style>
            .answer-status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .answer-status-badge.status-completed {
                background: #d4edda;
                color: #155724;
            }
            .answer-status-badge.status-draft {
                background: #fff3cd;
                color: #856404;
            }
            .answer-status-badge.status-cancelled {
                background: #6c757d;
                color: #ffffff;
            }
            .answer-status-badge.status-not-started {
                background: #f8d7da;
                color: #721c24;
            }
            .column-answer_status {
                width: 100px;
            }
            .column-answer_email {
                width: 200px;
            }
            .column-answer_updated {
                width: 180px;
            }
        </style>';
    }
}
