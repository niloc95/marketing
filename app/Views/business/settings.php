<?php
/**
 * @var string                    $tab
 * @var array<string,string>      $tabs
 * @var array<string,mixed>       $listing
 * @var array<string,string>      $settings
 * @var array<string,list<string>> $choices
 */
$labels = [
    '24h' => '24 hour clock (14:30)', '12h' => '12 hour clock (2:30 pm)',
    'monday' => 'Monday', 'sunday' => 'Sunday',
];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<h1 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Settings</h1>
<nav class="mb-5 flex gap-1 border-b border-slate-200 dark:border-slate-800" aria-label="Settings">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= base_url('business/settings/' . $key) ?>" class="<?= $key === $tab ? 'border-brand-ocean text-brand-ocean dark:border-white dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400' ?> -mb-px border-b-2 px-3 py-2 text-sm font-medium"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
    <?php endforeach ?>
</nav>

<?php if ($tab === 'general'): ?>
    <div class="max-w-xl space-y-5">
        <div class="panel">
            <h2 class="font-bold">From your business profile</h2>
            <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                <dt class="text-slate-500 dark:text-slate-400">Name</dt><dd class="col-span-2"><?= esc((string) $listing['display_name']) ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Email</dt><dd class="col-span-2"><?= esc((string) $listing['email']) ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Phone</dt><dd class="col-span-2"><?= esc((string) ($listing['phone'] ?? '')) ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Time zone</dt><dd class="col-span-2"><?= esc((string) ($listing['timezone'] ?: 'Africa/Johannesburg')) ?></dd>
            </dl>
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">To change these, or your opening hours, <a class="underline" href="<?= base_url('manage') ?>">edit your business profile</a>.</p>
        </div>

        <div class="panel">
            <h2 class="font-bold">Turn off online bookings</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Customers can no longer book, and your team can no longer sign in to this business. Nothing is deleted: turn them on again from your business profile.</p>
            <form method="post" action="<?= base_url('business/turn-off') ?>" class="mt-3">
                <?= csrf_field() ?>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="yes" required> I want to turn off online bookings</label>
                <button type="submit" class="btn btn-ghost mt-3">Turn off</button>
            </form>
        </div>
    </div>
<?php elseif ($tab === 'booking'): ?>
    <form method="post" action="<?= base_url('business/settings/booking') ?>" class="form-card form-card-narrow">
        <?= csrf_field() ?>
        <div class="field">
            <label for="biz-step">Times offered every</label>
            <select id="biz-step" name="slot_step">
                <?php foreach (['0' => 'The length of the service', '10' => '10 minutes', '15' => '15 minutes', '20' => '20 minutes', '30' => '30 minutes', '60' => 'Hour'] as $v => $label): ?>
                    <option value="<?= $v ?>"<?= $settings['booking.slot_step'] === (string) $v ? ' selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach ?>
            </select>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">With a 45 minute service, "the length of the service" offers 09:00, 09:45, 10:30; "15 minutes" offers 09:00, 09:15, 09:30.</p>
        </div>
        <div class="field">
            <label for="biz-lead">Minimum notice, in hours</label>
            <input id="biz-lead" type="number" name="lead_hours" min="0" max="336" value="<?= intdiv((int) $settings['booking.lead_minutes'], 60) ?>">
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Customers cannot book a time sooner than this. 0 lets them book any time still to come today.</p>
        </div>
        <div class="field">
            <label for="biz-buffer">Free minutes after every booking</label>
            <input id="biz-buffer" type="number" name="buffer_time" min="0" max="240" step="5" value="<?= (int) $settings['business.buffer_time'] ?>">
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Added to each service's own gaps.</p>
        </div>
        <div class="field">
            <label for="biz-limit">Customers can book up to this many days ahead</label>
            <input id="biz-limit" type="number" name="future_limit" min="1" max="730" value="<?= (int) $settings['business.future_limit'] ?>">
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
<?php else: ?>
    <form method="post" action="<?= base_url('business/settings/localization') ?>" class="form-card form-card-narrow">
        <?= csrf_field() ?>
        <div class="field">
            <label for="biz-time-format">Clock</label>
            <select id="biz-time-format" name="time_format">
                <?php foreach ($choices['localization.time_format'] as $v): ?>
                    <option value="<?= esc($v, 'attr') ?>"<?= $settings['localization.time_format'] === $v ? ' selected' : '' ?>><?= esc($labels[$v] ?? $v) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="biz-first-day">Calendar weeks start on</label>
            <select id="biz-first-day" name="first_day">
                <?php foreach ($choices['localization.first_day'] as $v): ?>
                    <option value="<?= esc($v, 'attr') ?>"<?= $settings['localization.first_day'] === $v ? ' selected' : '' ?>><?= esc($labels[$v] ?? $v) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Your time zone comes from your business profile: <?= esc((string) ($listing['timezone'] ?: 'Africa/Johannesburg')) ?>.</p>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
<?php endif ?>
<?= $this->endSection() ?>
