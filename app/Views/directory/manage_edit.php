<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Edit your profile — ' . config('Directory')->siteName(),
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
// The shared partial reads "specializations" via $v(); seed it from the stored
// tag names so the field arrives prefilled. Must happen before $v is bound.
$listing['specializations'] = implode(', ', $tags);

// Flashed input wins (so a failed save keeps what was typed), then the stored
// listing, then the default.
$v = function (string $f, string $default = '') use ($old, $listing) {
    if (array_key_exists($f, $old)) return form_old_value($old[$f]);
    if (array_key_exists($f, $listing) && $listing[$f] !== null) return (string) $listing[$f];
    return $default;
};
$err = fn (string $f) => $errors[$f] ?? '';

helper('directory_hours');
$vHours = is_array($old['hours'] ?? null) ? $old['hours'] : (hours_decode($listing['trading_hours'] ?? null) ?? []);
?>
<section class="section">
    <div class="container">
        <div class="form-card">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <span class="eyebrow">Manage your profile</span>
                    <h1 class="mb-1.5 mt-2 text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($listing['display_name']) ?></h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Status:
                        <span class="pill pill-<?= esc($listing['status'], 'attr') ?>"><?= esc($listing['status']) ?></span>
                        <?php if ($listing['status'] === 'published'): ?>
                            &middot; <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(base_url('directory/' . $listing['slug']), 'attr') ?>" target="_blank">View public page</a>
                        <?php endif; ?>
                    </p>
                </div>
                <a class="btn btn-ghost btn-xs" href="<?= base_url('manage/signout') ?>">Sign out</a>
            </div>

            <?php // Same reason as the gallery below: this panel posts its own form. ?>
            <?php if ($verificationOffered): ?>
                <?= view('directory/_verification_panel', [
                    'verification' => $verification,
                    'amount'       => $verificationAmount,
                    'payable'      => $verificationPayable,
                    'pending'      => $verificationPending,
                ]) ?>
            <?php endif; ?>

            <?php // Deliberately outside the form below — each thumbnail carries its
                  // own delete form, and forms cannot nest. ?>
            <?= view('directory/_gallery_manage', [
                'photos'     => $photos,
                'deleteBase' => base_url('manage/photo-delete'),
                'max'        => $galleryMax,
            ]) ?>

            <form method="post" action="<?= base_url('manage/edit') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <?= view('directory/_form_fields', [
                    'v'            => $v,
                    'err'          => $err,
                    'categories'   => $categories,
                    'provinces'    => $provinces,
                    'lockEmail'    => true,
                    'vHours'       => $vHours,
                    'existingLogo' => (string) ($listing['logo_path'] ?? ''),
                    'gallerySlots' => $slots,
                ]) ?>

                <button type="submit" class="btn btn-accent btn-block">Save changes</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= $this->endSection() ?>
