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
// The video add form flashes its input under the same field names (credit,
// credit_url), tagged form=video, so each form only redraws its own.
$fromVideo = ($old['form'] ?? '') === 'video';
$v   = fn (string $field) => ! $fromVideo && array_key_exists($field, $old) ? (string) $old[$field] : '';
$vv  = fn (string $field) => $fromVideo && array_key_exists($field, $old) ? (string) $old[$field] : '';
$err = fn (string $field) => $errors[$field] ?? '';

// Grouped the same way the signup form groups them, so the operator picks from
// the list a visitor actually sees.
$grouped = [];
foreach ($categories as $c) {
    $grouped[$c['group_name'] ?: 'Ungrouped'][] = $c;
}

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

        <?php
        // The background panel redraws from the flash on a rejected save, like
        // the add form below; otherwise from what is stored.
        $bgMode = array_key_exists('hero_media', $old) ? (string) $old['hero_media'] : $background['mode'];
        ?>
        <div class="panel mb-8">
            <h3>Hero background</h3>
            <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                What plays behind the search box. Videos play muted, with no controls and no YouTube buttons,
                and replace the photos completely &mdash; the photos are kept and come back if you switch to the photo rotation.
                Visitors whose phone or computer is set to reduce motion see a plain dark background instead of a video.
            </p>
            <form method="post" action="<?= base_url('admin/hero/background') ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label>Show</label>
                    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <?php foreach (['photos' => 'Photo rotation', 'video' => 'Video rotation', 'youtube' => 'YouTube video'] as $key => $label): ?>
                            <label class="inline-flex items-center gap-2 font-normal">
                                <input type="radio" name="hero_media" value="<?= $key ?>" <?= $bgMode === $key ? 'checked' : '' ?>>
                                <?= esc($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($err('hero_media')): ?>
                        <div class="err"><?= esc($err('hero_media')) ?></div>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="hero-youtube">YouTube link</label>
                    <input type="text" id="hero-youtube" name="youtube_url" maxlength="255"
                           value="<?= esc(array_key_exists('youtube_url', $old) ? (string) $old['youtube_url'] : ($background['youtube'] !== '' ? 'https://youtu.be/' . $background['youtube'] . ($background['start'] > 0 ? '?t=' . $background['start'] : '') : ''), 'attr') ?>"
                           placeholder="https://www.youtube.com/watch?v=...">
                    <?php if ($err('youtube_url')): ?>
                        <div class="err"><?= esc($err('youtube_url')) ?></div>
                    <?php endif; ?>
                    <div class="hint">Only used with <strong>YouTube video</strong>. A share link (youtu.be/…), watch or Shorts link, or YouTube's embed code all work, and the video must allow embedding.
                        To skip an intro, add a start time: <code>?t=15</code> starts 15 seconds in.</div>
                </div>
                <button class="btn btn-primary btn-xs">Save background</button>
            </form>
        </div>

        <?php $videosFull = count($videos) >= HeroImageService::MAX_VIDEOS; ?>
        <div class="panel mb-8">
            <h3>Background videos <span class="text-sm font-normal text-slate-500 dark:text-slate-400">(<?= count($videos) ?> of <?= HeroImageService::MAX_VIDEOS ?>)</span></h3>
            <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                Used with <strong>Video rotation</strong>. Each clip plays to its end and fades into the next, in <strong>Sort</strong> order;
                after the last it starts again from the first. One clip on its own simply loops.
                Short clips of 10&ndash;20 seconds work best, and each one is a separate download for visitors, so keep the list short.
            </p>
            <form method="post" action="<?= base_url('admin/hero/videos') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="field">
                        <label for="hero-video">Video file</label>
                        <input type="file" id="hero-video" name="video" accept="video/mp4,video/webm" required>
                        <?php if ($err('video')): ?>
                            <div class="err"><?= esc($err('video')) ?></div>
                        <?php endif; ?>
                        <div class="hint">MP4 or WebM, up to 12&nbsp;MB. Pexels videos are free to use here, with no credit required.</div>
                    </div>
                    <div class="field">
                        <label for="hero-video-credit">Credit <span class="text-slate-400">(optional)</span></label>
                        <input type="text" id="hero-video-credit" name="credit" maxlength="120" value="<?= esc($vv('credit'), 'attr') ?>" placeholder="e.g. the Pexels creator">
                    </div>
                    <div class="field">
                        <label for="hero-video-credit-url">Credit link <span class="text-slate-400">(optional)</span></label>
                        <input type="url" id="hero-video-credit-url" name="credit_url" maxlength="255" value="<?= esc($vv('credit_url'), 'attr') ?>" placeholder="https://www.pexels.com/video/...">
                        <?php if ($err('video_credit_url')): ?>
                            <div class="err"><?= esc($err('video_credit_url')) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="hero-video-sort">Sort</label>
                        <input type="text" id="hero-video-sort" name="sort_order" class="w-20" value="<?= count($videos) ?>">
                    </div>
                </div>
                <input type="hidden" name="is_active" value="1">
                <input type="hidden" name="form" value="video">
                <button class="btn btn-primary btn-xs" <?= $videosFull ? 'disabled' : '' ?>>Add video</button>
                <?php if ($videosFull): ?>
                    <div class="hint">The video rotation is full. Delete one before adding another.</div>
                <?php endif; ?>
            </form>

            <?php if ($videos !== []): ?>
                <div class="mt-5 grid gap-3">
                    <?php foreach ($videos as $vid): ?>
                        <div class="card flex flex-wrap items-start gap-4 p-3">
                            <?php // A preview the admin can play; preload metadata only, so the
                                  // screen does not pull every clip in full. ?>
                            <video class="h-24 w-40 rounded-lg bg-black object-cover" src="<?= esc(base_url($vid['path'])) ?>"
                                   muted controls preload="metadata"></video>
                            <form method="post" action="<?= base_url('admin/hero/videos/' . (int) $vid['id']) ?>" enctype="multipart/form-data"
                                  class="flex flex-1 flex-wrap items-end gap-3">
                                <?= csrf_field() ?>
                                <div class="field mb-0">
                                    <label class="text-xs">Sort</label>
                                    <input type="text" name="sort_order" value="<?= (int) $vid['sort_order'] ?>" class="w-16">
                                </div>
                                <div class="field mb-0">
                                    <label class="text-xs">Credit</label>
                                    <input type="text" name="credit" maxlength="120" value="<?= esc((string) $vid['credit'], 'attr') ?>" class="w-40">
                                </div>
                                <div class="field mb-0">
                                    <label class="text-xs">Credit link</label>
                                    <input type="url" name="credit_url" maxlength="255" value="<?= esc((string) $vid['credit_url'], 'attr') ?>" class="w-56">
                                </div>
                                <div class="field mb-0">
                                    <label class="text-xs">Active</label>
                                    <input type="checkbox" name="is_active" value="1" <?= $vid['is_active'] ? 'checked' : '' ?>>
                                </div>
                                <div class="field mb-0">
                                    <label class="text-xs">Replace video</label>
                                    <input type="file" name="video" accept="video/mp4,video/webm" class="w-56 text-xs">
                                </div>
                                <button class="btn btn-primary btn-xs">Save</button>
                            </form>
                            <form method="post" action="<?= base_url('admin/hero/videos/' . (int) $vid['id'] . '/delete') ?>"
                                  data-confirm="Delete this video? The file goes too.">
                                <?= csrf_field() ?>
                                <button class="btn btn-ghost btn-xs text-brand-crimson">Delete</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

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
                        <img src="<?= esc(base_url($img['path_sm'] ?: $img['path'])) ?>"
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
