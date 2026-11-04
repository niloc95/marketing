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
 * @var string                    $from
 * @var list<string>              $days
 * @var string|null               $date
 * @var list<array<string,mixed>> $times
 * @var bool                      $twelveHour
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
                <p class="font-semibold">Booked. Your reference is <?= esc((string) $a['reference']) ?>.</p>
                <p class="mt-1 text-sm">Keep this page's address: it is how you change or cancel your booking. Anyone with the link can, so keep it to yourself.</p>
            </div>
        <?php endif ?>
        <p class="mb-2 text-sm"><a class="underline" href="<?= listing_url($listing) ?>"><?= esc($listing['display_name']) ?></a></p>
        <h1 class="mb-1 text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc((string) $a['service_name']) ?></h1>
        <p class="text-slate-700 dark:text-slate-200"><?= view('book/_when', ['a' => $a, 'timezone' => $timezone, 'twelveHour' => $twelveHour]) ?></p>
        <p class="text-sm text-slate-500 dark:text-slate-400">with <?= esc((string) $a['provider_name']) ?><?= $a['location_name'] ? ', ' . esc((string) $a['location_name']) : '' ?><?= $a['location_address'] ? ', ' . esc((string) $a['location_address']) : '' ?></p>
        <p class="mt-3 text-sm font-semibold"><?= esc($status[$a['status']] ?? AppointmentStatus::label((string) $a['status'])) ?></p>
        <p class="mt-1 text-xs text-slate-500">Reference <?= esc((string) $a['reference']) ?> · Times in <?= esc($timezone->getName()) ?></p>

        <?php if (in_array($a['status'], AppointmentStatus::UPCOMING, true)): ?>
            <div class="panel mt-6" id="change">
                <h2 class="mb-2 font-bold">Change your booking</h2>
                <?php if ($moveRefusal !== null): ?>
                    <p class="text-sm"><?= esc($moveRefusal) ?></p>
                <?php else: ?>
                    <div class="mb-3 flex items-center gap-2 text-sm">
                        <?php if ($from > $today): ?><a class="underline" href="<?= base_url($path) . '?from=' . max($today, (new DateTimeImmutable($from, $timezone))->modify('-14 days')->format('Y-m-d')) ?>#change">Earlier</a><?php endif ?>
                        <a class="underline" href="<?= base_url($path) . '?from=' . (new DateTimeImmutable($from, $timezone))->modify('+14 days')->format('Y-m-d') ?>#change">Later</a>
                    </div>
                    <?php if ($days === []): ?>
                        <p class="text-sm">No free times in these days.</p>
                    <?php else: ?>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($days as $d): ?>
                                <a class="<?= $d === $date ? 'btn btn-primary' : 'btn btn-ghost' ?> btn-xs" href="<?= base_url($path) . '?from=' . $from . '&date=' . $d ?>#change"><?= esc($tzDay($d)) ?></a>
                            <?php endforeach ?>
                        </div>
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
