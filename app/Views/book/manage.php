<?php
/**
 * The customer's booking: details, a new time, cancel (Book::manage). The
 * address carries the key, so the page is private (Book::privatePage()).
 *
 * @var array<string,mixed>       $listing
 * @var array<string,mixed>       $a
 * @var string                    $path        this page's path
 * @var bool                      $new         just booked
 * @var string                    $customerHash
 * @var DateTimeZone              $timezone
 * @var string|null               $moveRefusal
 * @var string|null               $cancelRefusal
 * @var array<string,mixed>       $policies
 * @var string                    $today
 * @var array<string,mixed>|null  $calendar    PublicBookingService::rescheduleMonth(), null when it cannot move
 * @var array<string,mixed>|null  $nextFree    the first free time for the same service and person
 * @var string|null               $date
 * @var list<array<string,mixed>> $times
 * @var bool                      $twelveHour
 * @var array<string,mixed>|null  $deposit     what the customer sees about the deposit, or null
 */
use App\Services\Bookings\AppointmentStatus;

$siteName = config('Directory')->siteName();
$status   = [
    'pending'   => 'We have your request. ' . $listing['display_name'] . ' will confirm it.',
    'confirmed' => 'Your booking is confirmed.',
    'cancelled' => 'Your booking is cancelled.',
];
$tzDay = static fn (string $d): string => (new DateTimeImmutable($d, $timezone))->format('D j M');
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Your booking | ' . $siteName, 'description' => 'Your booking with ' . $listing['display_name'] . '.', 'canonical' => base_url('book/find'), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <?php if ($new): ?>
            <div class="alert alert-success mb-5" role="status">
                <p class="font-semibold"><?= $deposit !== null && $deposit['waiting'] ? 'Nearly done: pay the deposit below to keep this time.' : 'Booked.' ?> Your reference is <?= esc((string) $a['reference']) ?>.</p>
                <p class="mt-1 text-sm">Keep this page's address: it is how you change or cancel your booking. Anyone with the link can, so keep it to yourself.</p>
            </div>
        <?php endif ?>
        <p class="mb-2 text-sm"><a class="underline" href="<?= listing_url($listing) ?>"><?= esc($listing['display_name']) ?></a></p>
        <h1 class="mb-1 text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc((string) $a['service_name']) ?></h1>
        <p class="text-slate-700 dark:text-slate-200"><?= view('book/_when', ['a' => $a, 'timezone' => $timezone, 'twelveHour' => $twelveHour]) ?></p>
        <p class="text-sm text-slate-500 dark:text-slate-400">with <?= esc((string) $a['provider_name']) ?><?= $a['location_name'] ? ', ' . esc((string) $a['location_name']) : '' ?><?= $a['location_address'] ? ', ' . esc((string) $a['location_address']) : '' ?></p>
        <p class="mt-3 text-sm font-semibold"><?= esc($deposit !== null && $deposit['waiting'] ? 'Not booked yet: the time is held for you until the deposit is paid.' : ($status[$a['status']] ?? AppointmentStatus::label((string) $a['status']))) ?></p>
        <p class="mt-1 text-xs text-slate-500">Reference <?= esc((string) $a['reference']) ?> · Times in <?= esc($timezone->getName()) ?></p>

        <?php if ($deposit !== null): ?>
            <div class="panel mt-6" id="deposit">
                <h2 class="mb-2 font-bold">Deposit</h2>
                <?php if ($deposit['waiting']): ?>
                    <p class="text-sm">Pay the deposit of <strong><?= esc($deposit['amount']) ?></strong> to keep this time. We hold it for you until <?= esc($deposit['due_by']) ?>. The deposit goes to <?= esc($listing['display_name']) ?>, through <?= esc($deposit['gateway']) ?>.</p>
                    <?php if ($deposit['checking']): ?>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">If you have just paid, it can take a minute to show here. Refresh this page in a moment.</p>
                    <?php endif ?>
                    <?php if ($deposit['test']): ?>
                        <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">This business is testing its payments: no real money is taken.</p>
                    <?php endif ?>
                    <form method="post" action="<?= base_url($path . '/pay') ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-accent">Pay <?= esc($deposit['amount']) ?></button>
                    </form>
                <?php else: ?>
                    <p class="text-sm"><?= esc($deposit['text']) ?></p>
                    <?php if ($deposit['status'] === 'expired'): ?>
                        <p class="mt-2 text-sm"><a class="underline" href="<?= base_url('directory/' . $listing['slug'] . '/book') ?>">Book again</a></p>
                    <?php endif ?>
                <?php endif ?>
            </div>
        <?php endif ?>

        <?php if (in_array($a['status'], AppointmentStatus::UPCOMING, true)): ?>
            <div class="panel mt-6" id="change">
                <h2 class="mb-2 font-bold">Change your booking</h2>
                <?php if ($moveRefusal !== null): ?>
                    <p class="text-sm"><?= esc($moveRefusal) ?></p>
                <?php else: ?>
                    <?php
                    // The rules in plain words: how late it can change, and how far ahead.
                    $hours = (int) $policies['reschedule_hours'];
                    $month = new DateTimeImmutable($calendar['month'] . '-01', $timezone);
                    $free  = array_flip($calendar['free']);
                    $monthLink = static fn (string $m): string => base_url($path) . '?month=' . $m . '#change';
                    ?>
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                        You can move it to any free time up to <strong><?= esc($tzDay($calendar['last'])) ?> <?= esc((new DateTimeImmutable($calendar['last'], $timezone))->format('Y')) ?></strong>, as long as you change it at least <?= $hours ?> <?= $hours === 1 ? 'hour' : 'hours' ?> before your appointment.
                    </p>
                    <?php if ($nextFree !== null): ?>
                        <p class="mb-3 text-sm">Next free time:
                            <a class="font-semibold underline" href="<?= base_url($path) . '?month=' . substr($nextFree['date'], 0, 7) . '&date=' . $nextFree['date'] ?>#change"><?= esc($tzDay($nextFree['date'])) ?>, <?= esc($twelveHour ? (new DateTimeImmutable($nextFree['start'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('g:ia') : $nextFree['time']) ?></a>
                        </p>
                    <?php endif ?>
                    <div class="mb-2 flex items-center gap-2">
                        <?php if ($calendar['prev'] !== null): ?><a class="btn btn-ghost btn-xs" href="<?= $monthLink($calendar['prev']) ?>" aria-label="Earlier month"><?= lucide('chevron-left', 'h-4 w-4') ?></a><?php endif ?>
                        <h3 class="font-semibold"><?= $month->format('F Y') ?></h3>
                        <?php if ($calendar['next'] !== null): ?><a class="btn btn-ghost btn-xs" href="<?= $monthLink($calendar['next']) ?>" aria-label="Later month"><?= lucide('chevron-right', 'h-4 w-4') ?></a><?php endif ?>
                    </div>
                    <?php // Free days are links; the rest are plain numbers. ?>
                    <div class="avail-grid max-w-md">
                        <?php foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $wd): ?><span class="avail-wd"><?= $wd ?></span><?php endforeach ?>
                        <?php for ($i = 1; $i < (int) $month->format('N'); $i++): ?><span></span><?php endfor ?>
                        <?php for ($d = $month; $d->format('m') === $month->format('m'); $d = $d->modify('+1 day')): ?>
                            <?php $iso = $d->format('Y-m-d'); ?>
                            <?php if (isset($free[$iso])): ?>
                                <a class="avail-day avail-free<?= $iso === $date ? ' avail-today' : '' ?>" href="<?= base_url($path) . '?month=' . $calendar['month'] . '&date=' . $iso ?>#change"<?= $iso === $date ? ' aria-current="date"' : '' ?>><span class="avail-day-num"><?= $d->format('j') ?></span></a>
                            <?php else: ?>
                                <span class="avail-day avail-off" aria-disabled="true"><span class="avail-day-num"><?= $d->format('j') ?></span></span>
                            <?php endif ?>
                        <?php endfor ?>
                    </div>
                    <?php if ($calendar['free'] === []): ?>
                        <p class="mt-2 text-sm">No free times this month.<?= $calendar['next'] !== null ? ' Try the next month.' : '' ?></p>
                    <?php endif ?>
                    <?php if ($date !== null): ?>
                        <?php if ($times === []): ?>
                            <p class="mt-3 text-sm">No times left on this day. Try another day.</p>
                        <?php else: ?>
                            <form method="post" action="<?= base_url($path . '/reschedule') ?>" class="mt-4">
                                <?= csrf_field() ?>
                                <fieldset class="flex flex-wrap gap-2">
                                    <legend class="mb-2 text-sm font-semibold"><?= esc((new DateTimeImmutable($date, $timezone))->format('l j F Y')) ?></legend>
                                    <?php foreach ($times as $t): ?>
                                        <label class="btn btn-ghost btn-xs cursor-pointer has-[:checked]:bg-primary-500 has-[:checked]:text-white">
                                            <input type="radio" name="start" value="<?= esc($t['start'], 'attr') ?>" class="sr-only" required><?= esc($twelveHour ? (new DateTimeImmutable($t['start'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('g:ia') : $t['time']) ?>
                                        </label>
                                    <?php endforeach ?>
                                </fieldset>
                                <button type="submit" class="btn btn-accent mt-4">Move my booking</button>
                            </form>
                        <?php endif ?>
                    <?php endif ?>
                    <?php if ($policies['rescheduling'] !== ''): ?><p class="mt-4 whitespace-pre-line text-xs text-slate-500"><?= esc($policies['rescheduling']) ?></p><?php endif ?>
                <?php endif ?>
            </div>

            <div class="panel mt-4" id="cancel">
                <h2 class="mb-2 font-bold">Cancel your booking</h2>
                <?php if ($cancelRefusal !== null): ?>
                    <p class="text-sm"><?= esc($cancelRefusal) ?></p>
                <?php else: ?>
                    <?php if ($policies['cancellation'] !== ''): ?><p class="mb-3 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300"><?= esc($policies['cancellation']) ?></p><?php endif ?>
                    <form method="post" action="<?= base_url($path . '/cancel') ?>">
                        <?= csrf_field() ?>
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" class="mt-1" required> Yes, cancel this booking</label>
                        <button type="submit" class="btn btn-ghost mt-3">Cancel booking</button>
                    </form>
                <?php endif ?>
            </div>
        <?php endif ?>

        <?php if ($customerHash !== ''): ?>
            <p class="mt-6 text-sm"><a class="underline" href="<?= base_url('book/customer/' . $customerHash) ?>">All your bookings with <?= esc($listing['display_name']) ?></a></p>
        <?php endif ?>
    </div>
</section>
<?= $this->endSection() ?>
