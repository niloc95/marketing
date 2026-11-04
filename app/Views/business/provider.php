<?php
/**
 * One person customers book: their details, services, hours and reception.
 *
 * @var array<string,mixed>          $provider
 * @var array<int,array<string,mixed>> $hours
 * @var array<int,string>            $locations
 * @var list<array<string,mixed>>    $services
 * @var list<int>                    $offered
 * @var list<array<string,mixed>>    $staff
 * @var list<int>                    $assigned
 * @var list<array<string,mixed>>    $members
 * @var bool                         $isAdmin
 * @var array<int,string>            $weekdays
 * @var array<string,mixed>          $errors
 */
use App\Services\Bookings\CatalogueService;

$hourErrors = $errors['hours'] ?? [];
$archived   = $provider['archived_at'] !== null;
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('business/team') ?>">Team</a></p>
<h1 class="mb-5 text-xl font-bold text-slate-900 dark:text-white"><?= esc((string) $provider['name']) ?></h1>

<div class="grid gap-6 lg:grid-cols-2">
    <form method="post" action="<?= base_url('business/team/' . $provider['id']) ?>" class="form-card">
        <?= csrf_field() ?>
        <div class="field">
            <label for="p-name">Name</label>
            <input id="p-name" type="text" name="name" value="<?= esc((string) $provider['name']) ?>" maxlength="120" required>
            <?php if (isset($errors['name'])): ?><p class="mt-1 text-sm text-rose-600"><?= esc((string) $errors['name']) ?></p><?php endif ?>
        </div>
        <div class="field">
            <label for="p-title">Title <span class="font-normal text-slate-500">(optional)</span></label>
            <input id="p-title" type="text" name="title" value="<?= esc((string) ($provider['title'] ?? '')) ?>" maxlength="120" placeholder="Senior stylist">
        </div>
        <div class="field">
            <label for="p-bio">About <span class="font-normal text-slate-500">(optional)</span></label>
            <textarea id="p-bio" name="bio" rows="3" maxlength="1200"><?= esc((string) ($provider['bio'] ?? '')) ?></textarea>
        </div>
        <div class="field">
            <label for="p-color">Calendar colour</label>
            <select id="p-color" name="color">
                <?php foreach (CatalogueService::COLORS as $color): ?>
                    <option value="<?= $color ?>"<?= $provider['color'] === $color ? ' selected' : '' ?>><?= ucfirst($color) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <label class="mb-4 flex items-center gap-2 text-sm"><input type="checkbox" name="bookable" value="1"<?= $provider['bookable'] ? ' checked' : '' ?>> Customers can book <?= esc((string) $provider['name']) ?> online</label>

        <fieldset class="field">
            <legend class="mb-1 font-semibold">Services</legend>
            <?php if ($services === []): ?>
                <p class="text-sm text-slate-500"><a class="underline" href="<?= base_url('business/services/new') ?>">Add your first service</a>.</p>
            <?php endif ?>
            <?php foreach ($services as $s): ?>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="service_ids[]" value="<?= (int) $s['id'] ?>"<?= in_array((int) $s['id'], $offered, true) ? ' checked' : '' ?>> <?= esc((string) $s['name']) ?></label>
            <?php endforeach ?>
        </fieldset>

        <?php if ($isAdmin): ?>
            <div class="field">
                <label for="p-member">Signs in as</label>
                <select id="p-member" name="member_id">
                    <option value="0">Nobody</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int) $m['id'] ?>"<?= (int) ($provider['member_id'] ?? 0) === (int) $m['id'] ? ' selected' : '' ?>><?= esc((string) ($m['account_name'] ?: $m['account_email'])) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The person who sees this calendar as their own.</p>
            </div>
            <?php if ($staff !== []): ?>
                <fieldset class="field">
                    <legend class="mb-1 font-semibold">Reception looking after their bookings</legend>
                    <?php foreach ($staff as $m): ?>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="staff_ids[]" value="<?= (int) $m['id'] ?>"<?= in_array((int) $m['id'], $assigned, true) ? ' checked' : '' ?>> <?= esc((string) ($m['account_name'] ?: $m['account_email'])) ?></label>
                    <?php endforeach ?>
                </fieldset>
            <?php endif ?>
        <?php endif ?>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>

    <form method="post" action="<?= base_url('business/team/' . $provider['id'] . '/hours') ?>" class="form-card" id="hours">
        <?= csrf_field() ?>
        <h2 class="mb-1 font-bold">Working hours</h2>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Customers can book inside these hours. Your business opening hours still apply.</p>
        <?php if (isset($hourErrors[0])): ?><p class="mb-3 text-sm text-rose-600"><?= esc((string) $hourErrors[0]) ?></p><?php endif ?>
        <div class="space-y-3">
            <?php foreach ($weekdays as $day => $label): ?>
                <?php $h = $hours[$day]; ?>
                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                    <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="hours[<?= $day ?>][active]" value="1"<?= $h['active'] ? ' checked' : '' ?>> <?= $label ?></label>
                    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <label class="text-xs">From<input type="time" name="hours[<?= $day ?>][start]" value="<?= esc($h['start']) ?>" class="mt-1 w-full"></label>
                        <label class="text-xs">To<input type="time" name="hours[<?= $day ?>][end]" value="<?= esc($h['end']) ?>" class="mt-1 w-full"></label>
                        <label class="text-xs">Break from<input type="time" name="hours[<?= $day ?>][break_start]" value="<?= esc($h['break_start']) ?>" class="mt-1 w-full"></label>
                        <label class="text-xs">Break to<input type="time" name="hours[<?= $day ?>][break_end]" value="<?= esc($h['break_end']) ?>" class="mt-1 w-full"></label>
                    </div>
                    <?php if (count($locations) > 1): ?>
                        <label class="mt-2 block text-xs">Where
                            <select name="hours[<?= $day ?>][location_id]" class="mt-1 w-full">
                                <?php foreach ($locations as $id => $name): ?>
                                    <option value="<?= $id ?>"<?= (int) ($h['location_id'] ?? 0) === $id ? ' selected' : '' ?>><?= esc($name) ?></option>
                                <?php endforeach ?>
                            </select>
                        </label>
                    <?php endif ?>
                    <?php if (isset($hourErrors[$day])): ?><p class="mt-2 text-sm text-rose-600"><?= esc((string) $hourErrors[$day]) ?></p><?php endif ?>
                </div>
            <?php endforeach ?>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Save hours</button>
            <button type="submit" name="use_profile_hours" value="1" class="btn btn-ghost">Use our opening hours</button>
        </div>
    </form>
</div>

<?php if ($isAdmin): ?>
    <form method="post" action="<?= base_url('business/team/' . $provider['id'] . '/archive') ?>" class="mt-6">
        <?= csrf_field() ?>
        <?php if ($archived): ?><input type="hidden" name="restore" value="1"><?php endif ?>
        <button type="submit" class="btn btn-ghost"><?= $archived ? 'Bring back to the team' : 'Archive' ?></button>
        <?php if (! $archived): ?><span class="ml-2 text-sm text-slate-500 dark:text-slate-400">Customers can no longer book them. Past bookings stay.</span><?php endif ?>
    </form>
<?php endif ?>
<?= $this->endSection() ?>
