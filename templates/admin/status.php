<?php
/**
 * Questions for Conference → Status. Rendered by StatusAdminPage::renderAdminPage().
 *
 * @var array<int, array<string, mixed>> $rows One row per group, or per registration.
 * @var array<string, int> $stats Totals for the summary boxes.
 * @var list<array{name: string, count: int, answerIds: list<int>}> $duplicates Sets of duplicate registrations.
 * @var list<int> $duplicateAnswerIds Registrations in any of those sets.
 */

?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if (!empty($duplicates)) : ?>
        <div class="notice notice-warning">
            <p><strong><?php _e('Warning: Duplicate registrations detected!', 'confur'); ?></strong></p>
            <p><?php _e('The following groups have multiple active (non-cancelled) registrations. Use the "Cancel" button to cancel duplicate entries:', 'confur'); ?></p>
            <ul>
                <?php foreach ($duplicates as $info) : ?>
                    <li><?php echo esc_html($info['name']); ?> (<?php echo esc_html($info['count']); ?> registrations)</li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="confur-answers-header">
        <div class="confur-answers-stats">
            <div class="stat-box">
                <div class="number"><?php echo esc_html($stats['total']); ?></div>
                <div class="label">Total Groups</div>
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

    <?php if (empty($rows)) : ?>
        <div class="notice notice-info">
            <p><?php _e('No groups found.', 'confur'); ?></p>
        </div>
    <?php else : ?>
        <table class="confur-answers-table">
            <thead>
            <tr>
                <th>Group/Answers</th>
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
            <?php foreach ($rows as $row) :
                // This registration is in a duplicate set (and not cancelled).
                // By registration, not by group: another registration for the
                // same group with a different email is not a duplicate.
                $isDuplicate = in_array($row['answer_id'], $duplicateAnswerIds, true)
                               && $row['is_registered']
                               && $row['status_class'] !== 'cancelled';
                $rowClass = $isDuplicate ? 'duplicate-row' : $row['row_class'];
                ?>
                <tr class="<?php echo esc_attr($rowClass); ?>">
                    <td class="answer-name">
                        <?php if ($row['is_registered']) : ?>
                            <a href="<?php echo esc_url($row['edit_url']); ?>">
                                <?php echo esc_html($row['name']); ?>
                            </a>
                            <?php if ($isDuplicate) : ?>
                                <span class="duplicate-indicator">DUPLICATE</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <a href="<?php echo esc_url($row['group_url']); ?>" target="_blank">
                                <?php echo esc_html($row['name']); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo esc_attr($row['status_class']); ?>">
                            <?php echo esc_html($row['status_label']); ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        // Anchor markup built by HtmlHelper::createLink(),
                        // which escapes href, class and content itself.
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $row['email_html'];
                        ?>
                    </td>
                    <td class="contact-info">
                        <?php
                        // Contact name is escaped in contactTelephoneLink();
                        // the anchor is escaped by createLink().
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $row['contact1_html'];
                        ?>
                    </td>
                    <td class="contact-info">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $row['contact2_html'];
                        ?>
                    </td>
                    <td><?php echo esc_html($row['day']); ?></td>
                    <td><?php echo esc_html($row['time']); ?></td>
                    <td><?php echo esc_html($row['last_saved']); ?></td>
                    <td class="actions-cell">
                        <?php if ($row['is_registered'] && !empty($row['answer_id'])) : ?>
                            <?php if ($isDuplicate) : ?>
                                <button type="button"
                                        class="cancel-duplicate-btn"
                                        data-answer-id="<?php echo esc_attr($row['answer_id']); ?>"
                                        data-group-name="<?php echo esc_attr($row['name']); ?>">
                                    <span class="btn-text">Cancel</span>
                                    <span class="spinner"></span>
                                </button>
                            <?php endif; ?>
                            <?php if ($row['status_class'] !== 'cancelled') : ?>
                                <button type="button"
                                        class="resend-confirmation-btn"
                                        data-answer-id="<?php echo esc_attr($row['answer_id']); ?>"
                                        data-group-name="<?php echo esc_attr($row['name']); ?>">
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
