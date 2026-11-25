<?php
/**
 * The /business dashboard (Phase 7), as cards: a greeting, what needs
 * attention, the figures, then today (one column per person) beside a
 * column of what is waiting for confirmation, each person's free times and
 * the week ahead. Everything is the member's own scope (DashboardService).
 *
 * The figures and today are business/_dash_today.php, refreshed by
 * dashboard.js; their wrapper is display: contents, so they sit in this
 * page's grid. Every appointment card opens the details dialog here
 * (appointment-host.js), never the calendar, for members who may open them.
 *
 * @var array<string,mixed>       $listing
 * @var array<string,mixed>       $member
 * @var string                    $name     the signed in person's name
 * @var int                       $hour     local hour, for the greeting
 * @var list<array<string,mixed>> $alerts
 * @var list<array<string,mixed>> $waiting
 * @var list<array<string,mixed>> $team
 * @var list<array<string,mixed>> $coming
 * @var array<string,mixed>       $cards    and $schedule, $today, $canValue: see _dash_today.php
 * @var bool                      $canBook
 * @var list<array<string,mixed>>              $providers    for the dialog's Reschedule
 * @var list<array{value:string,label:string}> $statuses
 * @var array<string,string>                   $sendChannels
 */

$roles  = config('BookingPermissions')->roleLabels;
$states = ['working' => 'Working now', 'break' => 'On a break', 'off' => 'Not working now', 'time_off' => 'Time off'];
$hello  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$day    = static fn (string $local): string => date('D j M', (int) strtotime(substr($local, 0, 10)));
$hm     = static fn (string $local): string => substr($local, 11, 5);

