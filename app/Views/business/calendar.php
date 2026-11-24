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

    <?php // Details panel: filled by details.js from /business/api/appointments/{id}.
          // Two views in one dialog: the appointment, and Reschedule (Back returns). ?>
    <dialog class="cal-dialog appt-dialog" data-cal-details aria-labelledby="cal-details-title">
        <header class="appt-head">
            <span class="cal-swatch appt-swatch" data-d-swatch></span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h2 id="cal-details-title" class="appt-title" data-d="title"></h2>
                    <span class="appt-pill" data-d-pill></span>
                </div>
                <p class="appt-sub"><span data-d="service_name"></span> with <span data-d="provider_name"></span></p>
            </div>
            <button type="button" class="appt-close" data-cal-close aria-label="Close"><?= lucide('x', 'h-5 w-5') ?></button>
        </header>

        <div class="appt-body" data-d-view="main">
            <div class="appt-actions">
                <button type="button" class="btn btn-primary btn-xs" data-d-reschedule><?= lucide('calendar-days', 'h-4 w-4') ?> Reschedule</button>
                <a class="btn btn-ghost btn-xs" href="#" data-d-customer><?= lucide('user', 'h-4 w-4') ?> Customer</a>
                <a class="btn btn-ghost btn-xs" href="#" data-d-call><?= lucide('phone', 'h-4 w-4') ?> Call</a>
                <button type="button" class="btn btn-ghost btn-xs" aria-expanded="false" aria-controls="cal-d-compose" data-d-email><?= lucide('mail', 'h-4 w-4') ?> Email <?= lucide('chevron-down', 'appt-chevron h-4 w-4') ?></button>
                <a class="btn btn-ghost btn-xs" href="#" target="_blank" rel="noopener" data-d-whatsapp><?= lucide('message-circle', 'h-4 w-4') ?> WhatsApp</a>
            </div>
            <p class="appt-note" data-d-move-note hidden></p>

            <?php // Email the customer: rolls down under the actions. Sent by
                  // bookings:dispatch in the standard booking email, details added. ?>
            <form class="appt-compose" id="cal-d-compose" data-d-compose hidden novalidate>
                <p class="appt-compose-to"><span class="appt-label">To</span> <span data-d-compose-to></span></p>
                <div class="field mb-0 mt-3">
                    <label for="cal-d-subject">Subject</label>
                    <input id="cal-d-subject" type="text" maxlength="<?= \App\Services\Bookings\AppointmentService::EMAIL_SUBJECT_MAX ?>" required data-d-subject>
                </div>
                <div class="field mb-0 mt-3">
                    <label for="cal-d-message">Message</label>
                    <textarea id="cal-d-message" rows="5" maxlength="<?= \App\Services\Bookings\AppointmentService::EMAIL_BODY_MAX ?>" required data-d-message></textarea>
                </div>
                <p class="appt-hint">The appointment details go under your message, in your usual booking email. Replies come to you.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-xs" data-d-compose-send>Send email</button>
                    <button type="button" class="btn btn-ghost btn-xs" data-d-compose-cancel>Cancel</button>
                </div>
            </form>

            <dl class="appt-card appt-facts">
                <div class="appt-fact"><dt><?= lucide('clock', 'h-4 w-4') ?><span>When</span></dt><dd data-d="when"></dd></div>
                <div class="appt-fact"><dt><?= lucide('map-pin', 'h-4 w-4') ?><span>Where</span></dt><dd data-d="where"></dd></div>
                <div class="appt-fact"><dt><?= lucide('mail', 'h-4 w-4') ?><span>Email</span></dt><dd data-d="email"></dd></div>
                <div class="appt-fact"><dt><?= lucide('phone', 'h-4 w-4') ?><span>Phone</span></dt><dd data-d="phone"></dd></div>
            </dl>

            <section class="appt-section" data-d-fields-box>
                <h3 class="appt-label">Booking form answers</h3>
                <dl class="appt-answers" data-d-fields></dl>
            </section>

            <section class="appt-section appt-grid">
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
                    <div class="mt-2 flex justify-end"><button type="button" class="btn btn-ghost btn-xs" data-d-save-notes>Save notes</button></div>
                </div>
            </section>

            <?php // Deposit (Phase 8): shown when the appointment has one. ?>
            <section class="appt-section appt-card text-sm" data-d-payment hidden>
                <p class="font-semibold" data-d-payment-label></p>
                <ul class="mt-1 space-y-0.5 text-xs text-slate-600 dark:text-slate-300" data-d-payment-list></ul>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-ghost btn-xs" data-d-mark-paid hidden>Mark deposit paid</button>
                    <button type="button" class="btn btn-ghost btn-xs" data-d-mark-refunded hidden>Mark refunded</button>
                </div>
            </section>

            <details class="appt-section appt-more" data-d-messages>
                <summary>Messages</summary>
                <ul class="mt-2 space-y-1 text-sm text-slate-600 dark:text-slate-300" data-d-message-list></ul>
                <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
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
                    <button type="button" class="btn btn-primary btn-xs" data-d-send>Send</button>
                </div>
            </details>

            <p class="appt-ref">Reference <span data-d="reference"></span></p>
        </div>

        <div class="appt-body" data-d-view="move" hidden>
            <button type="button" class="appt-back" data-d-back><?= lucide('arrow-left', 'h-4 w-4') ?> Back</button>
            <h3 class="appt-move-title">Move to another time</h3>
            <p class="appt-card appt-now"><span class="appt-label">Now</span> <span data-d-now></span></p>
            <p class="appt-note" data-d-passed hidden>This time has passed. Choose a new one below.</p>
            <div class="appt-grid">
                <div class="field mb-0">
                    <label for="cal-d-provider">With</label>
                    <select id="cal-d-provider" data-d-provider>
                        <?php foreach ($providers as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= esc((string) $p['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="field mb-0"><label for="cal-d-date">Date</label><input id="cal-d-date" type="date" data-d-date></div>
            </div>
            <?php // Filled by details.js from /business/api/appointments/{id}/free: the
                  // next free times, and the chosen date's, each with its place. ?>
            <div class="free-times" data-d-free>
                <p class="free-times-heading">Next free times</p>
                <div class="free-times-list" data-d-next></div>
                <p class="free-times-heading" data-d-day-heading hidden></p>
                <div class="free-times-list" data-d-day hidden></div>
            </div>
            <div class="field mb-0 mt-4 max-w-[12rem]"><label for="cal-d-time">Or type a time</label><input id="cal-d-time" type="time" step="300" data-d-time></div>
            <p class="appt-chosen" data-d-chosen hidden></p>
            <div class="appt-foot">
                <button type="button" class="btn btn-primary" data-d-save-move>Move appointment</button>
                <button type="button" class="btn btn-ghost" data-d-back>Cancel</button>
            </div>
        </div>
        <p class="appt-error hidden" data-d-error role="alert"></p>
    </dialog>

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
