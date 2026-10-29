<?php
/**
 * Categories the typed words point at, from the discovery model
 * (DiscoveryModelService): "cancer" names no category, but most profiles
 * that say it are oncologists. Plain links to each category's page, never a
 * filter on these results, so nothing found by the words is hidden.
 *
 * @var list<array{id:int,name:string,slug:string,profiles:int}> $related
 */
if ($related !== []): ?>
<nav class="search-related" aria-label="Related categories">
    <span class="search-related-label">Related categories</span>
    <ul class="search-reading-chips">
        <?php foreach ($related as $row): ?>
            <li><a class="search-chip" href="<?= esc(base_url('directory/' . $row['slug']), 'attr') ?>"><?= esc($row['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>
<?php endif;
