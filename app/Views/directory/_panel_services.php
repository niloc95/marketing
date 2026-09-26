<?php
/**
 * The service menu and its prices. See _panel_description.php for the contract.
 *
 * The heading is the single clearest signal that a page belongs to its vertical:
 * a restaurant calls this "Menu", a practice "Treatments & fees", a lodge
 * "Packages & rates". price_label is free text by design ("from R150", "POA").
 *
 * A food listing's uploaded menu (ListingMenuService) sits at the top of the
 * same panel: it answers the same question as the typed list, and a restaurant
 * with only a PDF must still get a "Menu" panel. $l['menu'] is already empty
 * for anything that is not a food listing — DirectoryService::getProfile()
 * decides that, not this view.
 *
 * @var array $l
 * @var array $v
 */
$menu = $l['menu'] ?? [];

if (empty($l['services']) && $menu === []) {
    return;
}

$pdf   = array_values(array_filter($menu, static fn (array $m): bool => $m['kind'] === 'pdf'))[0] ?? null;
$pages = array_values(array_filter($menu, static fn (array $m): bool => $m['kind'] === 'image'));

// The size is on the link because a PDF is a download on a phone plan.
$bytes = (int) ($pdf['bytes'] ?? 0);
$size  = $bytes <= 0 ? '' : ($bytes >= 1_048_576
    ? number_format($bytes / 1_048_576, 1) . ' MB'
    : max(1, (int) round($bytes / 1024)) . ' KB');
?>
<div class="panel mb-5">
    <h3><?= esc($v['headings']['services']) ?></h3>

    <?php if ($pdf !== null): ?>
        <?php // A new tab: the PDF opens in the browser's own viewer, and closing
              // it must not lose the profile they were reading. ?>
        <a class="btn-ghost menu-file" href="<?= esc(base_url('directory/' . $l['slug'] . '/menu'), 'attr') ?>" target="_blank" rel="noopener">
            View the full menu (PDF<?= $size !== '' ? ', ' . $size : '' ?>)
            <?= lucide('external-link', 'h-4 w-4 shrink-0') ?>
        </a>
    <?php elseif ($pages !== []): ?>
        <div class="menu-pages">
            <?php foreach ($pages as $i => $page): ?>
                <?php // Plain links to the full-size page: a menu is read, not
                      // flicked through, so it opens at full resolution. ?>
                <a class="menu-page" href="<?= esc(base_url($page['path']), 'attr') ?>" target="_blank" rel="noopener">
                    <img src="<?= esc(base_url($page['path']), 'attr') ?>" loading="lazy"
                         alt="Menu page <?= $i + 1 ?> of <?= count($pages) ?>"
                         <?= ! empty($page['width']) ? 'width="' . (int) $page['width'] . '" height="' . (int) $page['height'] . '"' : '' ?>>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (! empty($l['services'])): ?>
        <ul class="service-list<?= $menu !== [] ? ' mt-4' : '' ?>">
            <?php foreach ($l['services'] as $svc): ?>
                <li>
                    <span class="service-name"><?= esc($svc['name']) ?></span>
                    <?php if (! empty($svc['price_label'])): ?><span class="service-price"><?= esc($svc['price_label']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
