<?php
/**
 * The location panel: the address, an interactive Leaflet map, and Get
 * directions with Waze beside it.
 *
 * The one place a profile shows an address and its directions (owner, 4 Nov
 * 2026). The Contact panel used to carry them too, so every profile showed Get
 * directions and Waze twice; now Contact is phone, email and links only. The
 * links carry data-stat="directions", which Contact's used to, so the owner's
 * statistics still count them.
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
 * The map needs real coordinates. A row that never geocoded shows the address
 * and directions without a map; geocoding_status is how those get found, and
 * the owner's pin picker is how a listing's gets fixed. A hidden address (a
 * mobile business) renders nothing.
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
// A hidden address has no coordinates in its public copy already; the guard
// is here so a caller that forgets listing_public_view() still draws no pin,
// and, as on the old Contact row, no directions to the town either.
//
// map_address_text() is the one definition of the address, so the text shown
// and the place the links navigate to can never disagree.
$addressPublic = listing_address_public($row);
$map  = $addressPublic ? map_point($row) : null;
$addr = $addressPublic ? map_address_text($row) : '';
if ($map === null && $addr === '') {
    return;
}

$heading = $heading ?? 'Location';
$class   = $class ?? '';
$dirUrl  = map_directions_url($row);
$wazeUrl = map_waze_url($row);
?>
<div class="panel <?= esc($class, 'attr') ?>">
    <h3><?= esc($heading) ?></h3>
    <?php if ($addr !== ''): ?>
        <p class="map-address"><?= lucide('map-pin', 'h-4 w-4 shrink-0', ['aria-hidden' => 'true']) ?><span><?= esc($addr) ?></span></p>
    <?php endif; ?>
    <?php if ($map !== null): ?>
    <div class="map-view"
         data-map-view
         data-lat="<?= esc((string) $map['lat'], 'attr') ?>"
         data-lng="<?= esc((string) $map['lng'], 'attr') ?>"
         data-zoom="<?= esc((string) $map['zoom'], 'attr') ?>"
         <?php if ($map['areaRadius'] !== null): ?>data-area-radius="<?= esc((string) $map['areaRadius'], 'attr') ?>"<?php endif; ?>
         data-label="<?= esc($name . ', ' . $map['label'], 'attr') ?>"
         data-tile-url="<?= esc(config('Directory')->mapTileUrl()) ?>"
         data-tile-attribution="<?= esc(config('Directory')->mapTileAttribution(), 'attr') ?>"
         data-icon-path="<?= base_url('assets/vendor/leaflet/images/') ?>"
         data-leaflet-css="<?= base_url('assets/vendor/leaflet/leaflet.css') ?>"
         data-leaflet-js="<?= base_url('assets/vendor/leaflet/leaflet.js') ?>">
        <div class="map-view-canvas" data-map-view-canvas role="application"
             aria-label="Map showing the location of <?= esc($name, 'attr') ?>"></div>
    </div>
    <?php if ($map['areaRadius'] !== null): ?>
        <p class="map-approx">Approximate area only. The exact address is shown above.</p>
    <?php elseif ($map['approximate']): ?>
        <p class="map-approx">Approximate location. Use the address above for exact directions.</p>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($dirUrl !== ''): ?>
        <a class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($dirUrl) ?>" data-stat="directions" target="_blank" rel="noopener nofollow">Get directions<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
    <?php endif; ?>
    <?php if ($wazeUrl !== ''): ?>
        <a class="mt-2 ml-3 inline-flex items-center gap-1 text-sm font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($wazeUrl) ?>" data-stat="directions" target="_blank" rel="noopener nofollow">Navigate with Waze<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
    <?php endif; ?>
</div>
