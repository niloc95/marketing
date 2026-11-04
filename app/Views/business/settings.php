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
        <a href="<?= base_url('business/settings/' . $key) ?>" class="<?= $key === $tab ? 'border-ocean text-ocean dark:border-white dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400' ?> -mb-px border-b-2 px-3 py-2 text-sm font-medium"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
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
