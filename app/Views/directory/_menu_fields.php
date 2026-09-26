<?php

use App\Services\ListingMenuService;

/**
 * The menu upload inside the listing form — one PDF, or photos of the pages.
 *
 * Shown only for food categories. Each category <option> carries data-menu,
 * and directory.js reveals this field when the chosen one is a food business,
 * exactly as the features and facet panels swap. With no category chosen yet
 * it renders visible, so a signup works with JavaScript off;
 * HandlesListingUploads::applyMenuUpload() re-checks the saved category and
 * refuses a menu for anything else.
 *
 * Not the [data-image-upload] module: that one downsizes images in the browser
 * and would choke on a PDF. The server resizes photos itself.
 *
 * @var callable $v          the form's value reader
 * @var array    $categories rows with id, slug and group_name
 * @var array    $menuFiles  the stored menu, empty on signup
 */
$offered = null;
foreach ($categories as $c) {
    if ((string) $c['id'] === (string) $v('category_id')) {
        $offered = ListingMenuService::offersMenu($c['group_name'] ?? null, $c['slug'] ?? null);
        break;
    }
}

$pdf   = array_values(array_filter($menuFiles, static fn (array $m): bool => $m['kind'] === 'pdf'))[0] ?? null;
$pages = array_values(array_filter($menuFiles, static fn (array $m): bool => $m['kind'] === 'image'));
?>
<div class="field" data-menu-field <?= $offered === false ? 'hidden' : '' ?>>
    <label for="menu-input">Menu</label>

    <?php if ($menuFiles !== []): ?>
        <div class="upload-current">
            <?php if ($pdf !== null): ?>
                <span class="hint">Current menu: <?= esc((string) ($pdf['original_name'] ?: 'menu.pdf')) ?> (PDF)</span>
            <?php else: ?>
                <?php foreach ($pages as $page): ?>
                    <img src="<?= esc(base_url($page['path']), 'attr') ?>" alt="Current menu page">
                <?php endforeach; ?>
                <span class="hint">Current menu: <?= count($pages) ?> page<?= count($pages) === 1 ? '' : 's' ?>.</span>
            <?php endif; ?>
        </div>
        <label class="attr-option"><input type="checkbox" name="menu_remove" value="1"> Remove the current menu</label>
    <?php endif; ?>

    <?php // accept lists image/* rather than MIME types for the reason the logo
          // input gives: it is what makes iOS offer the photo library. ?>
    <input type="file" id="menu-input" name="menu[]" accept="application/pdf,.pdf,image/*" multiple>
    <div class="hint">
        One PDF, or photos of up to <?= ListingMenuService::MAX_PAGES ?> pages — 10 MB each.
        <?= $menuFiles !== [] ? 'Uploading replaces the current menu.' : 'Customers can open it straight from your profile.' ?>
    </div>
</div>
