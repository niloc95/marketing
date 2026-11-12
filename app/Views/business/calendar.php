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
            <a href="<?= base_url('business/appointments/new') ?>" class="btn btn-primary btn-xs" data-cal-book><?= lucide('plus', 'h-4 w-4') ?> Book</a>
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
        <?php // Results open over the grid, so the grid keeps its size. ?>
        <div class="relative ml-auto">
            <label class="cal-filter">
                <span>Find</span>
                <input type="text" name="q" placeholder="Name, email, phone or reference" autocomplete="off" data-cal-find>
            </label>
            <ul class="cal-find-results" data-cal-find-results hidden></ul>
        </div>
    </form>

    <div class="cal-body mt-3" data-cal-body aria-busy="true">
        <noscript><p class="p-6 text-sm">The calendar needs JavaScript. You can still <a class="underline" href="<?= base_url('business/appointments/new') ?>">book an appointment</a> and see <a class="underline" href="<?= base_url('business/customers') ?>">your customers</a>.</p></noscript>
    </div>
    <p class="mt-2 hidden text-sm text-rose-600" data-cal-error role="alert"></p>
    <div class="cal-toast" data-cal-toast role="status" aria-live="polite" hidden></div>

    <?php // Details panel: filled by details.js from /business/api/appointments/{id}. ?>
    <dialog class="cal-dialog" data-cal-details aria-labelledby="cal-details-title">
        <div class="flex items-start gap-3">
            <span class="cal-swatch mt-1.5" data-d-swatch></span>
            <div class="min-w-0 flex-1">
                <h2 id="cal-details-title" class="text-lg font-bold text-slate-900 dark:text-white" data-d="title"></h2>
                <p class="text-sm text-slate-600 dark:text-slate-300"><span data-d="service_name"></span> with <span data-d="provider_name"></span></p>
            </div>
            <button type="button" class="rounded-control p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700" data-cal-close aria-label="Close"><?= lucide('x', 'h-5 w-5') ?></button>
        </div>
        <dl class="cal-facts mt-4">
            <dt><?= lucide('clock', 'h-4 w-4') ?><span class="sr-only">When</span></dt><dd data-d="when"></dd>
            <dt><?= lucide('map-pin', 'h-4 w-4') ?><span class="sr-only">Where</span></dt><dd data-d="where"></dd>
            <dt><?= lucide('mail', 'h-4 w-4') ?><span class="sr-only">Email</span></dt><dd data-d="email"></dd>
            <dt><?= lucide('phone', 'h-4 w-4') ?><span class="sr-only">Phone</span></dt><dd data-d="phone"></dd>
        </dl>
        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Reference <span data-d="reference"></span></p>
        <dl class="mt-3 space-y-2 text-sm" data-d-fields></dl>
        <p class="mt-3"><a class="underline text-sm" href="#" data-d-customer><?= lucide('user', 'mr-1 inline h-4 w-4') ?>Customer details and history</a></p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div class="field mb-0">
                <label for="cal-d-status">Status</label>
                <select id="cal-d-status" data-d-status>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= esc($s['value'], 'attr') ?>"><?= esc($s['label']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field mb-0">
                <label for="cal-d-notes">Notes <span class="font-normal text-slate-500">(only your team)</span></label>
                <textarea id="cal-d-notes" rows="2" maxlength="5000" data-d-notes></textarea>
                <button type="button" class="btn btn-ghost btn-xs mt-1" data-d-save-notes>Save notes</button>
            </div>
        </div>

        <?php // Deposit (Phase 8): shown when the appointment has one. ?>
        <div class="mt-4 rounded-inner border border-slate-200 p-3 text-sm dark:border-slate-700" data-d-payment hidden>
            <p class="font-semibold" data-d-payment-label></p>
            <ul class="mt-1 space-y-0.5 text-xs text-slate-600 dark:text-slate-300" data-d-payment-list></ul>
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" class="btn btn-ghost btn-xs" data-d-mark-paid hidden>Mark deposit paid</button>
                <button type="button" class="btn btn-ghost btn-xs" data-d-mark-refunded hidden>Mark refunded</button>
            </div>
        </div>

        <details class="mt-4" data-d-move>
            <summary class="cursor-pointer text-sm font-semibold text-brand-ocean dark:text-white">Move to another time</summary>
            <?php // Filled by details.js from /business/api/appointments/{id}/free: the
                  // next free times, and the chosen date's, each with its place. ?>
            <div class="free-times" data-d-free>
                <p class="free-times-heading">Next free times</p>
                <div class="free-times-list" data-d-next></div>
                <p class="free-times-heading" data-d-day-heading hidden></p>
                <div class="free-times-list" data-d-day hidden></div>
                <p class="free-times-chosen" data-d-chosen hidden></p>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="field mb-0"><label for="cal-d-date">Date</label><input id="cal-d-date" type="date" data-d-date></div>
                <div class="field mb-0"><label for="cal-d-time">Time</label><input id="cal-d-time" type="time" step="300" data-d-time></div>
                <div class="field mb-0">
                    <label for="cal-d-provider">With</label>
                    <select id="cal-d-provider" data-d-provider>
                        <?php foreach ($providers as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= esc((string) $p['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-xs mt-3" data-d-save-move>Move</button>
        </details>
        <details class="mt-4" data-d-messages>
            <summary class="cursor-pointer text-sm font-semibold text-brand-ocean dark:text-white">Messages</summary>
            <ul class="mt-2 space-y-1 text-sm text-slate-600 dark:text-slate-300" data-d-message-list></ul>
            <p class="mt-2"><a class="btn btn-ghost btn-xs" href="#" target="_blank" rel="noopener" data-d-whatsapp hidden><?= lucide('message-circle', 'h-4 w-4') ?> Open a WhatsApp chat</a></p>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="field mb-0">
                    <label for="cal-d-event">Send again</label>
                    <select id="cal-d-event" data-d-event>
                        <?php foreach (\Config\BookingNotifications::EVENTS as $k => $label): ?>
                            <option value="<?= $k ?>"><?= esc($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="field mb-0">
                    <label for="cal-d-channel">By</label>
                    <select id="cal-d-channel" data-d-channel>
                        <?php foreach ($sendChannels as $k => $label): ?>
                            <option value="<?= $k ?>"><?= esc($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-xs mt-3" data-d-send>Send</button>
        </details>
        <p class="mt-3 hidden text-sm text-rose-600" data-d-error role="alert"></p>
    </dialog>

    <?php // Book dialog: the same fields as /business/appointments/new, sent as JSON by create.js. ?>
    <dialog class="cal-dialog" data-cal-create aria-labelledby="cal-create-title">
        <form method="post" action="<?= base_url('business/appointments/new') ?>" data-cal-create-form novalidate>
            <?= csrf_field() ?>
            <div class="mb-4 flex items-center gap-3">
                <h2 id="cal-create-title" class="flex-1 text-lg font-bold text-slate-900 dark:text-white">Book an appointment</h2>
                <button type="button" class="rounded-control p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700" data-cal-close aria-label="Close"><?= lucide('x', 'h-5 w-5') ?></button>
            </div>
            <?= view('business/_appointment_fields', ['providers' => $providers, 'services' => $services, 'customFields' => $customFields, 'statuses' => $statuses, 'old' => ['date' => $date], 'errors' => [], 'prefix' => 'cal-new']) ?>
            <p class="mt-2 hidden text-sm text-rose-600" data-create-error role="alert"></p>
            <div class="mt-4 flex gap-3">
                <button type="submit" class="btn btn-primary">Book</button>
                <button type="button" class="btn btn-ghost" data-cal-close>Cancel</button>
            </div>
        </form>
    </dialog>
</div>
<?= $this->endSection() ?>
