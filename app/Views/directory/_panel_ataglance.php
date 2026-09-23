<?php
/**
 * "At a glance" — the structured facts from Config\ListingFacets, as a
 * definition list. See _panel_description.php for the contract.
 *
 * Nothing here is vertical-specific in this file, because everything already
 * is: which facets a listing was offered, their labels and their order all came
 * from ListingFacets::forCategory(), resolved in DirectoryService::getProfile()
 * against the category the listing has *now*.
 *
 * This is the one panel most verticals will render empty — no facets are
 * defined outside education yet — which is exactly why it returns early. A
 * panel with nothing to show costs a function call; see the note on 'order' in
 * Config\Verticals.
 *
 * @var array $l
 * @var array $v
 */
$facets = $l['facets'] ?? [];
if ($facets === []) {
    return;
}
?>
<div class="panel mb-5">
    <h3><?= esc($v['headings']['ataglance']) ?></h3>
    <dl class="facet-list">
        <?php foreach ($facets as $facet): ?>
            <div class="facet-row">
                <dt><?= esc($facet['label']) ?></dt>
                <dd>
                    <?php if ($facet['type'] === 'range'): ?>
                        <?= esc(facet_range_label($facet['num_low'], $facet['num_high'], $facet['unit'])) ?>
                    <?php else: ?>
                        <?= esc(implode(', ', $facet['values'])) ?>
                    <?php endif; ?>
                </dd>
            </div>
        <?php endforeach; ?>
    </dl>
</div>
