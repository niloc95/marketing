<?php
/**
 * One card in the homepage "Browse by Location" grid.
 *
 * @var string            $province
 * @var int               $count
 * @var array<int,string> $cities Busiest first; may be empty.
 * @var int               $i      Position, used only to pick a gradient.
 */
helper('slug');
$cities = $cities ?? [];
// Four brand gradients rotated by position so the row reads as a set rather
// than nine identical panels. Written out in full, never concatenated: Tailwind
// scans this file as plain text, so 'loc-card-' . $n would leave every variant
// unmatched and tree-shaken out of the built stylesheet.
$tints = ['loc-card-1', 'loc-card-2', 'loc-card-3', 'loc-card-4'];
$tint  = $tints[((int) ($i ?? 0)) % count($tints)];
?>
<a class="loc-card <?= $tint ?>" href="<?= base_url('directory/province/' . slugify($province)) ?>">
    <span class="loc-card-count"><?= lucide('map-pin', 'h-3.5 w-3.5 shrink-0') ?><?= number_format((int) $count) ?> profile<?= (int) $count === 1 ? '' : 's' ?></span>
    <h3 class="loc-card-name"><?= esc($province) ?></h3>
    <?php if ($cities !== []): ?>
        <p class="loc-card-cities"><?= esc(implode(' · ', $cities)) ?></p>
    <?php endif; ?>
</a>
