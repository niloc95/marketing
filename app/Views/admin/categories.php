<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Categories — Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$groups = [];
foreach ($categories as $c) {
    $groups[$c['group_name'] ?: 'Ungrouped'][] = $c;
}
$groupNames = array_keys($groups);
sort($groupNames);
?>
<div class="admin-bar">
    <div class="container">
        <strong>Directory admin</strong>
        <span>
            <a href="<?= base_url('admin') ?>">Listings</a> &middot;
            <a href="<?= base_url('admin/categories') ?>">Categories</a> &middot;
            <a href="<?= base_url('admin/logout') ?>">Sign out</a>
        </span>
    </div>
</div>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Categories <span class="text-sm font-normal text-slate-500 dark:text-slate-400">(<?= count($categories) ?>)</span></h1>
        <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">A category in use cannot be deleted — deactivate it instead, which hides it from the signup form without touching existing listings.</p>

        <div class="panel mb-8">
            <h3>Add a category</h3>
            <form method="post" action="<?= base_url('admin/categories') ?>">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="field">
                        <label>Name</label>
                        <input type="text" name="name" required placeholder="e.g. Mobile Car Wash">
                    </div>
                    <div class="field">
                        <label>Group</label>
                        <input type="text" name="group_name" list="groupnames" placeholder="e.g. Motoring">
                        <datalist id="groupnames">
                            <?php foreach ($groupNames as $g): ?><option value="<?= esc($g, 'attr') ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary btn-xs">Add category</button>
            </form>
        </div>

        <?php foreach ($groupNames as $g): ?>
            <h2 class="mb-3 mt-8 text-base font-semibold text-slate-900 dark:text-white"><?= esc($g) ?></h2>
            <div class="grid gap-2">
                <?php foreach ($groups[$g] as $c): ?>
                    <?php $used = $usage[(int) $c['id']] ?? 0; ?>
                    <div class="card flex flex-wrap items-end gap-3 p-3">
                        <?php // One <form> per row. Forms cannot legally wrap <td>s, so this
                              // is a flex row rather than a table. ?>
                        <form method="post" action="<?= base_url('admin/categories/' . $c['id']) ?>" class="flex flex-wrap items-end gap-3">
                            <?= csrf_field() ?>
                            <div class="field mb-0">
                                <label class="text-xs">Name</label>
                                <input type="text" name="name" value="<?= esc($c['name'], 'attr') ?>" class="w-56">
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Group</label>
                                <input type="text" name="group_name" value="<?= esc($c['group_name'], 'attr') ?>" list="groupnames" class="w-48">
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Sort</label>
                                <input type="text" name="sort_order" value="<?= (int) $c['sort_order'] ?>" class="w-16">
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Active</label>
                                <input type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>>
                            </div>
                            <button class="btn btn-primary btn-xs">Save</button>
                        </form>

                        <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                            <span><code><?= esc($c['slug']) ?></code></span>
                            <span><?= $used > 0 ? $used . ' listing' . ($used === 1 ? '' : 's') : 'unused' ?></span>
                            <?php if ($used === 0): ?>
                                <form method="post" action="<?= base_url('admin/categories/' . $c['id'] . '/delete') ?>" onsubmit="return confirm('Delete <?= esc($c['name'], 'attr') ?>?')">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-ghost btn-xs text-brand-crimson">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?= $this->endSection() ?>
