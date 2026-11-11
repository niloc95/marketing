<?php
/**
 * Features & amenities. See _panel_description.php for the contract.
 *
 * The labels are already vertical-specific without anything here: the tick-boxes
 * an owner was offered came from Config\ListingAttributes::forGroup(), so a
 * practice claims "Medical aid accepted" and a salon "Bridal & events".
 *
 * @var array $l
 * @var array $v
 */
$features = listing_feature_labels($l);
if ($features === []) {
    return;
}
?>
<div class="panel mb-5">
    <h2 class="panel-title"><?= esc($v['headings']['features']) ?></h2>
    <ul class="feature-list">
        <?php foreach ($features as $label): ?>
            <li><?= lucide('check', 'feature-check') ?><span><?= esc($label) ?></span></li>
        <?php endforeach; ?>
    </ul>
</div>
