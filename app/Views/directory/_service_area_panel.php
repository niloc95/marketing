<?php
/**
 * "Service areas" — where a mobile business travels to.
 *
 * Renders for a business that travels to customers ('travel' or 'both'). For
 * one whose address is hidden it is what stands in for the address and map,
 * so it always says something, even with no areas listed: "in {city} and
 * surrounding areas". A profile that hides its address must not read as one
 * that is missing it.
 *
 * Areas are owner-typed text and every one is escaped.
 *
 * @var array  $l      the public view of the listing (listing_public_view())
 * @var string $class  extra classes for the panel wrapper
 */
$location = (string) ($l['customer_location'] ?? 'visit');
if (! in_array($location, ['travel', 'both'], true)) {
    return;
}

$areas = listing_service_areas($l);
$city  = trim((string) ($l['city'] ?? ''));
if ($areas === [] && $location === 'both') {
    return; // the address and map already say where to find them
}

$class = $class ?? '';
$named = $areas !== [] ? $areas : array_filter([$city]);
$list  = count($named) > 1
    ? implode(', ', array_slice($named, 0, -1)) . ' and ' . end($named)
    : (string) (reset($named) ?: '');
?>
<div class="panel <?= esc($class, 'attr') ?>" id="service-areas">
    <h3>Service areas</h3>
    <p class="flex items-start gap-1.5 text-sm font-medium text-slate-900 dark:text-white">
        <?= lucide('car', 'mt-0.5 h-4 w-4 shrink-0') ?>
        <span><?= $location === 'travel' ? 'Mobile service: we travel to you' : 'We also travel to customers' ?></span>
    </p>
    <?php if ($list !== ''): ?>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">We travel to customers in <?= esc($list) ?> and surrounding areas.</p>
    <?php endif; ?>
    <?php if (count($areas) > 1): ?>
        <div class="taglist mt-3">
            <?php foreach ($areas as $area): ?><span class="tag"><?= esc($area) ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
