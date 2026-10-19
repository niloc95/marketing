<?php

use App\Services\ListingBrandService;

/**
 * "Brands you carry": the logo strip under the profile description, inside the
 * listing form, right after the photo gallery.
 *
 * Stored brands sit at the top with a delete button each. Like
 * _gallery_manage.php those are submit buttons with their own formaction,
 * because this is inside the listing form and forms cannot nest: directory.js
 * deletes in place, and without it the button posts to the delete URL and
 * comes back.
 *
 * New brands are brand_name[i] + brand_logo[i] rows in the [data-repeat]
 * pattern _team_fields.php uses. Without JavaScript three blank rows render; a
 * blank row is skipped by ListingBrandService::addFromForm().
 *
 * @var array  $brands     rows from ListingBrandService::forListing() (edit pages only)
 * @var string $deleteBase URL prefix the brand id is appended to ('' on signup)
 */
$brands     = $brands ?? [];
$deleteBase = $deleteBase ?? '';
$max        = ListingBrandService::MAX_BRANDS;
$slots      = max(0, $max - count($brands));
$blankRows  = min(3, $slots);

$row = static function ($i): string {
    ob_start(); ?>
    <div class="brand-row" data-repeat-item>
        <input type="text" name="brand_name[<?= $i ?>]" maxlength="<?= ListingBrandService::NAME_MAX ?>"
               placeholder="Brand name, e.g. Nike, Clarins or Kingsley Cola" aria-label="Brand name">
        <input type="file" name="brand_logo[<?= $i ?>]" accept="image/*" aria-label="Brand logo">
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="field" data-brand-upload>
    <label>Brands you carry</label>
    <p class="hint">
        Show the logos of brands you sell, stock or service. They scroll under your description on your profile.
        Up to <?= (int) $max ?>. Add only brands you genuinely carry.
    </p>

    <?php if ($brands !== [] && $deleteBase !== ''): ?>
        <div class="brand-manage" data-brand-manage data-csrf-name="<?= esc(csrf_token(), 'attr') ?>">
            <p class="photo-manage-error" role="alert" data-brand-error hidden></p>
            <div class="brand-manage-grid">
                <?php foreach ($brands as $b): ?>
                    <div class="brand-manage-item" data-brand-item>
                        <img src="<?= esc(base_url($b['logo_path']), 'attr') ?>" alt="<?= esc($b['name'], 'attr') ?>" loading="lazy">
                        <span><?= esc($b['name']) ?></span>
                        <button type="submit"
                                class="photo-manage-remove"
                                formaction="<?= esc(rtrim($deleteBase, '/') . '/' . (int) $b['id'], 'attr') ?>"
                                formmethod="post"
                                formnovalidate
                                data-brand-delete
                                aria-label="Delete <?= esc($b['name'], 'attr') ?>"
                                data-confirm="Delete this brand? This cannot be undone.">&times;</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div data-repeat <?= $slots > 0 ? '' : 'hidden' ?> data-brand-open>
        <div data-repeat-list>
            <?php for ($i = 0; $i < $blankRows; $i++): ?>
                <?= $row($i) ?>
            <?php endfor; ?>
        </div>
        <template data-repeat-template><?= $row('__i__') ?></template>
        <button type="button" class="btn btn-ghost btn-xs" data-repeat-add
                data-repeat-max="<?= (int) $slots ?>"
                data-repeat-full="You have added the maximum of <?= (int) $max ?> brands.">
            + Add another brand
        </button>
        <div class="hint">A logo on a clear or white background works best. JPEG, PNG, WebP or GIF, up to 10 MB.</div>
    </div>
    <div class="hint" data-brand-full <?= $slots > 0 ? 'hidden' : '' ?>>This profile already shows the maximum of <?= (int) $max ?> brands. Delete one above to add another.</div>
</div>
