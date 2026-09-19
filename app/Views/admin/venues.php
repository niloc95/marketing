<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Venues — Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * The complexes, malls and buildings listings are grouped into.
 *
 * Same shape as admin/categories.php — one <form> per row in a flex card,
 * because a form cannot legally wrap <td>s.
 *
 * Unlike a category, a venue in use CAN be deleted: the foreign key is
 * ON DELETE SET NULL, so its businesses are ungrouped rather than removed.
 * The confirm text says so.
 *
 * @var array $venues
 * @var array $usage      venue_id => published listings
 * @var array $provinces
 */
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Venues <span class="text-sm font-normal text-slate-500 dark:text-slate-400">(<?= count($venues) ?>)</span></h1>
        <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
            A venue groups the businesses inside one complex, mall or building, and gives them a shared page.
            Set a listing's venue on its edit form. Deleting a venue ungroups its businesses; it never deletes them.
        </p>

        <div class="panel mb-8">
            <h3>Add a venue</h3>
            <form method="post" action="<?= base_url('admin/venues') ?>">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="field">
                        <label>Name</label>
                        <input type="text" name="name" required placeholder="e.g. Oriental Plaza">
                    </div>
                    <div class="field">
                        <label>Street address</label>
                        <input type="text" name="address_line" placeholder="e.g. 62 Bree Street">
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label>Suburb</label>
                        <input type="text" name="suburb" placeholder="e.g. Fordsburg">
                    </div>
                    <div class="field">
                        <label>City</label>
                        <input type="text" name="city" placeholder="e.g. Johannesburg">
                    </div>
                    <div class="field">
                        <label>Province</label>
                        <select name="province">
                            <option value="">—</option>
                            <?php foreach ($provinces as $prov): ?>
                                <option value="<?= esc($prov, 'attr') ?>"><?= esc($prov) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary btn-xs">Add venue</button>
            </form>
        </div>

        <?php if ($venues === []): ?>
            <div class="empty"><p>No venues yet.</p></div>
        <?php endif; ?>

        <div class="grid gap-2">
            <?php foreach ($venues as $ven): ?>
                <?php $used = $usage[(int) $ven['id']] ?? 0; ?>
                <div class="card flex flex-wrap items-end gap-3 p-3">
                    <form method="post" action="<?= base_url('admin/venues/' . $ven['id']) ?>" class="flex flex-wrap items-end gap-3">
                        <?= csrf_field() ?>
                        <div class="field mb-0">
                            <label class="text-xs">Name</label>
                            <input type="text" name="name" value="<?= esc($ven['name'], 'attr') ?>" class="w-48">
                        </div>
                        <div class="field mb-0">
                            <label class="text-xs">Address</label>
                            <input type="text" name="address_line" value="<?= esc($ven['address_line'], 'attr') ?>" class="w-44">
                        </div>
                        <div class="field mb-0">
                            <label class="text-xs">Suburb</label>
                            <input type="text" name="suburb" value="<?= esc($ven['suburb'], 'attr') ?>" class="w-32">
                        </div>
                        <div class="field mb-0">
                            <label class="text-xs">City</label>
                            <input type="text" name="city" value="<?= esc($ven['city'], 'attr') ?>" class="w-32">
                        </div>
                        <div class="field mb-0">
                            <label class="text-xs">Province</label>
                            <select name="province" class="w-36">
                                <option value="">—</option>
                                <?php foreach ($provinces as $prov): ?>
                                    <option value="<?= esc($prov, 'attr') ?>" <?= $ven['province'] === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php // The venue's own pin. Blank means the page shows no map —
                              // directory:venue-assign fills these from the listings. ?>
                        <div class="field mb-0">
                            <label class="text-xs">Latitude</label>
                            <input type="text" name="latitude" value="<?= esc($ven['latitude'], 'attr') ?>" class="w-28">
                        </div>
                        <div class="field mb-0">
                            <label class="text-xs">Longitude</label>
                            <input type="text" name="longitude" value="<?= esc($ven['longitude'], 'attr') ?>" class="w-28">
                        </div>
                        <div class="field mb-0">
                            <label class="text-xs">Active</label>
                            <input type="checkbox" name="is_active" value="1" <?= $ven['is_active'] ? 'checked' : '' ?>>
                        </div>
                        <button class="btn btn-primary btn-xs">Save</button>
                    </form>

                    <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                        <a href="<?= esc(base_url('directory/at/' . $ven['slug']), 'attr') ?>" target="_blank"><code><?= esc($ven['slug']) ?></code></a>
                        <span><?= $used > 0 ? $used . ' business' . ($used === 1 ? '' : 'es') : 'empty' ?></span>
                        <form method="post" action="<?= base_url('admin/venues/' . $ven['id'] . '/delete') ?>"
                              data-confirm="Delete <?= esc($ven['name'], 'attr') ?>?<?= $used > 0 ? ' ' . $used . ' profile(s) stay listed but lose the grouping.' : '' ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-ghost btn-xs text-brand-crimson">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
