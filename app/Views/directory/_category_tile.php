<?php
/**
 * One tile in the homepage "Explore by Category" grid.
 *
 * @var array      $c     Category row annotated with listing_count (see DirectoryService::topCategories()).
 * @var array|null $photo Optional; pass category_photos() for the whole grid so tiles don't repeat a photo.
 */
$count = (int) ($c['listing_count'] ?? 0);
// One lookup for both halves: the icon and its colour are the same decision.
$style = category_group_style($c['group_name'] ?? null);
$photo ??= category_photo($c);
?>
<?php if ($photo !== null): ?>
<a class="cat-tile cat-tile-photo" href="<?= base_url('directory/' . ($c['slug'] ?? '')) ?>">
    <?php // Decorative: the category name below is the link's text. ?>
    <img class="cat-tile-img" src="<?= base_url($photo['src']) ?>"
         srcset="<?= base_url($photo['src_sm']) ?> 400w, <?= base_url($photo['src']) ?> 800w"
         sizes="(min-width: 1280px) 293px, (min-width: 768px) 25vw, 50vw"
         alt="" loading="lazy" decoding="async">
    <span class="cat-tile-shade" aria-hidden="true"></span>
    <span class="cat-tile-body">
        <span class="cat-tile-icon"><?= lucide($style['icon'], 'h-5 w-5') ?></span>
        <h3 class="cat-tile-name"><?= esc($c['name'] ?? '') ?></h3>
        <?php if (! empty($c['group_name'])): ?>
            <p class="cat-tile-group"><?= esc($c['group_name']) ?></p>
        <?php endif; ?>
        <span class="cat-tile-foot">
            <span class="cat-tile-count"><?= number_format($count) ?> profile<?= $count === 1 ? '' : 's' ?></span>
            <span class="cat-tile-more">Browse <?= lucide('arrow-right', 'cat-tile-arrow h-4 w-4') ?></span>
        </span>
    </span>
</a>
<?php else: ?>
<a class="cat-tile" href="<?= base_url('directory/' . ($c['slug'] ?? '')) ?>">
    <span class="cat-tile-icon <?= $style['tint'] ?>"><?= lucide($style['icon'], 'h-6 w-6') ?></span>
    <h3 class="cat-tile-name"><?= esc($c['name'] ?? '') ?></h3>
    <?php if (! empty($c['group_name'])): ?>
        <p class="cat-tile-group"><?= esc($c['group_name']) ?></p>
    <?php endif; ?>
    <span class="cat-tile-foot">
        <span class="cat-tile-count"><?= number_format($count) ?> profile<?= $count === 1 ? '' : 's' ?></span>
        <span class="cat-tile-more">Browse <?= lucide('arrow-right', 'cat-tile-arrow h-4 w-4') ?></span>
    </span>
</a>
<?php endif; ?>
