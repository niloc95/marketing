<?php
/**
 * One business in the homepage's featured strip: a wide, quiet frame with the
 * logo large, the name in display type, and the category and town underneath.
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
?>
<a class="feature-card" href="<?= esc(base_url('directory/' . ($l['slug'] ?? ''))) ?>">
    <span class="feature-card-mark">
        <?php if ($logoUrl !== ''): ?>
            <img src="<?= esc($logoUrl) ?>" alt="" loading="lazy" decoding="async">
        <?php else: ?>
            <?= esc($initials) ?>
        <?php endif; ?>
    </span>
    <span class="feature-card-name"><?= esc($name) ?></span>
    <?php if ($meta !== []): ?>
        <span class="feature-card-meta"><?= esc(implode(' · ', $meta)) ?></span>
    <?php endif; ?>
</a>
