<?php
/**
 * Services (/business/services): what customers can book, as cards like
 * Customers, then the categories that group them. Each card opens the
 * service page (business/service_form.php).
 *
 * @var list<array<string,mixed>> $services
 * @var list<array<string,mixed>> $categories
 * @var array<int,string>         $providers id => name
 */
use App\Services\Bookings\CatalogueService;

$minutes = static function (int $m): string {
    $h = intdiv($m, 60);
    $r = $m % 60;

    return $h === 0 ? $m . ' min' : $h . ' h' . ($r > 0 ? ' ' . $r . ' min' : '');
};
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<?php // This page is one of the profile's sections: the same tabs as /business/profile, docked. ?>
<?php if (! empty($profileTabs)): ?>
    <?= view('layouts/_profile_tabs', ['tabs' => $profileTabs, 'label' => 'Profile sections'], ['saveData' => false]) ?>
<?php endif ?>
<div class="mt-1 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500 dark:text-slate-400">What customers can book, how long it takes, what it costs and who gives it.</p>
    <?php if ($services !== []): ?>
        <a class="btn btn-primary btn-xs inline-flex items-center gap-1.5" href="<?= base_url('business/services/new') ?>"><?= lucide('plus', 'h-4 w-4') ?> Add a service</a>
    <?php endif ?>
</div>

<?php if ($services === []): ?>
    <div class="panel mt-5 p-5">
        <p class="text-sm text-slate-600 dark:text-slate-300">Add what customers can book, with how long it takes and what it costs.</p>
        <a class="btn btn-primary btn-xs mt-3 inline-flex items-center gap-1.5" href="<?= base_url('business/services/new') ?>"><?= lucide('plus', 'h-4 w-4') ?> Add a service</a>
    </div>
<?php else: ?>
    <ul class="svc-cards mt-5">
        <?php foreach ($services as $s): ?>
            <?php
            $archived = $s['archived_at'] !== null;
            $price    = CatalogueService::priceText($s['price_cents'] !== null ? (int) $s['price_cents'] : null, (string) $s['currency']);
            $who      = array_values(array_filter(array_map(static fn (int $id): string => $providers[$id] ?? '', $s['provider_ids'])));
            $url      = base_url('business/services/' . $s['id']);
            ?>
            <li class="panel svc-card<?= $archived ? ' svc-card-archived' : '' ?>">
                <a class="svc-card-head" href="<?= $url ?>">
                    <span class="svc-card-name"><?= esc((string) $s['name']) ?></span>
                    <?php if ($s['category_name']): ?>
                        <span class="svc-card-cat"><span class="biz-dot" data-color="<?= esc(CatalogueService::hex((string) $s['category_color']), 'attr') ?>" aria-hidden="true"></span><?= esc((string) $s['category_name']) ?></span>
                    <?php endif ?>
                </a>
                <ul class="svc-card-lines">
                    <li><?= lucide('clock', 'h-4 w-4') ?> <span><?= esc($minutes((int) $s['duration_min'])) ?></span></li>
                    <li><?= lucide('wallet', 'h-4 w-4') ?> <span><?= $price !== '' ? esc($price) : '<span class="text-slate-400">No price shown</span>' ?></span></li>
                    <li><?= lucide('users', 'h-4 w-4') ?> <span><?= $who !== [] ? esc(implode(', ', $who)) : '<span class="text-amber-700 dark:text-amber-300">Nobody gives it yet</span>' ?></span></li>
                </ul>
                <?php if ($archived || ! (int) $s['length_checked']): ?>
                    <p class="flex flex-wrap gap-1.5">
                        <?php if ($archived): ?><span class="pill pill-unpublished">Archived</span><?php endif ?>
                        <?php if (! $archived && ! (int) $s['length_checked']): ?><span class="pill pill-pending">Check the duration</span><?php endif ?>
                    </p>
                <?php endif ?>
                <div class="svc-card-actions">
                    <a class="btn btn-primary btn-xs" href="<?= $url ?>"><?= lucide('square-pen', 'h-4 w-4') ?> Edit</a>
                    <form method="post" action="<?= base_url('business/services/' . $s['id'] . '/archive') ?>">
                        <?= csrf_field() ?>
                        <?php if ($archived): ?><input type="hidden" name="restore" value="1"><?php endif ?>
                        <button type="submit" class="btn btn-ghost btn-xs"><?= lucide($archived ? 'rotate-ccw' : 'folder', 'h-4 w-4') ?> <?= $archived ? 'Restore' : 'Archive' ?></button>
                    </form>
                </div>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

<section class="panel svc-section mt-8" id="categories" aria-labelledby="cat-h">
    <h2 class="svc-section-title" id="cat-h"><?= lucide('list', 'h-5 w-5') ?> Categories</h2>
    <p class="svc-section-hint">Optional. They group services when customers choose one, such as Consultations or Procedures.</p>
    <ul class="svc-cat-rows">
        <?php foreach ($categories as $c): ?>
            <li class="svc-cat-row">
                <form method="post" action="<?= base_url('business/services/categories/' . $c['id']) ?>" class="svc-cat-form">
                    <?= csrf_field() ?>
                    <span class="biz-dot" data-color="<?= esc(CatalogueService::hex((string) $c['color']), 'attr') ?>" aria-hidden="true"></span>
                    <div class="field mb-0 min-w-0 svc-cat-name">
                        <label class="sr-only" for="cat-<?= (int) $c['id'] ?>">Name</label>
                        <input id="cat-<?= (int) $c['id'] ?>" type="text" name="name" value="<?= esc((string) $c['name']) ?>" maxlength="80" required>
                    </div>
                    <div class="field mb-0">
                        <label class="sr-only" for="cat-color-<?= (int) $c['id'] ?>">Colour</label>
                        <select id="cat-color-<?= (int) $c['id'] ?>" name="color">
                            <?php foreach (CatalogueService::COLORS as $color): ?>
                                <option value="<?= $color ?>"<?= $c['color'] === $color ? ' selected' : '' ?>><?= ucfirst($color) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-ghost btn-xs">Save</button>
                </form>
                <form method="post" action="<?= base_url('business/services/categories/' . $c['id'] . '/delete') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-xs"><?= lucide('trash-2', 'h-4 w-4') ?> Delete</button>
                </form>
            </li>
        <?php endforeach ?>
        <li class="svc-cat-row">
            <form method="post" action="<?= base_url('business/services/categories') ?>" class="svc-cat-form">
                <?= csrf_field() ?>
                <div class="field mb-0 min-w-0 flex-1">
                    <label class="sr-only" for="cat-new">New category</label>
                    <input id="cat-new" type="text" name="name" placeholder="New category, such as Consultations" maxlength="80" required>
                </div>
                <input type="hidden" name="color" value="slate">
                <button type="submit" class="btn btn-ghost btn-xs"><?= lucide('plus', 'h-4 w-4') ?> Add</button>
            </form>
        </li>
    </ul>
</section>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Services <span class="page-title-count">(<?= count(array_filter($services, static fn (array $s): bool => $s['archived_at'] === null)) ?>)</span><?= $this->endSection() ?>
