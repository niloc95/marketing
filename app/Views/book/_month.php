<?php
/**
 * One month of free days, the left column of the booking calendar (the
 * booking page and "Change your booking"). Free days are links; the rest are
 * plain numbers, so it works without script.
 *
 * @var array<string,mixed>      $calendar  PublicBookingService::month()
 * @var string|null              $date      the chosen day
 * @var DateTimeZone             $timezone
 * @var callable(string):string  $dayUrl    Y-m-d => link
 * @var callable(string):string  $monthUrl  Y-m => link
 */
$month = new DateTimeImmutable($calendar['month'] . '-01', $timezone);
$free  = array_flip($calendar['free']);
?>
<div class="book-cal">
    <div class="mb-3 flex items-center justify-between gap-2">
        <?php if ($calendar['prev'] !== null): ?>
            <a class="btn btn-ghost btn-xs" href="<?= esc($monthUrl($calendar['prev']), 'attr') ?>" aria-label="Earlier month"><?= lucide('chevron-left', 'h-4 w-4') ?></a>
        <?php else: ?><span class="w-8"></span><?php endif ?>
        <h3 class="font-semibold"><?= $month->format('F Y') ?></h3>
        <?php if ($calendar['next'] !== null): ?>
            <a class="btn btn-ghost btn-xs" href="<?= esc($monthUrl($calendar['next']), 'attr') ?>" aria-label="Later month"><?= lucide('chevron-right', 'h-4 w-4') ?></a>
        <?php else: ?><span class="w-8"></span><?php endif ?>
    </div>
    <div class="avail-grid">
        <?php foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $wd): ?><span class="avail-wd"><?= $wd ?></span><?php endforeach ?>
        <?php for ($i = 1; $i < (int) $month->format('N'); $i++): ?><span></span><?php endfor ?>
        <?php for ($d = $month; $d->format('m') === $month->format('m'); $d = $d->modify('+1 day')): ?>
            <?php $iso = $d->format('Y-m-d'); ?>
            <?php if (isset($free[$iso])): ?>
                <a class="avail-day avail-free<?= $iso === $date ? ' avail-today' : '' ?>" href="<?= esc($dayUrl($iso), 'attr') ?>"<?= $iso === $date ? ' aria-current="date"' : '' ?> aria-label="<?= esc($d->format('l j F'), 'attr') ?>"><span class="avail-day-num"><?= $d->format('j') ?></span></a>
            <?php else: ?>
                <span class="avail-day avail-off" aria-disabled="true"><span class="avail-day-num"><?= $d->format('j') ?></span></span>
            <?php endif ?>
        <?php endfor ?>
    </div>
    <?php if ($calendar['free'] === []): ?>
        <p class="mt-3 text-sm">No free times this month.<?= $calendar['next'] !== null ? ' Try the next month.' : '' ?></p>
    <?php endif ?>
</div>