// The week ahead, grouped by day.
$comingDays = [];
foreach ($coming as $a) {
    $comingDays[$day((string) $a['start'])][] = $a;
}
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="dash-page" data-appt-host
     data-appointment-url="<?= esc(base_url('business/api/appointments'), 'attr') ?>"
     data-customer-page="<?= esc(base_url('business/customers'), 'attr') ?>"
     data-calendar-page="<?= esc(base_url('business/calendar'), 'attr') ?>"
     data-csrf-header="<?= esc(config('Security')->headerName, 'attr') ?>" data-csrf-hash="<?= esc(csrf_hash(), 'attr') ?>">

    <header class="dash-hello">
        <div class="min-w-0">
            <h2 class="dash-hello-title"><?= esc($hello . (trim($name) !== '' ? ', ' . trim($name) : '')) ?></h2>
            <p class="dash-hello-sub"><?= esc(date('l j F', (int) strtotime($today))) ?> · <?= esc((string) $listing['display_name']) ?> · <?= esc($roles[$member['role']] ?? (string) $member['role']) ?></p>
        </div>
        <a class="btn btn-ghost btn-xs" href="<?= esc(listing_url($listing), 'attr') ?>"><?= lucide('external-link', 'h-4 w-4') ?> View profile</a>
    </header>

    <?php if ($alerts !== []): ?>
        <ul class="biz-alerts dash-alerts" aria-label="Needs attention">
            <?php foreach ($alerts as $a): ?>
                <li class="biz-alert is-<?= esc($a['level'], 'attr') ?>">
                    <?= lucide($a['level'] === 'warn' ? 'circle-alert' : 'info', 'h-4 w-4 shrink-0') ?>
                    <span class="min-w-0 flex-1"><?= esc($a['text']) ?></span>
                    <a class="shrink-0 font-medium underline" href="<?= base_url($a['link']) ?>"><?= esc($a['action']) ?></a>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <div class="dash-layout">
        <div class="dash-live" data-dash-today data-refresh-url="<?= esc(base_url('business/api/dashboard/today'), 'attr') ?>">
            <?= view('business/_dash_today', ['cards' => $cards, 'schedule' => $schedule, 'today' => $today, 'canValue' => $canValue, 'canBook' => $canBook]) ?>
        </div>

        <aside class="dash-side">
            <section class="panel" id="waiting">
                <h2 class="section-title">Waiting for confirmation</h2>
                <?php if ($waiting === []): ?>
                    <p class="dash-empty-sm"><?= lucide('check', 'h-4 w-4') ?> Nothing waiting.</p>
                <?php else: ?>
                    <div class="dash-appts">
                        <?php foreach ($waiting as $a): ?>
                            <?= view('business/_dash_appt', ['a' => $a, 'when' => $day((string) $a['start']) . ', ' . $hm((string) $a['start']), 'sub' => $a['service_name'] . ' with ' . $a['provider_name'], 'canOpen' => $canBook], ['saveData' => false]) ?>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </section>

            <?php if ($team !== []): ?>
                <section class="panel">
                    <h2 class="section-title">Free times today</h2>
                    <div class="dash-free">
                        <?php foreach ($team as $p): ?>
                            <div class="dash-free-person">
                                <p class="dash-person-name">
                                    <span class="biz-dot" data-color="<?= esc((string) $p['color'], 'attr') ?>"></span>
                                    <span class="min-w-0 flex-1 truncate"><?= esc((string) $p['name']) ?></span>
                                    <span class="biz-state is-<?= esc((string) $p['state'], 'attr') ?>"><?= esc($states[$p['state']] ?? '') ?></span>
                                </p>
                                <p class="dash-free-service"><?= esc((string) $p['service']['name']) ?></p>
                                <?php if ($p['free'] !== []): ?>
                                    <p class="mt-2 flex flex-wrap gap-1.5">
                                        <?php foreach ($p['free'] as $time): ?>
                                            <?php if ($canBook): ?>
                                                <a class="chip" href="<?= base_url('business/appointments/new?' . http_build_query(['provider_id' => $p['id'], 'service_id' => $p['service']['id'], 'date' => $today, 'time' => $time])) ?>" title="Book <?= esc($time, 'attr') ?>"><?= esc($time) ?></a>
                                            <?php else: ?>
                                                <span class="chip"><?= esc($time) ?></span>
                                            <?php endif ?>
                                        <?php endforeach ?>
                                        <?php if ($p['free_count'] > count($p['free'])): ?>
                                            <a class="chip dash-chip-more" href="<?= base_url('business/availability?' . http_build_query(['provider_id' => $p['id'], 'service_id' => $p['service']['id'], 'as' => 'business'])) ?>"><?= (int) $p['free_count'] - count($p['free']) ?> more</a>
                                        <?php endif ?>
                                    </p>
                                <?php elseif ($p['next'] !== null): ?>
                                    <p class="dash-empty-sm mt-1">Nothing left today. Next: <?= esc(date('D j M', (int) strtotime($p['next']['date']))) ?> at <?= esc((string) $p['next']['time']) ?>.</p>
                                <?php else: ?>
                                    <p class="dash-empty-sm mt-1">No free time in the next 14 days.</p>
                                <?php endif ?>
                            </div>
                        <?php endforeach ?>
                    </div>
                </section>
            <?php endif ?>

            <section class="panel">
                <div class="dash-panel-head">
                    <h2 class="section-title mb-0">Coming up this week</h2>
                    <?php if ($canBook): ?><a class="dash-more" href="<?= base_url('business/calendar?view=week') ?>">Week</a><?php endif ?>
                </div>
                <?php if ($comingDays === []): ?>
                    <p class="dash-empty-sm">Nothing booked for the next 7 days.</p>
                <?php else: ?>
                    <?php foreach ($comingDays as $label => $list): ?>
                        <h3 class="dash-day"><?= esc($label) ?></h3>
                        <div class="dash-appts">
                            <?php foreach ($list as $a): ?>
                                <?= view('business/_dash_appt', ['a' => $a, 'when' => $hm((string) $a['start']), 'sub' => $a['service_name'] . ' with ' . $a['provider_name'], 'canOpen' => $canBook, 'compact' => true], ['saveData' => false]) ?>
                            <?php endforeach ?>
                        </div>
                    <?php endforeach ?>
                <?php endif ?>
            </section>
        </aside>
    </div>

    <?php if ($canBook): ?>
        <div class="cal-toast" data-cal-toast role="status" aria-live="polite" hidden></div>
        <?= view('business/_appointment_details', ['providers' => $providers, 'statuses' => $statuses, 'sendChannels' => $sendChannels, 'calendarLink' => true]) ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

<?php // Book an appointment and Your booking page are in the top bar on every page (business/_topbar_tools.php). ?>
