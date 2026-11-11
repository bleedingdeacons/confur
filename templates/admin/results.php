<?php
/**
 * Questions for Conference → Results. Rendered by ResultAdminPage::renderPage().
 *
 * @var string $pageTitle
 * @var string $generatedAt When the report was built, for display.
 * @var string $reportHtml The navigation, answers and coverage tables. Built by
 *                         ResultAdminPage, which escapes each value as it goes.
 */

?>
<div class="wrap confur-reporting-admin">
    <div class="confur-reporting-header">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-analytics"></span>
            <?php echo esc_html($pageTitle); ?>
        </h1>
        <div class="confur-reporting-actions">
            <button type="button" class="button button-primary" onclick="window.print();">
                <span class="dashicons dashicons-printer"></span>
                Print Report
            </button>
            <button type="button" class="button" onclick="confurReportingRefresh();">
                <span class="dashicons dashicons-update"></span>
                Refresh
            </button>
            <span style="margin-left: 15px; line-height: 28px;">
                Report generated: <strong><?php echo esc_html($generatedAt); ?></strong>
            </span>
        </div>
    </div>

    <hr class="wp-header-end">

    <div class="notice notice-info is-dismissible">
        <p>
            <strong>Tip:</strong> Use the navigation links to jump to different sections of the report.
            Click the copy buttons to copy committee data to your clipboard.
        </p>
    </div>

    <div class="confur-reporting-content">
        <?php
        // Escaped as it was built; see $reportHtml above.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $reportHtml;
        ?>
    </div>
</div>
