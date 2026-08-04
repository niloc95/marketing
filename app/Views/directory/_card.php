<?php
/** @var array $l */
$name = $l['display_name'] ?? '';
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));
$logo = $l['logo_path'] ?? '';
$logoUrl = $logo === '' ? '' : (preg_match('#^https?://#i', $logo) ? $logo : base_url($logo));
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
        <div class="avatar">
            <?php if ($logoUrl !== ''): ?><img src="<?= esc($logoUrl, 'attr') ?>" alt=""><?php else: ?><?= esc($initials) ?><?php endif; ?>
        </div>
        <div class="min-w-0">
            <?php if (! empty($l['category_name'])): ?>
                <span class="badge mb-1"><?= esc($l['category_name']) ?></span>
            <?php endif; ?>
            <h3 class="truncate text-base font-semibold">
                <a class="text-slate-900 dark:text-white hover:text-primary-500 dark:hover:text-primary-300" href="<?= base_url('directory/' . ($l['slug'] ?? '')) ?>"><?= esc($name) ?></a>
            </h3>
        </div>
    </div>

    <div class="mt-auto flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
        <?php if ($place !== ''): ?><span>📍 <?= esc($place) ?></span><?php endif; ?>
        <?php if ($distance !== ''): ?><span class="card-distance"><?= esc($distance) ?></span><?php endif; ?>
        <?php if (! empty($l['is_featured'])): ?><span class="badge badge-featured">★ Featured</span><?php endif; ?>
    </div>
</div>
