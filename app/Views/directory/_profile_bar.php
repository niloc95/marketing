<?php
/**
 * The compact profile bar (owner, 18 Nov 2026): once the full header has
 * scrolled out of view on a desktop, this slim bar docks under the site
 * header so the visitor still sees whose page this is while the content
 * scrolls. directory.js ("profile bar") shows it; resources/directory.css
 * (.profile-bar) keeps it to lg and up.
 *
 * Identity only, no buttons: the sticky Contact card beside the content
 * already has Book, Call, WhatsApp and Email, so nothing is offered twice and
 * no tap is counted twice. aria-hidden because it repeats the h1 above.
 *
 * @var array  $l       the public view of the listing
 * @var string $name
 * @var string $prof    category name, or ''
 * @var string $avatar  the header's avatar markup (logo or initials)
 */
$todayHours = hours_today_line($l['trading_hours'] ?? null, $l['holiday_rule'] ?? null, ! empty($l['by_appointment']));
$rating     = rating_summary($l);
?>
<div class="profile-bar" data-profile-bar aria-hidden="true">
    <div class="container profile-bar-inner">
        <?= $avatar ?>
        <div class="profile-bar-text">
            <p class="profile-bar-name"><?= esc($name) ?></p>
            <p class="profile-bar-meta">
                <?php if ($prof): ?><span class="profile-bar-category"><?= esc($prof) ?></span><?php endif; ?>
                <?php if ($rating !== ''): ?><span><?= $rating ?></span><?php endif; ?>
                <?php if ($todayHours !== null): ?>
                    <?php $glanceClass = ['open' => 'hours-glance-open', 'closed' => 'hours-glance-closed'][$todayHours['state'] ?? ''] ?? 'hours-glance-unknown'; ?>
                    <span class="profile-bar-hours"><span class="<?= $glanceClass ?>"><?= esc($todayHours['label']) ?></span><?php if ($todayHours['detail'] !== ''): ?> <span><?= esc($todayHours['detail']) ?></span><?php endif; ?></span>
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>
