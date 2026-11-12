<?php
/**
 * The /business dashboard (Phase 7): what needs attention, today's figures
 * and list (business/_dash_today.php, refreshed by dashboard.js), bookings
 * waiting for confirmation, the team's free times with quick book links, and
 * the next week. Everything is the member's own scope (DashboardService).
 *
 * @var array<string,mixed>       $listing
 * @var array<string,mixed>       $member
 * @var list<array<string,mixed>> $alerts
 * @var list<array<string,mixed>> $waiting
 * @var list<array<string,mixed>> $team
 * @var list<array<string,mixed>> $coming
 * @var array<string,mixed>       $cards    and $schedule, $today, $canValue: see _dash_today.php
 * @var bool                      $canBook
 */
use App\Services\Bookings\PublicBookingService;

$roles   = config('BookingPermissions')->roleLabels;
$bookUrl = PublicBookingService::bookUrl($listing);
$states  = ['working' => 'Working now', 'break' => 'On a break', 'off' => 'Not working now', 'time_off' => 'Time off'];
$day     = static fn (string $local): string => date('D j M, H:i', (int) strtotime(str_replace('T', ' ', substr($local, 0, 16))));
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-6 text-sm text-slate-500 dark:text-slate-400">You are signed in as <?= esc($roles[$member['role']] ?? (string) $member['role']) ?>.</p>

<?php if ($alerts !== []): ?>
    <h2 class="dash-heading">Needs attention</h2>
    <ul class="biz-alerts mb-6">
        <?php foreach ($alerts as $a): ?>
            <li class="biz-alert is-<?= esc($a['level'], 'attr') ?>">
                <?= lucide($a['level'] === 'warn' ? 'circle-alert' : 'info', 'h-4 w-4 shrink-0') ?>
                <span class="min-w-0 flex-1"><?= esc($a['text']) ?></span>
                <a class="shrink-0 font-medium underline" href="<?= base_url($a['link']) ?>"><?= esc($a['action']) ?></a>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

<div data-dash-today data-refresh-url="<?= esc(base_url('business/api/dashboard/today'), 'attr') ?>" aria-live="polite">
    <?= view('business/_dash_today', ['cards' => $cards, 'schedule' => $schedule, 'today' => $today, 'canValue' => $canValue, 'canBook' => $canBook]) ?>
</div>

<div class="dash-grid mt-6">
    <div class="panel" id="waiting">
        <h2 class="strength-panel-title">Waiting for confirmation</h2>
        <?php if ($waiting === []): ?>
            <p class="stats-empty">Nothing is waiting. New online bookings show here when your booking rules ask you to confirm them.</p>
        <?php else: ?>
            <ul class="stats-list dash-list mt-3">
                <?php foreach ($waiting as $a): ?>
                    <li>
                        <a class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-300" href="<?= base_url('business/calendar?view=day&date=' . substr((string) $a['start'], 0, 10) . '&open=' . $a['hash']) ?>">
                            <?= esc($day((string) $a['start'])) ?>, <?= esc((string) ($a['customer_name'] ?? 'No customer')) ?>
                        </a>
                        <span class="stats-list-n"><?= esc((string) $a['service_name']) ?> with <?= esc((string) $a['provider_name']) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>

    <div class="panel">
        <h2 class="strength-panel-title">Coming up this week</h2>
        <?php if ($coming === []): ?>
            <p class="stats-empty">Nothing booked for the next 7 days.</p>
        <?php else: ?>
            <ul class="stats-list dash-list mt-3">
                <?php foreach ($coming as $a): ?>
                    <li>
                        <a class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-300" href="<?= base_url('business/calendar?view=day&date=' . substr((string) $a['start'], 0, 10) . '&open=' . $a['hash']) ?>">
                            <?= esc($day((string) $a['start'])) ?>, <?= esc((string) ($a['customer_name'] ?? 'No customer')) ?>
                        </a>
                        <span class="stats-list-n"><?= esc((string) $a['service_name']) ?> with <?= esc((string) $a['provider_name']) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
</div>

<?php if ($team !== []): ?>
    <h2 class="dash-heading mt-2">Free times today</h2>
    <div class="biz-team mb-6">
        <?php foreach ($team as $p): ?>
            <div class="panel">
                <div class="flex items-center gap-2">
                    <span class="biz-dot" data-color="<?= esc((string) $p['color'], 'attr') ?>"></span>
                    <h3 class="min-w-0 flex-1 truncate subsection-title"><?= esc((string) $p['name']) ?></h3>
                    <span class="biz-state is-<?= esc((string) $p['state'], 'attr') ?>"><?= esc($states[$p['state']] ?? '') ?></span>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?= esc((string) $p['service']['name']) ?></p>
                <?php if ($p['free'] !== []): ?>
                    <p class="mt-3 flex flex-wrap gap-1.5">
                        <?php foreach ($p['free'] as $time): ?>
                            <?php if ($canBook): ?>
                                <a class="chip" href="<?= base_url('business/appointments/new?' . http_build_query(['provider_id' => $p['id'], 'service_id' => $p['service']['id'], 'date' => $today, 'time' => $time])) ?>" title="Book <?= esc($time, 'attr') ?>"><?= esc($time) ?></a>
                            <?php else: ?>
                                <span class="chip"><?= esc($time) ?></span>
                            <?php endif ?>
                        <?php endforeach ?>
                    </p>
                    <?php if ($p['free_count'] > count($p['free'])): ?>
                        <p class="mt-2 text-xs"><a class="underline" href="<?= base_url('business/availability?' . http_build_query(['provider_id' => $p['id'], 'service_id' => $p['service']['id'], 'as' => 'business'])) ?>"><?= (int) $p['free_count'] - count($p['free']) ?> more today</a></p>
                    <?php endif ?>
                <?php elseif ($p['next'] !== null): ?>
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">No free time left today. Next free time online: <?= esc(date('D j M', (int) strtotime($p['next']['date']))) ?> at <?= esc((string) $p['next']['time']) ?>.</p>
                <?php else: ?>
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">No free time in the next 14 days.</p>
                <?php endif ?>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>

<div class="panel">
    <h2 class="section-title">Your business profile</h2>
    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Your name, address and opening hours come from your business profile.</p>
    <p class="mt-3 flex flex-wrap gap-2">
        <a class="btn btn-ghost btn-xs" href="<?= esc(listing_url($listing), 'attr') ?>">View profile</a>
        <a class="btn btn-ghost btn-xs" href="<?= base_url('business/profile/details') ?>">Edit profile</a>
    </p>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_actions') ?>
<?php if ($canBook): ?>
    <a class="btn btn-primary btn-xs" href="<?= base_url('business/appointments/new') ?>"><?= lucide('plus', 'h-4 w-4') ?>Book an appointment</a>
<?php endif ?>
<?php if ($bookUrl !== ''): ?>
    <a class="btn btn-ghost btn-xs" href="<?= esc($bookUrl, 'attr') ?>">Your booking page</a>
<?php endif ?>
<?= $this->endSection() ?>
