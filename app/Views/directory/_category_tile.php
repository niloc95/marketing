<?php
/**
 * One tile in the homepage "Explore by Category" grid.
 *
 * @var array $c Category row annotated with listing_count (see DirectoryService::topCategories()).
 */
$count = (int) ($c['listing_count'] ?? 0);
?>
<a class="cat-tile" href="<?= base_url('directory/' . ($c['slug'] ?? '')) ?>">
    <span class="cat-tile-icon" aria-hidden="true"><?= esc(category_group_emoji($c['group_name'] ?? null)) ?></span>
    <h3 class="cat-tile-name"><?= esc($c['name'] ?? '') ?></h3>
    <?php if (! empty($c['group_name'])): ?>
        <p class="cat-tile-group"><?= esc($c['group_name']) ?></p>
    <?php endif; ?>
    <span class="cat-tile-foot">
        <span class="cat-tile-count"><?= number_format($count) ?> profile<?= $count === 1 ? '' : 's' ?></span>
        <span class="cat-tile-more">Browse <span class="cat-tile-arrow" aria-hidden="true">&rarr;</span></span>
    </span>
</a>
