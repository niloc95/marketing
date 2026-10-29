<?php
/**
 * What the discovery model (DiscoveryModelService) added to a search, said
 * out loud under the box, like _search_reading.php:
 *
 *   - a spelling it corrected ("oncoligist" found nothing, "oncologist" did),
 *     with a link back to the words exactly as typed (exact=1 skips it);
 *   - the related words it also looked for ("chemotherapy" for "cancer"),
 *     which only ever add results, ranked below direct matches;
 *   - categories the words point at, as plain links to each category's
 *     page. Never a filter on these results, so nothing found is hidden.
 *
 * @var list<array{id:int,name:string,slug:string,profiles:int}> $related
 * @var list<string> $expanded
 * @var string|null  $correctedFrom the words as typed, when corrected
 * @var string       $q             the words searched
 */
if ($correctedFrom === null && $expanded === [] && $related === []) {
    return;
}
?>
<div class="search-reading">
<?php if ($correctedFrom !== null): ?>
<p class="search-corrected" role="status">
    Showing results for <strong><?= esc($q) ?></strong>.
    <a href="<?= esc(base_url('directory') . '?' . http_build_query(['q' => $correctedFrom, 'exact' => '1']), 'attr') ?>">Discover “<?= esc($correctedFrom) ?>” instead</a>
</p>
<?php endif; ?>
<?php if ($expanded !== []): ?>
<p class="search-expanded">Also including profiles that mention <?= esc(implode(', ', $expanded)) ?>.</p>
<?php endif; ?>
<?php if ($related !== []): ?>
<nav class="search-related" aria-label="Related categories">
    <span class="search-related-label">Related categories</span>
    <ul class="search-reading-chips">
        <?php foreach ($related as $row): ?>
            <li><a class="search-chip" href="<?= esc(base_url('directory/' . $row['slug']), 'attr') ?>"><?= esc($row['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>
</div>
