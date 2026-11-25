<?php
/**
 * The business calendar (Bookings' /appointments). The grid is drawn by the
 * scheduler script (resources/business/calendar/) from the server's render
 * models at /business/api/calendar/{view}; everything a person reads that
 * is not appointment data lives here, so the copy rules test sees it.
 *
 * @var string                                 $view day, week, month or agenda
 * @var string                                 $date Y-m-d
 * @var string                                 $today
 * @var string                                 $timezone
 * @var string                                 $open appointment hash to open, or ''
 * @var list<array<string,mixed>>              $providers in the member's scope
 * @var list<array<string,mixed>>              $services
 * @var array<int,string>                      $locations 0 = the profile's own address
 * @var list<array{value:string,label:string}> $statuses
 * @var list<array<string,mixed>>              $customFields
 * @var bool                                   $anyone may see everyone
 */
$views = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'agenda' => 'List'];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="cal" data-calendar
     data-model-url="<?= esc(base_url('business/api/calendar'), 'attr') ?>"
     data-appointment-url="<?= esc(base_url('business/api/appointments'), 'attr') ?>"
     data-customer-url="<?= esc(base_url('business/api/customers'), 'attr') ?>"
     data-customer-page="<?= esc(base_url('business/customers'), 'attr') ?>"
     data-view="<?= esc($view, 'attr') ?>" data-date="<?= esc($date, 'attr') ?>" data-today="<?= esc($today, 'attr') ?>"
     data-timezone="<?= esc($timezone, 'attr') ?>" data-open="<?= esc($open, 'attr') ?>"
     data-csrf-header="<?= esc(config('Security')->headerName, 'attr') ?>" data-csrf-hash="<?= esc(csrf_hash(), 'attr') ?>">

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn btn-ghost btn-xs" data-cal-today>Today</button>
        <div class="flex items-center">
            <button type="button" class="rounded-control p-2 hover:bg-slate-100 dark:hover:bg-slate-800" data-cal-prev aria-label="Earlier"><?= lucide('chevron-left', 'h-5 w-5') ?></button>
            <button type="button" class="rounded-control p-2 hover:bg-slate-100 dark:hover:bg-slate-800" data-cal-next aria-label="Later"><?= lucide('chevron-right', 'h-5 w-5') ?></button>
        </div>
        <h2 class="section-title" data-cal-label aria-live="polite"></h2>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <div class="cal-views" role="group" aria-label="View">
                <?php foreach ($views as $key => $label): ?>
                    <button type="button" class="cal-view-btn" data-cal-view="<?= $key ?>" aria-pressed="<?= $key === $view ? 'true' : 'false' ?>"><?= $label ?></button>
                <?php endforeach ?>
            </div>
        </div>
    </div>

    <form class="mt-3 flex flex-wrap items-end gap-3" data-cal-filters>
        <?php if (count($providers) > 1): ?>
            <label class="cal-filter">
                <span>Team member</span>
                <select name="provider_id">
                    <option value="">Everyone</option>
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= esc((string) $p['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
        <?php endif ?>
        <?php if (count($services) > 1): ?>
            <label class="cal-filter">
                <span>Service</span>
                <select name="service_id">
                    <option value="">All services</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= esc((string) $s['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
        <?php endif ?>
        <?php if (count($locations) > 1): ?>
            <label class="cal-filter">
                <span>Location</span>
                <select name="location">
                    <option value="">All locations</option>
                    <?php foreach ($locations as $id => $label): ?>
                        <option value="<?= $id === 0 ? 'main' : (int) $id ?>"><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
        <?php endif ?>
        <label class="cal-filter">
            <span>Status</span>
            <select name="status">
                <option value="">Not cancelled</option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= esc($s['value'], 'attr') ?>"><?= esc($s['label']) ?></option>
                <?php endforeach ?>
                <option value="all">All</option>
            </select>
        </label>
        <p class="min-w-0 flex-1 basis-40 pb-1 text-xs text-slate-500 dark:text-slate-400">Times are in <?= esc($timezone) ?>. Drag an appointment to move it.</p>
        <?php // Find and Book an appointment are in the top bar on every page (business/_topbar_tools.php); here Book opens the dialog below. ?>
    </form>

    <div class="cal-body mt-3" data-cal-body aria-busy="true">
        <noscript><p class="p-6 text-sm">The calendar needs JavaScript. You can still <a class="underline" href="<?= base_url('business/appointments/new') ?>">book an appointment</a> and see <a class="underline" href="<?= base_url('business/customers') ?>">your customers</a>.</p></noscript>
    </div>
    <p class="mt-2 hidden text-sm text-rose-600" data-cal-error role="alert"></p>
    <div class="cal-toast" data-cal-toast role="status" aria-live="polite" hidden></div>

    <?= view('business/_appointment_details', ['providers' => $providers, 'statuses' => $statuses, 'sendChannels' => $sendChannels]) ?>

    <?php // Book dialog: the same fields as /business/appointments/new, sent as JSON by create.js. ?>
    <dialog class="cal-dialog book-dialog" data-cal-create aria-labelledby="cal-create-title">
        <form method="post" action="<?= base_url('business/appointments/new') ?>" data-cal-create-form novalidate>
            <?= csrf_field() ?>
            <div class="mb-4 flex items-center gap-3">
                <h2 id="cal-create-title" class="flex-1 text-lg font-bold text-slate-900 dark:text-white">Book an appointment</h2>
                <button type="button" class="rounded-control p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700" data-cal-close aria-label="Close"><?= lucide('x', 'h-5 w-5') ?></button>
            </div>
            <?= view('business/_appointment_fields', ['providers' => $providers, 'services' => $services, 'customFields' => $customFields, 'statuses' => $statuses, 'old' => ['date' => $date], 'errors' => [], 'prefix' => 'cal-new', 'wide' => true]) ?>
            <p class="mt-2 hidden text-sm text-rose-600" data-create-error role="alert"></p>
            <div class="book-actions">
                <button type="submit" class="btn btn-primary" data-book-submit>Book</button>
                <button type="button" class="btn btn-ghost" data-cal-close>Cancel</button>
            </div>
        </form>
    </dialog>
</div>
<?= $this->endSection() ?>
