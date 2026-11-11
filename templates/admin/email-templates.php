<?php
/**
 * Rendered by EmailTemplateAdminPage::renderAdminPage().
 *
 * @var array<string, array<string, mixed>> $templates Each template key => subject, body, label.
 * @var string $updated The ?updated= flag after a save, or ''.
 * @var string $error The ?error= flag after a failed save, or ''.
 */

?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if ($updated === '1') : ?>
        <div class="confur-notice">
            <p><strong>Email templates updated successfully.</strong></p>
        </div>
    <?php endif; ?>

    <?php if ($updated === 'reset_all') : ?>
        <div class="confur-notice">
            <p><strong>All email templates reset to defaults.</strong></p>
        </div>
    <?php endif; ?>

    <?php if ($updated === 'reset_single') : ?>
        <div class="confur-notice">
            <p><strong>Email template reset to default.</strong></p>
        </div>
    <?php endif; ?>

    <?php if ($error !== '') : ?>
        <div class="confur-notice error">
            <p><strong>Error:</strong> Failed to update email templates.</p>
        </div>
    <?php endif; ?>

    <div class="confur-templates-container">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('confur_email_templates_action', 'confur_email_templates_nonce'); ?>
            <input type="hidden" name="action" value="confur_update_email_templates">

            <?php foreach ($templates as $key => $template) : ?>
                <div class="confur-template-card">
                    <h2>
                        <?php echo esc_html($template['name']); ?>
                        <span class="confur-template-badge"><?php echo esc_html($key); ?></span>
                    </h2>

                    <div class="template-description">
                        <?php echo esc_html($template['description']); ?>
                        <?php if (!empty($template['placeholders'])) : ?>
                            <br><br>
                            <strong>Placeholders:</strong>
                            <?php foreach ($template['placeholders'] as $placeholder) : ?>
                                <code><?php echo esc_html($placeholder); ?></code>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="confur-template-field">
                        <label for="template_<?php echo esc_attr($key); ?>_subject">Subject Line</label>
                        <input
                                type="text"
                                id="template_<?php echo esc_attr($key); ?>_subject"
                                name="template_<?php echo esc_attr($key); ?>_subject"
                                value="<?php echo esc_attr($template['subject']); ?>"
                                class="regular-text"
                        />
                    </div>

                    <div class="confur-template-field">
                        <label for="template_<?php echo esc_attr($key); ?>_body">Email Body</label>
                        <?php
                        $editor_id = 'template_' . $key . '_body';
                        $editor_settings = [
                                'textarea_name' => $editor_id,
                                'textarea_rows' => 12,
                                'media_buttons' => false,
                                'teeny' => false,
                                'quicktags' => true,
                                'default_editor' => 'tinymce',
                                'tinymce' => [
                                        'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,undo,redo',
                                        'toolbar2' => '',
                                        'block_formats' => 'Paragraph=p;Heading 3=h3;Heading 4=h4',
                                ],
                        ];
                        wp_editor($template['body'], $editor_id, $editor_settings);
                        ?>
                        <p class="confur-placeholder-info">
                            Use the placeholders shown above to insert dynamic content.
                        </p>
                    </div>

                    <p>
                        <button type="submit" name="reset_template" value="<?php echo esc_attr($key); ?>" class="button button-secondary" onclick="return confirm('Reset this template to default? This cannot be undone.');">
                            Reset to Default
                        </button>
                    </p>
                </div>
            <?php endforeach; ?>

            <div class="confur-form-actions">
                <?php submit_button('Save All Templates', 'primary', 'submit', false); ?>
                <?php submit_button('Reset All to Defaults', 'delete', 'reset_all_defaults', false, ['onclick' => 'return confirm("Reset ALL templates to their default values? This cannot be undone.");']); ?>
            </div>
        </form>

        <div class="confur-reset-warning" style="margin-top: 20px;">
            <strong>Note:</strong> The default templates are stored in the <code>/emails</code> directory of the plugin.
            Custom changes are stored in the database and will override the file-based templates.
        </div>
    </div>
</div>
