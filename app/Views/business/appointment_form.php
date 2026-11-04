<?php
/**
 * Book an appointment without the calendar script (routes.md:
 * /business/appointments/new). The calendar's Book dialog posts the same
 * fields as JSON.
 *
 * @var list<array<string,mixed>>              $providers
 * @var list<array<string,mixed>>              $services
 * @var list<array<string,mixed>>              $customFields
 * @var list<array{value:string,label:string}> $statuses
 * @var array<string,mixed>                    $old
 * @var array<string,string>                   $errors
 * @var string                                 $timezone
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('business/calendar') ?>">Calendar</a></p>
<form method="post" action="<?= base_url('business/appointments/new') ?>" class="form-card form-card-narrow">
    <?= csrf_field() ?>
    <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Book an appointment</h1>
    <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">Times are in <?= esc($timezone) ?>. You can book any free time in working hours.</p>
    <?php if ($services === [] || $providers === []): ?>
        <p class="text-sm">Add a service and a team member who offers it first.</p>
    <?php else: ?>
        <?= view('business/_appointment_fields', ['providers' => $providers, 'services' => $services, 'customFields' => $customFields, 'statuses' => $statuses, 'old' => $old, 'errors' => $errors, 'prefix' => 'apt']) ?>
        <button type="submit" class="btn btn-primary">Book</button>
    <?php endif ?>
</form>
<?= $this->endSection() ?>
