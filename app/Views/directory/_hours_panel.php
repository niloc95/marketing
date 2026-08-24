<?php
/**
 * The "Trading hours" panel, for the listing or for one branch.
 *
 * Extracted from show.php. $hours is whatever hours_decode() produced, so this
 * needs no branch-specific case: DirectoryService::getProfile() decodes the
 * listing's and every branch's the same way, and both arrive here keyed
 * mon..sun.
 *
 * @var array|null $hours   decoded hours, keyed mon..sun
 * @var string     $heading panel heading
 * @var string     $class   extra classes for the panel wrapper
 */
if (empty($hours)) {
    return;
}

helper('directory_hours');

$heading  = $heading ?? 'Trading hours';
$class    = $class ?? '';
$todayKey = hours_today_key();
?>
<div class="panel <?= esc($class, 'attr') ?>">
    <h3><?= esc($heading) ?></h3>
    <?php foreach (hours_days() as $key => $label): $row = $hours[$key] ?? null; ?>
        <div class="kv hours-day-row<?= $key === $todayKey ? ' hours-today' : '' ?>">
            <span class="k"><?= esc($label) ?><?php if ($key === $todayKey): ?> <span class="hours-today-badge">Today</span><?php endif; ?></span>
            <span>
                <?php if (empty($row) || ! empty($row['closed'])): ?>
                    Closed
                <?php else: ?>
                    <?= esc($row['open']) ?>&ndash;<?= esc($row['close']) ?>
                <?php endif; ?>
                <?php if (! empty($row['note'])): ?><br><span class="hours-note-text"><?= esc($row['note']) ?></span><?php endif; ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>
