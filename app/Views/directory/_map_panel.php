<?php
/**
 * The map panel — an interactive Leaflet map plus a Get directions link.
 *
 * Extracted from show.php so a branch gets the same map as the primary. The
 * whole panel is driven by map_point(), which reads latitude/longitude/
 * geocode_precision off any array, so a practice-location row works unchanged.
 *
 * directory.js injects the Leaflet library and the tiles only when this scrolls
 * into view, so the majority of visitors who never reach it pay nothing for it.
 * Without JavaScript the panel is a heading and a directions link, which is the
 * part that actually gets someone to the door.
 *
 * Needs real coordinates, unlike the old address-text embed. A row that never
 * geocoded shows no map; geocoding_status is how those get found, and the
 * owner's pin picker is how a listing's gets fixed.
 *
 * NOTE: every one of these on a page initialises independently — directory.js
 * looks the panels up with querySelectorAll and gives each its own
 * IntersectionObserver. It used to be a single querySelector, which is exactly
 * why a second map on the page would have stayed blank.
 *
 * @var array  $row     a listing row, or a practice-location row
 * @var string $name    what the marker and the aria-label call this place
 * @var string $heading panel heading
 * @var string $class   extra classes for the panel wrapper
 */
$map = map_point($row);
if ($map === null) {
    return;
}

$heading = $heading ?? 'Location';
$class   = $class ?? '';
$dirUrl  = map_directions_url($row);
$wazeUrl = map_waze_url($row);
?>
<div class="panel <?= esc($class, 'attr') ?>">
    <h3><?= esc($heading) ?></h3>
    <div class="map-view"
         data-map-view
         data-lat="<?= esc((string) $map['lat'], 'attr') ?>"
         data-lng="<?= esc((string) $map['lng'], 'attr') ?>"
         data-zoom="<?= esc((string) $map['zoom'], 'attr') ?>"
         data-label="<?= esc($name . ' — ' . $map['label'], 'attr') ?>"
         data-tile-url="<?= esc(config('Directory')->mapTileUrl(), 'attr') ?>"
         data-tile-attribution="<?= esc(config('Directory')->mapTileAttribution(), 'attr') ?>"
         data-icon-path="<?= base_url('assets/vendor/leaflet/images/') ?>"
         data-leaflet-css="<?= base_url('assets/vendor/leaflet/leaflet.css') ?>"
         data-leaflet-js="<?= base_url('assets/vendor/leaflet/leaflet.js') ?>">
        <div class="map-view-canvas" data-map-view-canvas role="application"
             aria-label="Map showing the location of <?= esc($name, 'attr') ?>"></div>
    </div>
    <?php if ($map['approximate']): ?>
        <p class="map-approx">Approximate location &mdash; use the address above for exact directions.</p>
    <?php endif; ?>
    <?php if ($dirUrl !== ''): ?>
        <a class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($dirUrl, 'attr') ?>" target="_blank" rel="noopener nofollow">Get directions<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
    <?php endif; ?>
    <?php if ($wazeUrl !== ''): ?>
        <a class="mt-2 ml-3 inline-flex items-center gap-1 text-sm font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($wazeUrl, 'attr') ?>" target="_blank" rel="noopener nofollow">Navigate with Waze<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
    <?php endif; ?>
</div>
