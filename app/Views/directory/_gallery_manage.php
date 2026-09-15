<?php
/**
 * Current gallery photos, with a delete button on each.
 *
 * Rendered by directory/manage_edit.php and admin/edit.php. It sits *outside*
 * the main listing form and cannot move into _form_fields.php: each thumbnail
 * carries its own <form>, and HTML forbids nesting one form inside another.
 *
 * The delete forms still work as plain posts, but directory.js intercepts them
 * and deletes in place: a reload here used to throw away every unsaved edit in
 * the main form below. The data-photo-* attributes are that script's contract.
 *
 * @var array  $photos     rows from DirectoryListingPhotoModel::forListing()
 * @var string $deleteBase URL prefix the photo id is appended to
 * @var int    $max        gallery cap, for the remaining-slots line
 */
$max  = $max ?? \App\Controllers\Listing::GALLERY_MAX;
$used = count($photos);
?>
<?php if ($photos !== []): ?>
    <div class="photo-manage" data-photo-manage>
        <div class="photo-manage-head">
            <h2>Current photos</h2>
            <span class="hint" data-photo-count data-max="<?= (int) $max ?>">
                <?= $used ?> of <?= (int) $max ?> used<?= $used < $max ? ' — ' . ($max - $used) . ' slot' . ($max - $used === 1 ? '' : 's') . ' left' : '' ?>
            </span>
        </div>
        <p class="photo-manage-error" role="alert" data-photo-error hidden></p>
        <div class="photo-manage-grid">
            <?php foreach ($photos as $p): ?>
                <div class="photo-manage-item" data-photo-item>
                    <?php // Both URLs are server-generated, so escaping them is belt and
                          // braces rather than a fix — but it is what every other
                          // attribute here does, and the entities it produces decode
                          // back to the same URL before the browser parses it. ?>
                    <img src="<?= esc(base_url($p['path']), 'attr') ?>"
                         alt="<?= esc($p['original_name'] ?? 'Profile photo') ?>"
                         loading="lazy">
                    <form method="post" action="<?= esc(rtrim($deleteBase, '/') . '/' . (int) $p['id'], 'attr') ?>" data-photo-delete>
                        <?= csrf_field() ?>
                        <button type="submit"
                                class="photo-manage-remove"
                                aria-label="Delete <?= esc($p['original_name'] ?? 'this photo', 'attr') ?>"
                                data-confirm="Delete this photo? This cannot be undone.">&times;</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
