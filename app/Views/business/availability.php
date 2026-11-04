<?php
/**
 * @var list<array<string,mixed>> $services
 * @var list<array<string,mixed>> $providers
 * @var int                       $serviceId
 * @var string                    $who
 * @var string                    $date
 * @var bool                      $public
 * @var list<array<string,mixed>> $slots
 * @var array<string,mixed>|null  $next
 * @var array{0:string,1:string}|null $window
 * @var bool                      $anyAllowed
 * @var string                    $timezone
 */
$names = array_column($providers, 'name', 'id');
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<h1 class="text-xl font-bold text-slate-900 dark:text-white">When can customers book?</h1>
<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Check the times on offer for a service and a day. Times are in <?= esc($timezone) ?>.</p>

<?php if ($services === []): ?>
    <div class="panel mt-5"><p class="text-sm"><a class="underline" href="<?= base_url('business/services/new') ?>">Add a service</a> first.</p></div>
<?php else: ?>
    <form method="get" action="<?= base_url('business/availability') ?>" class="panel mt-5 grid gap-3 sm:grid-cols-4 sm:items-end">
        <div>
            <label for="av-service" class="mb-1 block text-sm font-semibold">Service</label>
            <select id="av-service" name="service_id" class="w-full">
                <?php foreach ($services as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $serviceId ? ' selected' : '' ?>><?= esc((string) $s['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="av-who" class="mb-1 block text-sm font-semibold">With</label>
            <select id="av-who" name="provider_id" class="w-full">
                <?php if ($anyAllowed): ?><option value="any"<?= $who === 'any' ? ' selected' : '' ?>>Anyone available</option><?php endif ?>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= (string) $p['id'] === $who ? ' selected' : '' ?>><?= esc((string) $p['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="av-date" class="mb-1 block text-sm font-semibold">Day</label>
            <input id="av-date" type="date" name="date" value="<?= esc($date) ?>" class="w-full">
        </div>
        <div class="flex flex-col gap-2">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="as" value="business"<?= $public ? '' : ' checked' ?>> As the business (no notice rules)</label>
            <button type="submit" class="btn btn-primary">Show times</button>
        </div>
    </form>

    <div class="mt-5">
        <?php if ($window === null): ?>
            <p class="alert alert-info">The business is closed on this day, by its opening hours or public holiday hours.</p>
        <?php elseif ($slots === []): ?>
            <p class="alert alert-info">No times left on this day.<?php if ($next !== null): ?> The next free time is <?= esc((new DateTimeImmutable($next['date']))->format('l j F')) ?> at <?= esc($next['time']) ?>.<?php endif ?></p>
        <?php else: ?>
            <p class="mb-3 text-sm text-slate-600 dark:text-slate-300"><?= count($slots) ?> times on offer.</p>
            <ul class="flex flex-wrap gap-2">
                <?php foreach ($slots as $slot): ?>
                    <?php $ids = $slot['provider_ids'] ?? [$slot['provider_id']]; ?>
                    <li class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900" title="<?= esc(implode(', ', array_map(static fn (int $id): string => $names[$id] ?? '', $ids)), 'attr') ?>"><?= esc($slot['time']) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
