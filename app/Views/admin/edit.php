<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => ($listing ? 'Edit' : 'New') . ' listing — Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$isNew = $listing === null;
$base  = $listing ?? [];
if (! $isNew) {
    $base['specializations'] = implode(', ', $tags);
}
$v = function (string $f, string $default = '') use ($old, $base) {
    if (array_key_exists($f, $old)) return form_old_value($old[$f]);
    if (array_key_exists($f, $base) && $base[$f] !== null) return (string) $base[$f];
    return $default;
};
$err = fn (string $f) => $errors[$f] ?? '';
$action = $isNew ? base_url('admin/new') : base_url('admin/edit/' . $listing['id']);
helper('directory_hours');
// Flashed "old" hours are already the raw hours[day][...] array shape from the
// failed submit; the stored value is JSON and needs decoding. Old wins.
$vHours = is_array($old['hours'] ?? null) ? $old['hours'] : (hours_decode($base['trading_hours'] ?? null) ?? []);
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-white"><?= $isNew ? 'New profile' : 'Edit profile' ?></h1>
                <a class="btn btn-ghost btn-xs" href="<?= base_url('admin') ?>">&larr; Back to profiles</a>
            </div>

            <?php // Deliberately outside the form below — each thumbnail carries its
                  // own delete form, and forms cannot nest. ?>
            <?= view('directory/_gallery_manage', [
                'photos'     => $photos,
                'deleteBase' => base_url('admin/photo-delete'),
                'max'        => $galleryMax,
            ]) ?>

            <form method="post" action="<?= esc($action, 'attr') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <?= view('directory/_form_fields', [
                    'v'            => $v,
                    'err'          => $err,
                    'categories'   => $categories,
                    'provinces'    => $provinces,
                    'lockEmail'    => false,
                    'vHours'       => $vHours,
                    'existingLogo' => (string) ($base['logo_path'] ?? ''),
                    'gallerySlots' => $slots,
                ]) ?>

                <?php // Privileged fields — deliberately not in the shared partial, so the
                      // owner form cannot render them even by accident. ?>
                <div class="panel mt-2 bg-slate-50 dark:bg-slate-800/60">
                    <h3>Admin controls</h3>
                    <div class="form-row">
                        <div class="field">
                            <label>Status</label>
                            <select name="status">
                                <?php foreach (['pending', 'published', 'unpublished', 'rejected'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $v('status', 'pending') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>URL slug</label>
                            <input type="text" name="slug" value="<?= esc($v('slug'), 'attr') ?>" placeholder="auto from the name">
                            <div class="hint">Changing this breaks existing links to the profile.</div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="font-medium"><input type="checkbox" name="is_featured" value="1" <?= $v('is_featured') ? 'checked' : '' ?>> Featured</label>
                    </div>
                    <div class="field">
                        <label class="font-medium"><input type="checkbox" name="is_verified" value="1" <?= $v('is_verified') ? 'checked' : '' ?>> Email verified</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block mt-4"><?= $isNew ? 'Create profile' : 'Save changes' ?></button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= view('directory/_editor_assets') ?>
<?= $this->endSection() ?>
