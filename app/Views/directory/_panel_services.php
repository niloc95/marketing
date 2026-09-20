<?php
/**
 * The service menu and its prices. See _panel_description.php for the contract.
 *
 * The heading is the single clearest signal that a page belongs to its vertical:
 * a restaurant calls this "Menu", a practice "Treatments & fees", a lodge
 * "Packages & rates". price_label is free text by design ("from R150", "POA").
 *
 * @var array $l
 * @var array $v
 */
if (empty($l['services'])) {
    return;
}
?>
<div class="panel mb-5">
    <h3><?= esc($v['headings']['services']) ?></h3>
    <ul class="service-list">
        <?php foreach ($l['services'] as $svc): ?>
            <li>
                <span class="service-name"><?= esc($svc['name']) ?></span>
                <?php if (! empty($svc['price_label'])): ?><span class="service-price"><?= esc($svc['price_label']) ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
