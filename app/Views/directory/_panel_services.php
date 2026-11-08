<?php
/**
 * The service menu and its prices. See _panel_description.php for the contract.
 *
 * The heading is the single clearest signal that a page belongs to its vertical:
 * a restaurant calls this "Menu", a practice "Treatments & fees", a lodge
 * "Packages & rates". price_label is free text by design ("from R150", "POA");
 * link_url, when set, turns the service name into a link.
 *
 * A food listing's uploaded menu (ListingMenuService) sits at the top of the
 * same panel: it answers the same question as the typed list, and a restaurant
 * with only a PDF must still get a "Menu" panel. $l['menu'] is already empty
 * for anything that is not a food listing — DirectoryService::getProfile()
 * decides that, not this view.
 *
 * A profile that takes online bookings shows its booking services here
 * instead of its own list (decision T28, Directory::show() fills
 * _booking_services): length, price, who offers it on a Verified or Plus
 * profile, and a Book link that opens the booking page on that service. Its
 * own list stays saved and comes back if bookings are switched off.
 *
 * @var array $l
 * @var array $v
 */
$menu    = $l['menu'] ?? [];
$booking = $l['_booking_services'] ?? [];

if (empty($l['services']) && $menu === [] && $booking === []) {
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
        <a class="btn-ghost menu-file" href="<?= esc(base_url('directory/' . $l['slug'] . '/menu')) ?>" target="_blank" rel="noopener">
            View the full menu (PDF<?= $size !== '' ? ', ' . $size : '' ?>)
            <?= lucide('external-link', 'h-4 w-4 shrink-0') ?>
        </a>
    <?php elseif ($pages !== []): ?>
        <div class="menu-pages">
            <?php foreach ($pages as $i => $page): ?>
                <?php // Plain links to the full-size page: a menu is read, not
                      // flicked through, so it opens at full resolution. ?>
                <a class="menu-page" href="<?= esc(base_url($page['path'])) ?>" target="_blank" rel="noopener">
                    <img src="<?= esc(base_url($page['path'])) ?>" loading="lazy"
                         alt="<?= esc(($l['display_name'] ?? '') !== '' ? $l['display_name'] . ', menu page ' : 'Menu page ', 'attr') ?><?= $i + 1 ?> of <?= count($pages) ?>"
                         <?= ! empty($page['width']) ? 'width="' . (int) $page['width'] . '" height="' . (int) $page['height'] . '"' : '' ?>>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($booking !== []): ?>
        <?php // Who offers it only helps when there is a choice of person. ?>
        <?php $withNames = count($l['_practitioners'] ?? []) > 1; ?>
        <ul class="service-list service-list-book<?= $menu !== [] ? ' mt-4' : '' ?>">
            <?php foreach ($booking as $svc): ?>
                <li>
                    <div class="service-main">
                        <span class="service-name"><?= esc($svc['name']) ?></span>
                        <?php
                        $meta = array_filter([
                            $svc['duration_min'] > 0 ? $svc['duration_min'] . ' min' : '',
                            $withNames && count($svc['people']) > 0 ? 'with ' . implode(', ', $svc['people']) : '',
                        ]);
                        ?>
                        <?php if ($meta !== []): ?><span class="service-meta"><?= esc(implode(', ', $meta)) ?></span><?php endif; ?>
                        <?php if ($svc['description'] !== ''): ?>
                            <span class="service-desc"><?= esc($svc['description']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="service-side">
                        <?php if ($svc['price'] !== ''): ?><span class="service-price"><?= esc($svc['price']) ?></span><?php endif; ?>
                        <?php if ($svc['book_url'] !== ''): ?>
                            <a class="service-book" href="<?= esc($svc['book_url'], 'attr') ?>" data-stat="booking">Book<span class="sr-only"> <?= esc($svc['name']) ?></span></a>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif (! empty($l['services'])): ?>
        <ul class="service-list<?= $menu !== [] ? ' mt-4' : '' ?>">
            <?php foreach ($l['services'] as $svc): ?>
                <?php // safe_external_url() again at render, for the reason _contact_panel.php gives. ?>
                <?php $svcUrl = safe_external_url($svc['link_url'] ?? ''); ?>
                <li>
                    <?php if ($svcUrl !== ''): ?>
                        <a class="service-name" href="<?= esc($svcUrl) ?>" target="_blank" rel="noopener nofollow"><?= esc($svc['name']) ?><?= lucide('external-link', 'h-3.5 w-3.5') ?></a>
                    <?php else: ?>
                        <span class="service-name"><?= esc($svc['name']) ?></span>
                    <?php endif; ?>
                    <?php if (! empty($svc['price_label'])): ?><span class="service-price"><?= esc($svc['price_label']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
