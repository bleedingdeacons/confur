<?php
/**
 * Dashboard → Questions for Conference heads-up widget. Rendered by
 * ConfurHeadsUp::renderWidget(); its CSS and JS are in assets/admin/dashboard.*.
 *
 * @var string $updatesHtml The update list (templates/admin/dashboard-updates.php).
 * @var string $lastUpdated When it was built, for display.
 */

?>
<div id="confur-headsup-container">
    <div id="confur-headsup-content">
        <?php
        // Rendered from its own template, which escapes as it goes.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $updatesHtml;
        ?>
    </div>
    <div class="confur-headsup-footer">
        <span class="confur-last-updated-time">Last updated: <span id="confur-update-time"><?php echo esc_html($lastUpdated); ?></span></span>
        <button type="button" id="confur-refresh-btn" class="button button-small">
            <span class="dashicons dashicons-update"></span> Refresh
        </button>
    </div>
</div>
