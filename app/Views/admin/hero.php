<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Hero photos — Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Services\HeroImageService;

// Flashed input wins so a rejected add keeps what was typed. Only the add form
// reads it — a rejected row edit redraws from the stored row, because `old` has
// no id on it and would otherwise repopulate every row with one row's input.
$v   = fn (string $field) => array_key_exists($field, $old) ? (string) $old[$field] : '';
$err = fn (string $field) => $errors[$field] ?? '';

// Grouped the same way the signup form groups them, so the operator picks from
// the list a visitor actually sees.
$grouped = [];
foreach ($categories as $c) {
    $grouped[$c['group_name'] ?: 'Ungrouped'][] = $c;
}
ksort($grouped);

$full = count($images) >= HeroImageService::MAX_SLIDES;

/** The category <select>, shared by the add form and every row. */
$categorySelect = static function (?int $selected) use ($grouped): string {
    $html = '<option value="">No link — caption only</option>';
    foreach ($grouped as $group => $cats) {
        $html .= '<optgroup label="' . esc($group, 'attr') . '">';
        foreach ($cats as $c) {
            $html .= '<option value="' . (int) $c['id'] . '"'
                . ((int) $c['id'] === $selected ? ' selected' : '') . '>'
                . esc($c['name']) . '</option>';
        }
        $html .= '</optgroup>';
    }

    return $html;
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Hero photos <span class="text-sm font-normal text-slate-500 dark:text-slate-400">(<?= count($images) ?> of <?= HeroImageService::MAX_SLIDES ?>)</span></h1>
        <p class="mb-5 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
            The photographs that cross-fade behind the search box on the home page, in <strong>Sort</strong> order.
            The first one is what a visitor sees before anything else loads, so put your strongest photo at sort&nbsp;0.
            Landscape shots with the subject to one side work best &mdash; the headline sits over the left of the frame.
            With no active photos the hero falls back to the plain blue gradient, which is a safe place to be.
        </p>

        <div class="panel mb-8">
            <h3>Add a hero photo</h3>
            <form method="post" action="<?= base_url('admin/hero') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="hero-photo">Photo</label>
                    <input type="file" id="hero-photo" name="photo" accept="image/*" required>
                    <?php if ($err('photo')): ?>
                        <div class="err"><?= esc($err('photo')) ?></div>
                    <?php endif; ?>
                    <div class="hint">
                        JPEG, PNG or WebP, up to 10&nbsp;MB. Upload the largest version you have &mdash;
                        it is resized and converted to WebP here, at two sizes, so phones do not download the big one.
                        Aim for at least 1600&nbsp;px wide and landscape.
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="hero-caption">Caption</label>
                        <input type="text" id="hero-caption" name="caption" maxlength="80"
                               value="<?= esc($v('caption'), 'attr') ?>" placeholder="e.g. Architects">
                        <div class="hint">Shown in the corner of the photo. Leave blank for no caption.</div>
                    </div>
                    <div class="field">
                        <label for="hero-category">Links to</label>
                        <select id="hero-category" name="category_id"><?= $categorySelect((int) $v('category_id') ?: null) ?></select>
                        <?php if ($err('category_id')): ?>
                            <div class="err"><?= esc($err('category_id')) ?></div>
                        <?php endif; ?>
                        <div class="hint">Where the caption takes someone who clicks it.</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="hero-credit">Photographer</label>
                        <input type="text" id="hero-credit" name="credit" maxlength="120"
                               value="<?= esc($v('credit'), 'attr') ?>" placeholder="e.g. Sora Shimazaki">
                    </div>
                    <div class="field">
                        <label for="hero-credit-url">Credit link</label>
                        <input type="url" id="hero-credit-url" name="credit_url" maxlength="255"
                               value="<?= esc($v('credit_url'), 'attr') ?>" placeholder="https://www.pexels.com/photo/...">
                        <?php if ($err('credit_url')): ?>
                            <div class="err"><?= esc($err('credit_url')) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="hero-sort">Sort</label>
                        <input type="text" id="hero-sort" name="sort_order" class="w-20"
                               value="<?= esc($v('sort_order') !== '' ? $v('sort_order') : (string) count($images), 'attr') ?>">
                    </div>
                </div>
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary btn-xs" <?= $full ? 'disabled' : '' ?>>Add hero photo</button>
                <?php if ($full): ?>
                    <div class="hint">The rotation is full. Delete one before adding another.</div>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($images === []): ?>
            <div class="empty">
                <p>No hero photos yet — the home page is showing the plain gradient.</p>
            </div>
        <?php endif; ?>

        <div class="grid gap-3">
            <?php foreach ($images as $img): ?>
                <?php // One <form> per row, so a row is saved on its own. Forms cannot
                      // legally wrap <td>s, which is why this is a flex card and not a
                      // table — same reason as the categories screen. ?>
                <div class="card p-3">
                    <div class="flex flex-wrap items-start gap-4">
                        <img src="<?= esc(base_url($img['path_sm'] ?: $img['path']), 'attr') ?>"
                             alt="" width="160" height="90"
                             class="h-[90px] w-[160px] shrink-0 rounded-xl object-cover<?= $img['is_active'] ? '' : ' opacity-40' ?>">

                        <form method="post" action="<?= base_url('admin/hero/' . $img['id']) ?>"
                              enctype="multipart/form-data" class="flex flex-1 flex-wrap items-end gap-3">
                            <?= csrf_field() ?>
                            <div class="field mb-0">
                                <label class="text-xs">Caption</label>
                                <input type="text" name="caption" maxlength="80" class="w-44"
                                       value="<?= esc($img['caption'], 'attr') ?>">
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Links to</label>
                                <select name="category_id" class="w-52"><?= $categorySelect($img['category_id'] === null ? null : (int) $img['category_id']) ?></select>
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Photographer</label>
                                <input type="text" name="credit" maxlength="120" class="w-40"
                                       value="<?= esc($img['credit'], 'attr') ?>">
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Credit link</label>
                                <input type="url" name="credit_url" maxlength="255" class="w-56"
                                       value="<?= esc($img['credit_url'], 'attr') ?>">
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Sort</label>
                                <input type="text" name="sort_order" class="w-16" value="<?= (int) $img['sort_order'] ?>">
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Active</label>
                                <input type="checkbox" name="is_active" value="1" <?= $img['is_active'] ? 'checked' : '' ?>>
                            </div>
                            <div class="field mb-0">
                                <label class="text-xs">Replace photo</label>
                                <input type="file" name="photo" accept="image/*" class="w-56 text-xs">
                            </div>
                            <button class="btn btn-primary btn-xs">Save</button>
                        </form>

                        <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                            <span><?= (int) $img['width'] ?>&times;<?= (int) $img['height'] ?><?= $img['path_sm'] ? '' : ' (one size)' ?></span>
                            <form method="post" action="<?= base_url('admin/hero/' . $img['id'] . '/delete') ?>"
                                  data-confirm="Delete this hero photo? The image files go too.">
                                <?= csrf_field() ?>
                                <button class="btn btn-ghost btn-xs text-brand-crimson">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
