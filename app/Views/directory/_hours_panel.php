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
 * @var bool       $byAppointment the listing trades by appointment only; the
 *                         panel says so, and shows the grid only if some
 *                         times were given as well
 */
$byAppointment = ! empty($byAppointment);
$anyTimes      = is_array($hours) && array_filter($hours, static fn ($d): bool => is_array($d) && (($d['open'] ?? '') !== '' || ! empty($d['closed']))) !== [];

if (empty($hours) && ! $byAppointment) {
    return;
}

helper('directory_hours');

$heading  = $heading ?? 'Trading hours';
$class    = $class ?? '';
$todayKey = hours_today_key();
?>
<div class="panel <?= esc($class, 'attr') ?>">
    <h3><?= esc($heading) ?></h3>
    <?php if ($byAppointment): ?>
        <p class="hours-appointment">By appointment only</p>
        <?php if (! $anyTimes): ?></div><?php return; endif; ?>
    <?php endif; ?>
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
