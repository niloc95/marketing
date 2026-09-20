<?php
/**
 * The listing's free-form tags. See _panel_description.php for the contract.
 *
 * Tags are the long tail the 160 categories cannot carry, so what they mean
 * varies by vertical and the heading says which: "Special interests" for a
 * practice, "Cuisine" for a restaurant, "Animals we see" for a vet, "What we are
 * known for" for a home baker.
 *
 * @var array $l
 * @var array $v
 */
if (empty($l['tags'])) {
    return;
}
?>
<div class="panel mb-5">
    <h3><?= esc($v['headings']['tags']) ?></h3>
    <div class="taglist">
        <?php foreach ($l['tags'] as $t): ?><span class="tag"><?= esc($t) ?></span><?php endforeach; ?>
    </div>
</div>
