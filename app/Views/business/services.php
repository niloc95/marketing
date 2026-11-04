<?php
/**
 * @var list<array<string,mixed>> $services
 * @var list<array<string,mixed>> $categories
 * @var array<int,string>         $providers id => name
 */
use App\Services\Bookings\CatalogueService;

?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-bold text-slate-900 dark:text-white">Services</h1>
    <a class="btn btn-primary" href="<?= base_url('business/services/new') ?>"><?= lucide('plus', 'h-4 w-4') ?> Add a service</a>
</div>

<?php if ($services === []): ?>
    <div class="panel mt-5">
        <p class="text-sm text-slate-600 dark:text-slate-300">Add what customers can book, with how long it takes and what it costs.</p>
    </div>
<?php else: ?>
    <div class="table-scroll mt-5">
        <table class="table">
            <thead><tr><th>Service</th><th>Length</th><th>Price</th><th>Who</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($services as $s): ?>
                    <?php $archived = $s['archived_at'] !== null; ?>
                    <tr<?= $archived ? ' class="opacity-60"' : '' ?>>
                        <td>
                            <a class="font-semibold underline" href="<?= base_url('business/services/' . $s['id']) ?>"><?= esc((string) $s['name']) ?></a>
                            <?php if ($s['category_name']): ?><span class="block text-xs text-slate-500 dark:text-slate-400"><?= esc((string) $s['category_name']) ?></span><?php endif ?>
                            <?php if ($archived): ?><span class="pill pill-unpublished">Archived</span><?php endif ?>
                        </td>
                        <td class="whitespace-nowrap"><?= (int) $s['duration_min'] ?> min</td>
                        <td class="whitespace-nowrap"><?= esc(CatalogueService::priceText($s['price_cents'] !== null ? (int) $s['price_cents'] : null, (string) $s['currency'])) ?: '<span class="text-slate-400">None</span>' ?></td>
                        <td class="text-sm"><?= esc(implode(', ', array_map(static fn (int $id): string => $providers[$id] ?? '', $s['provider_ids']))) ?: '<span class="text-amber-600">Nobody yet</span>' ?></td>
                        <td>
                            <form method="post" action="<?= base_url('business/services/' . $s['id'] . '/archive') ?>">
                                <?= csrf_field() ?>
                                <?php if ($archived): ?><input type="hidden" name="restore" value="1"><?php endif ?>
                                <button type="submit" class="btn btn-ghost btn-xs"><?= $archived ? 'Restore' : 'Archive' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<section class="mt-8" id="categories">
    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Categories</h2>
    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">Optional. They group services when customers choose one.</p>
    <div class="space-y-2">
        <?php foreach ($categories as $c): ?>
            <div class="panel flex flex-wrap items-end gap-2">
                <form method="post" action="<?= base_url('business/services/categories/' . $c['id']) ?>" class="flex flex-1 flex-wrap items-end gap-2">
                    <?= csrf_field() ?>
                    <label class="sr-only" for="cat-<?= (int) $c['id'] ?>">Name</label>
                    <input id="cat-<?= (int) $c['id'] ?>" type="text" name="name" value="<?= esc((string) $c['name']) ?>" maxlength="80" required class="min-w-0 flex-1">
                    <select name="color" aria-label="Colour">
                        <?php foreach (CatalogueService::COLORS as $color): ?>
                            <option value="<?= $color ?>"<?= $c['color'] === $color ? ' selected' : '' ?>><?= ucfirst($color) ?></option>
                        <?php endforeach ?>
                    </select>
                    <button type="submit" class="btn btn-ghost btn-xs">Save</button>
                </form>
                <form method="post" action="<?= base_url('business/services/categories/' . $c['id'] . '/delete') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-xs">Delete</button>
                </form>
            </div>
        <?php endforeach ?>
        <form method="post" action="<?= base_url('business/services/categories') ?>" class="panel flex flex-wrap items-end gap-2">
            <?= csrf_field() ?>
            <label class="sr-only" for="cat-new">New category</label>
            <input id="cat-new" type="text" name="name" placeholder="New category, such as Hair or Nails" maxlength="80" required class="min-w-0 flex-1">
            <input type="hidden" name="color" value="slate">
            <button type="submit" class="btn btn-ghost btn-xs">Add</button>
        </form>
    </div>
</section>
<?= $this->endSection() ?>
