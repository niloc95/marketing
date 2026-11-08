<?php
/**
 * One customer: their appointments, what they told you, edit and delete.
 *
 * @var array<string,mixed>                                       $customer
 * @var array<string,mixed>                                       $old
 * @var array<string,string>                                      $errors
 * @var list<array<string,mixed>>                                 $customFields
 * @var list<array<string,mixed>>                                 $history
 * @var array{total:int,upcoming:int,completed:int,cancelled:int,no_show:int} $stats
 * @var array{upcoming:int,past:int,can_delete:bool}|null         $impact null when the member may not delete
 * @var string                                                    $timezone
 * @var bool                                                      $optedOut  stopped this business's booking emails
 */
use App\Services\Bookings\AppointmentStatus;

$tz    = new DateTimeZone($timezone);
$local = static fn (string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('D j M Y, H:i');
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('bookings/customers') ?>">Customers</a></p>
<div class="flex flex-wrap items-center gap-3">
    <h1 class="text-xl font-bold text-slate-900 dark:text-white"><?= esc($customer['name']) ?></h1>
    <a href="<?= base_url('bookings/appointments/new?customer=' . $customer['hash']) ?>" class="btn btn-primary btn-xs ml-auto"><?= lucide('plus', 'h-4 w-4') ?> Book</a>
</div>
<p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
    <?= esc(implode(' · ', array_filter([(string) ($customer['email'] ?? ''), (string) ($customer['phone'] ?? '')]))) ?>
</p>
<?php if (! empty($optedOut)): ?>
    <div class="alert alert-info mt-3">
        <p>This customer asked us to stop your booking emails to them.</p>
        <form method="post" action="<?= base_url('bookings/customers/' . $customer['hash'] . '/emails') ?>" class="mt-2">
            <?= csrf_field() ?>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="yes" required> They asked me to send them again</label>
            <button type="submit" class="btn btn-ghost btn-xs mt-2">Send booking emails again</button>
        </form>
    </div>
<?php endif ?>

<dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
    <?php foreach (['total' => 'Appointments', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No show'] as $k => $label): ?>
        <div class="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
            <dt class="text-xs text-slate-500 dark:text-slate-400"><?= $label ?></dt>
            <dd class="text-lg font-bold"><?= (int) $stats[$k] ?></dd>
        </div>
    <?php endforeach ?>
</dl>

<h2 class="mt-8 mb-3 text-lg font-bold text-slate-900 dark:text-white">Appointments</h2>
<?php if ($history === []): ?>
    <p class="text-sm text-slate-500 dark:text-slate-400">None yet.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>When</th><th>Service</th><th>With</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($history as $a): ?>
                    <tr>
                        <td class="whitespace-nowrap"><a class="underline" href="<?= base_url('bookings/calendar?view=day&date=' . substr((new DateTimeImmutable((string) $a['start_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d'), 0, 10) . '&open=' . $a['hash']) ?>"><?= esc($local((string) $a['start_at'])) ?></a></td>
                        <td><?= esc((string) $a['service_name']) ?></td>
                        <td><?= esc((string) $a['provider_name']) ?></td>
                        <td><?= esc(AppointmentStatus::short((string) $a['status'])) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<form method="post" action="<?= base_url('bookings/customers/' . $customer['hash']) ?>" class="form-card form-card-narrow mt-8" id="edit">
    <?= csrf_field() ?>
    <h2 class="mb-4 text-lg font-bold text-slate-900 dark:text-white">Details</h2>
    <?= view('business/_customer_fields', ['old' => $old, 'errors' => $errors, 'customFields' => $customFields]) ?>
    <button type="submit" class="btn btn-primary">Save</button>
</form>

<?php if ($impact !== null): ?>
    <section class="form-card form-card-narrow mt-8" id="their-data">
        <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Their data</h2>
        <p class="text-sm">If they ask what you hold about them, download it all in one file: their details, answers, appointments, deposits and the messages sent to them. We keep customers for <?= (int) config('Bookings')->retentionMonths ?> months after their last appointment, then remove their personal details.</p>
        <a class="btn btn-ghost mt-4" href="<?= base_url('bookings/customers/' . $customer['hash'] . '/export') ?>">Download their data</a>
    </section>
    <section class="form-card form-card-narrow mt-8" id="delete">
        <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Delete this customer</h2>
        <?php if (! $impact['can_delete']): ?>
            <p class="text-sm">They have <?= $impact['upcoming'] ?> upcoming <?= $impact['upcoming'] === 1 ? 'appointment' : 'appointments' ?>. Cancel or move <?= $impact['upcoming'] === 1 ? 'it' : 'them' ?> first.</p>
        <?php else: ?>
            <p class="text-sm">This removes their name, contact details, notes and answers.<?php if ($impact['past'] > 0): ?> Their <?= $impact['past'] ?> past <?= $impact['past'] === 1 ? 'appointment stays' : 'appointments stay' ?> in your calendar without their name.<?php endif ?> You cannot undo this.</p>
            <form method="post" action="<?= base_url('bookings/customers/' . $customer['hash'] . '/delete') ?>" class="mt-4">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost text-rose-700">Delete customer</button>
            </form>
        <?php endif ?>
    </section>
<?php endif ?>
<?= $this->endSection() ?>
