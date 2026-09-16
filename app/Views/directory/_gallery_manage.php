<?php
/**
 * Current gallery photos, with a delete button on each.
 *
 * Rendered by _form_fields.php, directly above the "add photos" input, so the
 * photos a listing already has and the control that adds more are one place.
 * It used to sit at the top of the edit pages, outside the listing form, which
 * left the upload at the bottom of a long form, far from the photos it adds to.
 *
 * That move is why there are no <form>s here any more: this is now inside the
 * listing form, and forms cannot nest. Each delete is a submit button carrying
 * its own formaction, so:
 *
 *  - with JavaScript, directory.js catches the click and deletes in place over
 *    fetch — a reload would throw away every unsaved edit in the form around it;
 *  - without it, the button posts the listing form to the delete URL instead of
 *    to Save. The endpoint reads only the CSRF token from that body, deletes
 *    and redirects back to the edit page, exactly as the old per-photo form did.
 *
 * formnovalidate so a required field left blank elsewhere in the form cannot
 * block a delete.
 *
 * @var array  $photos     rows from DirectoryListingPhotoModel::forListing()
 * @var string $deleteBase URL prefix the photo id is appended to
 * @var int    $max        gallery cap, for the remaining-slots line
 */
$max  = $max ?? \App\Controllers\Listing::GALLERY_MAX;
$used = count($photos);
?>
<?php if ($photos !== []): ?>
    <div class="photo-manage" data-photo-manage data-csrf-name="<?= esc(csrf_token(), 'attr') ?>">
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
                    <button type="submit"
                            class="photo-manage-remove"
                            formaction="<?= esc(rtrim($deleteBase, '/') . '/' . (int) $p['id'], 'attr') ?>"
                            formmethod="post"
                            formnovalidate
                            data-photo-delete
                            aria-label="Delete <?= esc($p['original_name'] ?? 'this photo', 'attr') ?>"
                            data-confirm="Delete this photo? This cannot be undone.">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
