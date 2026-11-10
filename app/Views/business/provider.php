<?php
/**
 * One person customers book: their details, services, hours and reception.
 *
 * @var array<string,mixed>          $provider
 * @var array<string,mixed>|null     $card      their profile team card (T31)
 * @var array<string,mixed>|null     $regulator the profile category's regulator
 * @var array<int,array<string,mixed>> $hours  TeamService::hoursForForm(): periods per day
 * @var list<string>                 $hourWarnings times outside the opening hours of their place
 * @var string                       $hoursMode    profile (follows the opening hours) or own
 * @var bool                         $hoursChanged the opening hours changed since their own hours were set or kept
 * @var array<int,string>            $openingWeek  weekday => the profile's opening hours that day
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
use App\Services\Bookings\ProviderWeek;

$hourErrors = $errors['hours'] ?? [];
$archived   = $provider['archived_at'] !== null;
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('bookings/team') ?>">Team</a></p>
<h1 class="mb-5 text-xl font-bold text-slate-900 dark:text-white"><?= esc((string) $provider['name']) ?></h1>

<div class="grid gap-6 lg:grid-cols-2">
    <form method="post" action="<?= base_url('bookings/team/' . $provider['id']) ?>" class="form-card" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php // Who they are is their profile team card (T31): the same fields as "Add your people". ?>
        <?= view('directory/_person_fields', [
            'name'      => static fn (string $f): string => $f,
            'photoName' => 'photo',
            'm'         => $card ?? ['name' => $provider['name'], 'role' => $provider['title'], 'bio' => $provider['bio'], 'show_on_profile' => '0'],
            'e'         => static fn (string $f): string => (string) ($errors[$f] ?? ($f === 'role' ? ($errors['title'] ?? '') : '')),
            'regulator' => $regulator,
        ], ['saveData' => false]) ?>
        <p class="hint mb-4">These details are also your profile&rsquo;s team card. &ldquo;Show on my profile&rdquo; puts them on your public profile.</p>
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
                <p class="text-sm text-slate-500"><a class="underline" href="<?= base_url('bookings/services/new') ?>">Add your first service</a>.</p>
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

    <form method="post" action="<?= base_url('bookings/team/' . $provider['id'] . '/hours') ?>" class="form-card" id="hours">
        <?= csrf_field() ?>
        <h2 class="mb-1 font-bold">Working hours</h2>
        <?php if ($hoursMode === ProviderWeek::PROFILE): ?>
            <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                <?= esc((string) $provider['name']) ?> works your opening hours. When you change your opening hours, customers can book the new times at once.
            </p>
            <dl class="mb-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <?php foreach ($weekdays as $day => $label): ?>
                    <dt class="font-semibold"><?= $label ?></dt><dd><?= esc($openingWeek[$day] ?? 'Closed') ?></dd>
                <?php endforeach ?>
            </dl>
            <p class="mb-4 text-sm"><a class="underline" href="<?= base_url('manage') ?>">Change your opening hours</a></p>
            <div class="flex flex-wrap gap-2">
                <button type="submit" name="hours_mode" value="<?= ProviderWeek::OWN ?>" class="btn btn-primary">Set own hours</button>
            </div>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Own hours let <?= esc((string) $provider['name']) ?> work fewer hours, at a branch, or only some services at some times.</p>
        <?php else: ?>
        <?php if ($hoursChanged): ?>
            <div class="hours-warnings mb-4" role="status">
                <p class="font-semibold">Your opening hours changed</p>
                <p class="mb-2">These are <?= esc((string) $provider['name']) ?>'s own hours, so customers can still only book the times below. Your opening hours are now:</p>
                <ul><?php foreach ($weekdays as $day => $label): ?><li><?= $label ?>: <?= esc($openingWeek[$day] ?? 'Closed') ?></li><?php endforeach ?></ul>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="submit" name="hours_mode" value="<?= ProviderWeek::PROFILE ?>" class="btn btn-primary">Follow our opening hours</button>
                    <button type="submit" name="keep_hours" value="1" class="btn btn-ghost">Keep mine</button>
                </div>
            </div>
        <?php endif ?>
        <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
            When and where <?= esc((string) $provider['name']) ?> sees people. Customers book these times, never your reception hours.
            A day can have more than one time, each at its own place and, if you like, for only some services.
        </p>
        <?php // A worked example, because "periods" is not how a practice talks. ?>
        <details class="hours-example mb-4">
            <summary>See an example</summary>
            <p>Reception is open Monday to Friday, 08:00 to 16:30 at both practices. The doctor works:</p>
            <ul>
                <li>Monday: 09:00 to 13:00 at Practice A, then 14:30 to 16:00 at Practice B</li>
                <li>Tuesday: the same</li>
            </ul>
            <p>Tick Monday, enter 09:00 to 13:00 with Practice A, and 14:30 to 16:00 with Practice B in the next line. If Practice B only offers some treatments, open "All their services" on that line and tick them. A lunch break is simply the gap between two lines.</p>
        </details>
        <?php if (isset($hourErrors[0])): ?><p class="mb-3 text-sm text-rose-600"><?= esc((string) $hourErrors[0]) ?></p><?php endif ?>
        <?php if (($hourWarnings ?? []) !== []): ?>
            <div class="hours-warnings mb-4" role="status">
                <p class="font-semibold">Check these times</p>
                <ul><?php foreach ($hourWarnings as $w): ?><li><?= esc($w) ?></li><?php endforeach ?></ul>
            </div>
        <?php endif ?>
        <?php $mine = array_values(array_filter($services, static fn (array $s): bool => in_array((int) $s['id'], $offered, true))); ?>
        <div class="space-y-3">
            <?php foreach ($weekdays as $day => $label): ?>
                <?php
                $h    = $hours[$day];
                // What was saved, and one empty line to add another time; a
                // save adds the next one. No script needed.
                $rows = $h['periods'];
                $rows[] = ['start' => '', 'end' => '', 'location_id' => null, 'service_ids' => null];
                ?>
                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                    <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="hours[<?= $day ?>][active]" value="1"<?= $h['active'] ? ' checked' : '' ?>> <?= $label ?></label>
                    <?php foreach ($rows as $i => $r): ?>
                        <?php $name = 'hours[' . $day . '][periods][' . $i . ']'; $blank = $r['start'] === ''; ?>
                        <div class="hours-period<?= $blank && $i > 0 ? ' hours-period-new' : '' ?>">
                            <?php if ($blank && $i > 0): ?><p class="text-xs text-slate-500 dark:text-slate-400">Another time this day (optional)</p><?php endif ?>
                            <div class="grid grid-cols-2 gap-2<?= count($locations) > 1 ? ' sm:grid-cols-3' : '' ?>">
                                <label class="text-xs">From<input type="time" name="<?= $name ?>[start]" value="<?= esc($r['start']) ?>" class="mt-1 w-full"></label>
                                <label class="text-xs">To<input type="time" name="<?= $name ?>[end]" value="<?= esc($r['end']) ?>" class="mt-1 w-full"></label>
                                <?php if (count($locations) > 1): ?>
                                    <label class="col-span-2 text-xs sm:col-span-1">Where
                                        <select name="<?= $name ?>[location_id]" class="mt-1 w-full">
                                            <?php foreach ($locations as $id => $place): ?>
                                                <option value="<?= $id ?>"<?= (int) ($r['location_id'] ?? 0) === $id ? ' selected' : '' ?>><?= esc($place) ?></option>
                                            <?php endforeach ?>
                                        </select>
                                    </label>
                                <?php endif ?>
                            </div>
                            <?php if (count($mine) > 1): ?>
                                <?php $limited = $r['service_ids'] !== null; ?>
                                <details class="mt-2 text-xs"<?= $limited ? ' open' : '' ?>>
                                    <summary><?= $limited ? 'Only: ' . esc(implode(', ', array_column(array_filter($mine, static fn (array $s): bool => in_array((int) $s['id'], $r['service_ids'], true)), 'name'))) : 'All their services' ?></summary>
                                    <p class="mt-1 text-slate-500 dark:text-slate-400">Tick the services offered at this time. None ticked means all of them.</p>
                                    <div class="mt-1 grid gap-1 sm:grid-cols-2">
                                        <?php foreach ($mine as $s): ?>
                                            <label class="flex items-center gap-2"><input type="checkbox" name="<?= $name ?>[service_ids][]" value="<?= (int) $s['id'] ?>"<?= $limited && in_array((int) $s['id'], $r['service_ids'], true) ? ' checked' : '' ?>> <?= esc((string) $s['name']) ?></label>
                                        <?php endforeach ?>
                                    </div>
                                </details>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                    <?php if (isset($hourErrors[$day])): ?><p class="mt-2 text-sm text-rose-600"><?= esc((string) $hourErrors[$day]) ?></p><?php endif ?>
                </div>
            <?php endforeach ?>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Save hours</button>
            <button type="submit" name="hours_mode" value="<?= ProviderWeek::PROFILE ?>" class="btn btn-ghost">Follow our opening hours</button>
        </div>
        <?php endif ?>
    </form>
</div>

<?php if ($isAdmin): ?>
    <form method="post" action="<?= base_url('bookings/team/' . $provider['id'] . '/archive') ?>" class="mt-6">
        <?= csrf_field() ?>
        <?php if ($archived): ?><input type="hidden" name="restore" value="1"><?php endif ?>
        <button type="submit" class="btn btn-ghost"><?= $archived ? 'Bring back to the team' : 'Archive' ?></button>
        <?php if (! $archived): ?><span class="ml-2 text-sm text-slate-500 dark:text-slate-400">Customers can no longer book them. Past bookings stay.</span><?php endif ?>
    </form>
<?php endif ?>
<?= $this->endSection() ?>
