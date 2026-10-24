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
 * @var string     $id      anchor id, for the listing's own panel only: the
 *                         header's "See hours" link jumps here, and a branch
 *                         panel reusing this view must not repeat the id
 * @var bool       $byAppointment the listing trades by appointment only; the
 *                         panel says so, and shows the grid only if some
 *                         times were given as well
 * @var array|null $holidays the public holiday answer (hours_holiday_rule());
 *                         passed by every caller, null for none, because CI4
 *                         view data outlives a render
 */
$byAppointment = ! empty($byAppointment);
$anyTimes      = is_array($hours) && array_filter($hours, static fn ($d): bool => is_array($d) && (($d['open'] ?? '') !== '' || ! empty($d['closed']))) !== [];

$holidays      = $holidays ?? null;

if (empty($hours) && ! $byAppointment && $holidays === null) {
    return;
}

helper('directory_hours');

$heading  = $heading ?? 'Trading hours';
$class    = $class ?? '';
$id       = $id ?? '';
$todayKey = hours_today_key();
?>
<div class="panel <?= esc($class, 'attr') ?>"<?= $id !== '' ? ' id="' . esc($id, 'attr') . '"' : '' ?>>
    <h3><?= esc($heading) ?></h3>
    <?php if ($byAppointment): ?>
        <p class="hours-appointment">By appointment only</p>
        <?php if (! $anyTimes): ?></div><?php return; endif; ?>
    <?php endif; ?>
    <?php // Only a holiday answer and no week: say nothing about the days
          // rather than list seven of them as Closed. ?>
    <?php foreach (! empty($hours) ? hours_days() : [] as $key => $label): $row = $hours[$key] ?? null; ?>
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

    <?php // Public holidays: the owner's answer, any holidays that differ,
          // and the next holiday resolved to what it means for this business.
          // Without an answer it says so rather than leaving a visitor to
          // assume the weekday's hours hold. ?>
    <?php
    $calendar = hours_calendar();
    $date     = hours_now()->format('Y-m-d');
    $holToday = $calendar->on($date);
    $next     = $holToday ?? $calendar->next($date);
    $nextAns  = $next !== null ? hours_holiday_answer($holidays, $next['key'], hours_weekday_row($hours, $next['date'])) : null;
    $differs  = [];
    foreach ((array) ($holidays['except'] ?? []) as $key => $ex) {
        if (isset($calendar->named()[$key])) {
            $differs[] = $calendar->named()[$key] . ': ' . hours_holiday_text($ex);
        }
    }
    ?>
    <div class="kv hours-day-row hours-holiday-line<?= $holToday !== null ? ' hours-today' : '' ?>">
        <span class="k">Public holidays<?php if ($holToday !== null): ?> <span class="hours-today-badge">Today</span><?php endif; ?></span>
        <span>
            <?= $holidays !== null ? esc(hours_holiday_text($holidays)) : 'Not given. Hours may differ, so call ahead' ?>
            <?php if (! empty($holidays['note'])): ?><br><span class="hours-note-text"><?= esc($holidays['note']) ?></span><?php endif; ?>
            <?php foreach ($differs as $line): ?><br><span class="hours-note-text"><?= esc($line) ?></span><?php endforeach; ?>
            <?php if ($next !== null): ?>
                <br><span class="hours-note-text">
                    <?= $holToday !== null ? 'Today' : 'Next' ?>: <?= esc(App\Libraries\PublicHolidays::label($next)) ?>, <?= esc(date('D j M', strtotime($next['date']))) ?><?php if ($nextAns !== null): ?>, <?= esc(($t = hours_holiday_text($nextAns)) === 'Closed' || $t === 'Open' ? strtolower($t) : $t) ?><?php endif; ?>
                </span>
            <?php endif; ?>
        </span>
    </div>
</div>
