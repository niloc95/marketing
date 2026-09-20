<?php
/**
 * @var array $l
 * @var bool  $hideVenue  true on a venue's own page, where the chip is noise
 */
$hideVenue = $hideVenue ?? false;
$name = $l['display_name'] ?? '';
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
$logoUrl = listing_image_url($l['logo_path'] ?? null);
$place = trim(implode(', ', array_filter([$l['city'] ?? '', $l['province'] ?? ''])));

// Distance, present only on a "near me" search. Phrased as loosely as the pin
// deserves: a street/suburb/city match is a centroid that can sit hundreds of
// metres from the door, so "1.2 km" there would claim an accuracy we do not
// have. Only hand-placed and house-number pins get a firm figure.
$distance = '';
if (isset($l['distance_m'])) {
    $km       = ((float) $l['distance_m']) / 1000;
    $isApprox = ! in_array($l['geocode_precision'] ?? null, ['manual', 'exact'], true);

    if ($isApprox) {
        $distance = $km < 1 ? 'under 1 km away' : '~' . round($km) . ' km away';
    } else {
        $distance = $km < 1
            ? round(((float) $l['distance_m']) / 100) * 100 . ' m away'
            : number_format($km, 1) . ' km away';
    }
}
?>
<div class="card flex flex-col gap-3 p-4 transition-shadow hover:shadow-brand-lg">
    <div class="flex items-start gap-3">
        <?php // avatar-logo only when there is a logo: it swaps the square crop for
              // a contained fit that shows a wide lockup whole. Without an image the
              // box stays square for the initials. ?>
        <div class="avatar<?= $logoUrl !== '' ? ' avatar-logo' : '' ?>">
            <?php if ($logoUrl !== ''): ?><img src="<?= esc($logoUrl, 'attr') ?>" alt=""><?php else: ?><?= esc($initials) ?><?php endif; ?>
        </div>
        <div class="min-w-0">
            <?php if (! empty($l['category_name'])): ?>
                <?php // The group's icon and colour, and deliberately nothing more.
                      // A result grid is a mix of categories, and giving each card a
                      // full colour treatment reads as noise rather than as identity
                      // — the vertical belongs to the pages you arrive AT. The badge
                      // already carries enough signal to tell two verticals apart at
                      // a glance. ?>
                <span class="badge badge-category mb-1 gap-1 <?= category_group_tint($l['category_group'] ?? null) ?>"><?= lucide(category_group_icon($l['category_group'] ?? null), 'h-3 w-3 shrink-0') ?><?= esc($l['category_name']) ?></span>
            <?php endif; ?>
            <h3 class="truncate text-base font-semibold">
                <a class="text-slate-900 dark:text-white hover:text-primary-500 dark:hover:text-primary-300" href="<?= esc(base_url('directory/' . ($l['slug'] ?? '')), 'attr') ?>"><?= esc($name) ?></a>
            </h3>
        </div>
    </div>

    <div class="mt-auto flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
        <?php if ($place !== ''): ?><span class="inline-flex items-center gap-1"><?= lucide('map-pin', 'h-3.5 w-3.5 shrink-0') ?><?= esc($place) ?></span><?php endif; ?>
        <?php if ($distance !== ''): ?><span class="card-distance"><?= esc($distance) ?></span><?php endif; ?>
        <?php // Verified before Featured: one says we checked this business, the
              // other says we are promoting it. The stronger claim reads first. ?>
        <?php if (listing_is_verified_business($l)): ?><span class="badge badge-verified gap-1"><?= lucide('badge-check', 'h-3.5 w-3.5 shrink-0') ?>Verified Business</span><?php endif; ?>
        <?php if (! empty($l['is_featured'])): ?><span class="badge badge-featured gap-1"><?= lucide('star', 'h-3.5 w-3.5 shrink-0') ?>Featured</span><?php endif; ?>
        <?php // The complex this shop sits in. Only present on the queries that
              // join the venue (browse/featured/recent/related), so nothing else
              // needs to know venues exist to render a card. ?>
        <?php if (! $hideVenue && ! empty($l['venue_name'])): ?>
            <a class="badge badge-venue gap-1" href="<?= esc(base_url('directory/at/' . $l['venue_slug']), 'attr') ?>"><?= lucide('building-2', 'h-3.5 w-3.5 shrink-0') ?><?= esc($l['venue_name']) ?></a>
        <?php endif; ?>
    </div>
</div>
