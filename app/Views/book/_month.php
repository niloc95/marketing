<?php
/**
 * One month of free days, the left column of the booking calendar (the
 * booking page and "Change your booking"). Free days are links; the rest are
 * plain numbers, so it works without script. In button mode (the /business
 * Book forms, free-times.js) days and arrows are buttons carrying
 * data-cal-from (Y-m-d) and data-cal-month (Y-m).
 *
 * @var array<string,mixed>      $calendar  PublicBookingService::month()
 * @var string|null              $date      the chosen day
 * @var array{0:string,1:string}|null $range the days on view beside it (from, to), marked
 * @var DateTimeZone             $timezone
 * @var (callable(string):string)|null $dayUrl    link mode: Y-m-d => link
 * @var (callable(string):string)|null $monthUrl  link mode: Y-m => link
 * @var string                   $mode      'link' (default) or 'button'
 */
$month = new DateTimeImmutable($calendar['month'] . '-01', $timezone);
$free  = array_flip($calendar['free']);
$range ??= null;
$dayUrl ??= null;
$monthUrl ??= null;
$isLink = ($mode ?? 'link') === 'link';
$arrow  = static function (string $to, string $label, string $icon) use ($isLink, $monthUrl): string {
    return $isLink
        ? '<a class="btn btn-ghost btn-xs" href="' . esc($monthUrl($to), 'attr') . '" aria-label="' . $label . '">' . lucide($icon, 'h-4 w-4') . '</a>'
        : '<button type="button" class="btn btn-ghost btn-xs" data-cal-month="' . esc($to, 'attr') . '" aria-label="' . $label . '">' . lucide($icon, 'h-4 w-4') . '</button>';
};
$inView = static fn (string $iso): bool => $range !== null && $iso >= $range[0] && $iso <= $range[1];
?>
<div class="book-cal">
    <div class="mb-3 flex items-center justify-between gap-2">
        <?php if ($calendar['prev'] !== null): ?>
            <?= $arrow($calendar['prev'], 'Earlier month', 'chevron-left') ?>
        <?php else: ?><span class="w-8"></span><?php endif ?>
        <h3 class="font-semibold"><?= $month->format('F Y') ?></h3>
        <?php if ($calendar['next'] !== null): ?>
            <?= $arrow($calendar['next'], 'Later month', 'chevron-right') ?>
        <?php else: ?><span class="w-8"></span><?php endif ?>
    </div>
    <div class="avail-grid">
        <?php foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $wd): ?><span class="avail-wd"><?= $wd ?></span><?php endforeach ?>
        <?php for ($i = 1; $i < (int) $month->format('N'); $i++): ?><span></span><?php endfor ?>
        <?php for ($d = $month; $d->format('m') === $month->format('m'); $d = $d->modify('+1 day')): ?>
            <?php $iso = $d->format('Y-m-d'); ?>
            <?php if (isset($free[$iso]) && ! $isLink): ?>
                <button type="button" class="avail-day avail-free<?= $iso === $date ? ' avail-today' : '' ?><?= $inView($iso) ? ' avail-inview' : '' ?>" data-cal-from="<?= $iso ?>"<?= $iso === $date ? ' aria-current="date"' : '' ?> aria-label="<?= esc($d->format('l j F'), 'attr') ?>"><span class="avail-day-num"><?= $d->format('j') ?></span></button>
            <?php elseif (isset($free[$iso])): ?>
                <a class="avail-day avail-free<?= $iso === $date ? ' avail-today' : '' ?><?= $inView($iso) ? ' avail-inview' : '' ?>" href="<?= esc($dayUrl($iso), 'attr') ?>"<?= $iso === $date ? ' aria-current="date"' : '' ?> aria-label="<?= esc($d->format('l j F'), 'attr') ?>"><span class="avail-day-num"><?= $d->format('j') ?></span></a>
            <?php else: ?>
                <span class="avail-day avail-off<?= $inView($iso) ? ' avail-inview' : '' ?>" aria-disabled="true"><span class="avail-day-num"><?= $d->format('j') ?></span></span>
            <?php endif ?>
        <?php endfor ?>
    </div>
    <?php if ($calendar['free'] === []): ?>
        <p class="mt-3 text-sm">No free times this month.<?= $calendar['next'] !== null ? ' Try the next month.' : '' ?></p>
    <?php endif ?>
</div>
