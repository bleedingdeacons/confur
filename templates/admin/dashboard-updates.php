<?php
/**
 * The heads-up widget's list of recent updates. Rendered by
 * ConfurHeadsUp::renderWidgetContent(), on load and on each AJAX refresh.
 *
 * @var array<int, array<int, list<array{group_name: string, updated: int, url: string}>>> $updates
 *      Committee => question => groups updated in the last 24 hours.
 * @var array<int, string> $committeeLabels Committee => "Committee N" or "All Committees".
 * @var int $now The current time, for "x ago".
 */

?>
<?php if ($updates === []) : ?>
    <div class="confur-no-data">
        <p>No updates in the last 24 hours</p>
    </div>
<?php else : ?>
    <ul class="confur-updates-list">
        <?php foreach ($updates as $committeeNum => $questions) : ?>
            <li class="confur-committee-item" data-committee="<?php echo esc_attr((string) $committeeNum); ?>">
                <div class="confur-committee-title">
                    <span class="confur-committee-toggle">▼</span><?php echo esc_html($committeeLabels[$committeeNum] ?? 'Committee ' . $committeeNum); ?>
                </div>
                <ul class="confur-questions-list">
                    <?php foreach ($questions as $questionNum => $groups) : ?>
                        <li class="confur-question-item">
                            <div class="confur-question-title">Question <?php echo esc_html((string) $questionNum); ?><span class="confur-recent-badge">UPDATED</span></div>
                            <ul class="confur-groups-list">
                                <?php foreach ($groups as $group) : ?>
                                    <li class="confur-group-item">
                                        <a href="<?php echo esc_url($group['url']); ?>" class="confur-group-link"><?php echo esc_html($group['group_name']); ?></a>
                                        <span class="confur-group-time"><?php echo esc_html(human_time_diff($group['updated'], $now)); ?> ago</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
