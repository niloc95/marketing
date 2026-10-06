<?php
/**
 * One business in the home page's featured carousel: a tall card filled by a
 * picture, the name and what the business is at the top, and a "View profile"
 * pill at the bottom (after deepmind.google's home carousel).
 *
 * The picture, in order of preference:
 *   1. the business's own first gallery photo (featured() attaches it as
 *      cover_path)
 *   2. its category's stock photograph, the one the category pages use
 *   3. neither: a quiet light card with the logo (or initials) large in it
 * White type over a photograph, with a scrim behind it; dark type on the plain
 * card. The whole card is the one link, so the pill is a span, not a button.
 *
 * The grid card (_card.php) stays the card for every other page.
 *
 * @var array $l A featured() row.
 */
// Through the public view like every renderer, though only city and province
// are shown here and both survive a hidden address.
$l        = listing_public_view($l);
$name     = $l['display_name'] ?? '';
$parts    = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
$logoUrl  = listing_image_url($l['logo_path'] ?? null);
$meta     = array_filter([$l['category_name'] ?? '', trim(implode(', ', array_filter([$l['city'] ?? '', $l['province'] ?? ''])))]);

$cover = listing_image_url($l['cover_path'] ?? null);
$stock = $cover === '' && ($l['category_slug'] ?? '') !== ''
    ? category_photo(['slug' => $l['category_slug'], 'group_name' => $l['category_group'] ?? ''])
    : null;
$photo = $cover !== '' || $stock !== null;
?>
<a class="showcase-card<?= $photo ? ' showcase-card-photo' : '' ?>" href="<?= esc(base_url('directory/' . ($l['slug'] ?? ''))) ?>">
    <?php if ($cover !== ''): ?>
        <img class="showcase-card-img" src="<?= esc($cover) ?>" alt="" loading="lazy" decoding="async">
    <?php elseif ($stock !== null): ?>
        <img class="showcase-card-img"
             src="<?= esc(base_url($stock['src'])) ?>"
             srcset="<?= esc(base_url($stock['src_sm']), 'attr') ?> 400w, <?= esc(base_url($stock['src']), 'attr') ?> 800w"
             sizes="(min-width: 640px) 25rem, 82vw" alt="" loading="lazy" decoding="async">
    <?php else: ?>
        <span class="showcase-card-mark">
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= esc($logoUrl) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
                <?= esc($initials) ?>
            <?php endif; ?>
        </span>
    <?php endif; ?>

    <span class="showcase-card-head">
        <span class="showcase-card-name"><?= esc($name) ?></span>
        <?php if ($meta !== []): ?>
            <span class="showcase-card-meta"><?= esc(implode(' · ', $meta)) ?></span>
        <?php endif; ?>
    </span>

    <span class="showcase-card-foot">
        <span class="showcase-card-btn">View profile</span>
    </span>
</a>
