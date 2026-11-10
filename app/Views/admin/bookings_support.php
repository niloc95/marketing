<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Support view | Admin']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * One business's next two weeks, opened for support (AdminBookings::support).
 * The opening and its reason are in the business's audit log.
 *
 * @var array<string,mixed>       $listing
 * @var string                    $reason
 * @var list<array<string,mixed>> $rows
 * @var list<array<string,mixed>> $problems
 * @var array<string,mixed>       $plan
 */
$tz   = \App\Services\Bookings\BusinessLocale::zone($listing);
$when = static fn (string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('D j M H:i');
?>
<section class="section">
    <div class="container">
        <p class="mb-2 text-sm"><a class="underline" href="<?= base_url('admin/bookings') ?>">Online bookings</a></p>
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white"><?= esc((string) $listing['display_name']) ?>: support view</h1>
        <p class="alert alert-info mt-2 text-sm">Opened because: <?= esc($reason) ?>. This is in the business's audit log. Read only, the next 14 days.</p>

        <h2 class="mb-2 mt-6 font-bold">Setup</h2>
        <?php if ($problems === []): ?>
            <p class="text-sm">Nothing to fix.</p>
        <?php else: ?>
            <ul class="list-disc pl-5 text-sm">
                <?php foreach ($problems as $p): ?><li><?= esc($p['text']) ?></li><?php endforeach ?>
            </ul>
        <?php endif ?>

        <h2 class="mb-2 mt-6 font-bold">Appointments</h2>
        <?php if ($rows === []): ?>
            <p class="text-sm">None in the next 14 days.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="text-slate-500"><th class="py-1 pr-3">When</th><th class="py-1 pr-3">Service</th><th class="py-1 pr-3">With</th><th class="py-1 pr-3">Status</th><th class="py-1 pr-3">Booked</th><th class="py-1">Reference</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $a): ?>
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="py-1 pr-3 whitespace-nowrap"><?= esc($when((string) $a['start_at'])) ?></td>
                                <td class="py-1 pr-3"><?= esc((string) $a['service_name']) ?></td>
                                <td class="py-1 pr-3"><?= esc((string) $a['provider_name']) ?></td>
                                <td class="py-1 pr-3"><?= esc(\App\Services\Bookings\AppointmentStatus::label((string) $a['status'])) ?></td>
                                <td class="py-1 pr-3"><?= esc((string) $a['source']) ?></td>
                                <td class="py-1"><code class="text-xs"><?= esc((string) $a['reference']) ?></code></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-500">Customer names and contact details are left out on purpose.</p>
        <?php endif ?>
    </div>
</section>
<?= $this->endSection() ?>
