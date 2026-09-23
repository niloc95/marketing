<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?php
helper('slug');
$siteName = config('Directory')->siteName();

// $category and $province come from the controller already resolved against
// the real category table and the province list; null means the visitor asked
// for something that does not exist. Titles and canonicals are built from
// those resolved values, never from the raw query string.
$askedCategory = ($filters['category'] ?? '') !== '';
$askedProvince = ($filters['province'] ?? '') !== '';
$unknownFilter = ($askedCategory && $category === null) || ($askedProvince && $province === null);

$title = 'Browse everything';
if ($category !== null) { $title = $category['name'] . ' profiles'; }
if ($province !== null) { $title .= ' in ' . $province; }

// A filtered browse view duplicates a landing page, and a ?q= result set is
// endless thin permutations — canonicalise to the landing page where one exists
// and keep search/pagination out of the index.
$hasQuery  = ($filters['q'] ?? '') !== '';
$page      = (int) ($result['page'] ?? 1);
// An unrecognised category or province is noindexed as well: without that, any
// query string is a 200 that asks to be indexed under a title of its author's
// choosing.
// A faceted view is a filtered view: thin, endlessly combinable, and a
// duplicate of the landing page it canonicalises to. Same rule as ?q=.
$hasFacets = ($filters['facets'] ?? []) !== [];
$indexable = ! $hasQuery && ! $hasFacets && $page === 1 && ! $unknownFilter;

// Every link on this page rebuilds the query string from scratch, and facets
// travel as ?f[key][]=value while the filter array calls them 'facets'. Without
// this rename the pager and the radius chips would drop every facet — the kind
// of bug that only shows up on page 2.
$urlFilters = $filters;
unset($urlFilters['facets']);
if ($hasFacets) {
    $urlFilters['f'] = $filters['facets'];
}
$canonical = base_url('directory');
if (! $hasQuery && $category !== null) {
    $canonical = base_url('directory/' . $category['slug']
        . ($province !== null ? '/' . slugify($province) : ''));
}

// Only worth an ItemList when the page is actually indexable — no point
// emitting structured data for a page we've told search engines to skip.
$schema = null;
if ($indexable && ! empty($result['items'])) {
    $schema = schema_page(
        [schema_item_list($title, schema_listing_elements($result['items']), (int) $result['total'], $canonical)],
        $canonical,
        'CollectionPage',
        $title
    );
}
?>
<?= seo_meta([
    'title'       => $title . ' — ' . $siteName,
    'description' => 'Browse and search South African services, professionals and home industry by category, province and city.',
    'canonical'   => $canonical,
    'robots'      => $indexable,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
// A browse filtered to exactly one category is the same set of businesses as
// that category's landing page — it canonicalises to it a few lines above — so it
// arrives in the same colour world rather than the shared navy. Anything else
// stays navy on purpose: with no category, an unrecognised one, or a province on
// its own, the results are a mix of verticals and the band would have to pick a
// hue for all of them.
//
// $category is the row the controller already resolved against the category
// table, so this cannot be driven from the raw query string — the same reason
// the title and canonical above are built from it.
$vertical      = $category !== null ? category_group_style($category['group_name'] ?? null) : null;
$verticalPhoto = $category !== null ? category_photo($category) : null;
?>
<section class="hero<?= $vertical !== null ? ' hero-vertical vertical-scope ' . $vertical['tint'] : '' ?>">
    <?php if ($verticalPhoto !== null): ?>
        <?php // Decorative, and eager — see landing.php, which draws the same
              // photograph for the same category. ?>
        <img class="hero-vertical-img"
             src="<?= esc(base_url($verticalPhoto['src']), 'attr') ?>"
             srcset="<?= esc(base_url($verticalPhoto['src_sm']), 'attr') ?> 400w, <?= esc(base_url($verticalPhoto['src']), 'attr') ?> 800w"
             sizes="100vw" alt="" decoding="async" fetchpriority="high">
    <?php endif; ?>
    <div class="container">
        <?php // The heading follows the band. A coloured hero still headed "Browse"
              // reads as a styling accident, and this page already knew the better
              // wording: $title is what the <title> and the ItemList both use, so
              // the three now agree instead of the h1 saying "Browse" while the tab
              // says "Dentist profiles in Gauteng". Unfiltered, it is still Browse. ?>
        <?php if ($vertical !== null): ?>
            <h1 class="flex items-center gap-2.5 text-2xl sm:text-3xl">
                <?= lucide($vertical['icon'], 'h-7 w-7 shrink-0 opacity-80 sm:h-8 sm:w-8') ?>
                <span><?= esc($title) ?></span>
            </h1>
        <?php else: ?>
            <h1 class="text-2xl sm:text-3xl">Browse</h1>
        <?php endif; ?>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <?= view('directory/_search_input', [
                'listId'      => 'search-suggest-hero',
                'value'       => (string) $filters['q'],
                'placeholder' => 'Name, service or keyword',
                'ariaLabel'   => '',
                'type'        => 'text',
            ]) ?>
            <select name="category">
                <option value="">All categories</option>
                <?php foreach ($groups as $groupName => $cats): ?>
                    <optgroup label="<?= esc($groupName, 'attr') ?>">
                    <?php foreach ($cats as $p): ?>
                        <option value="<?= esc($p['slug'], 'attr') ?>" <?= ($filters['category'] === $p['slug']) ? 'selected' : '' ?>><?= esc($p['name']) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
            <select name="province">
                <option value="">All provinces</option>
                <?php foreach ($provinces as $prov): ?>
                    <option value="<?= esc($prov, 'attr') ?>" <?= ($filters['province'] === $prov) ? 'selected' : '' ?>><?= esc($prov) ?></option>
                <?php endforeach; ?>
            </select>
            <?php // The map writes lat/lng/radius into the URL, and this form
                  // rebuilds the query string from scratch on submit — without
                  // these, refining a "near me" search by category would
                  // silently drop the position and quietly widen the results. ?>
            <?php foreach (['lat', 'lng', 'radius'] as $carry): ?>
                <?php if (($filters[$carry] ?? '') !== ''): ?>
                    <input type="hidden" name="<?= $carry ?>" value="<?= esc($filters[$carry], 'attr') ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>

        <?php // Distance search. "Near me" is JS-only (it needs the browser's
              // geolocation), so it is hidden until the script confirms the API
              // exists — an offer the browser cannot honour is worse than no
              // offer. The radius links work without JavaScript, but only once
              // a position is set, which is why they appear alongside it. ?>
        <div class="near-bar">
            <button type="button" class="btn btn-ghost btn-xs" data-near-me hidden>Use my location</button>
            <?php if (($filters['lat'] ?? '') !== '' && ($filters['lng'] ?? '') !== ''): ?>
                <span class="near-bar-label">Within</span>
                <?php foreach ($radii as $km): ?>
                    <?php $q = array_filter($urlFilters + ['radius' => (string) $km], static fn ($v) => $v !== ''); unset($q['bounds']); ?>
                    <a class="near-chip<?= (int) ($filters['radius'] ?? 0) === $km ? ' is-active' : '' ?>"
                       href="<?= esc(base_url('directory') . '?' . http_build_query($q), 'attr') ?>"><?= $km ?> km</a>
                <?php endforeach; ?>
                <?php $clear = array_filter($urlFilters, static fn ($v) => $v !== ''); unset($clear['lat'], $clear['lng'], $clear['radius'], $clear['bounds']); ?>
                <a class="near-chip" href="<?= esc(base_url('directory') . ($clear ? '?' . http_build_query($clear) : ''), 'attr') ?>">Clear</a>
            <?php endif; ?>
            <span class="near-bar-note" role="status" data-near-me-note></span>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php // Category chips: crawlable links into the landing pages, which
              // query-string filters alone would never provide. ?>
        <?php if (! $hasQuery && ($filters['category'] ?? '') === ''): ?>
            <div class="mb-6 flex flex-wrap gap-2">
                <?php $shown = 0; $seenGroup = ''; ?>
                <?php foreach ($categories as $c): ?>
                    <?php if ($shown >= 18) { break; } ?>
                    <?php if (($c['group_name'] ?? '') === $seenGroup) { continue; } ?>
                    <?php $seenGroup = $c['group_name'] ?? ''; $shown++; ?>
                    <?= view('directory/_chip', ['label' => $c['name'], 'href' => base_url('directory/' . $c['slug']), 'tint' => category_group_tint($c['group_name'] ?? null)], ['saveData' => false]) ?>
                <?php endforeach; ?>
                <?= view('directory/_chip', ['label' => 'Browse all categories', 'href' => base_url('directory/categories'), 'icon' => 'arrow-right'], ['saveData' => false]) ?>
            </div>
        <?php endif; ?>

        <?php // Two columns only where there is a sidebar to put in one — see
              // listing_has_facet_rail(). The chips above and the sidebar are
              // mutually exclusive by construction: chips render only when there
              // is no category, and there is no rail without one. ?>
        <?php $hasRail = listing_has_facet_rail($category); ?>
        <div<?= $hasRail ? ' class="results-layout"' : '' ?>>
            <?php if ($hasRail): ?>
                <?php // Carries every other filter as hidden inputs so ticking a
                      // box cannot lose the province or the near-me position. ?>
                <div class="results-filters">
                    <?= view('directory/_facet_filters', [
                        'category' => $category,
                        'facets'   => $filters['facets'] ?? [],
                        'action'   => base_url('directory'),
                        'carry'    => array_diff_key($urlFilters, ['f' => null, 'page' => null]),
                    ], ['saveData' => false]) ?>
                </div>
            <?php endif; ?>

            <div>
                <p class="mb-4 text-sm text-slate-500 dark:text-slate-400"><?= (int) $result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?></p>

                <?php
                // Where the map opens. Null when nothing on this page is mappable, in
                // which case no map renders at all — an empty map of South Africa
                // answers no question.
                //
                // The opening zoom has to frame the whole search, not just its centre.
                // The map only ever loads pins for the viewport it is showing, so a
                // "within 25km" search opened at street zoom reports "no businesses in
                // this part of the map" while the list underneath lists three. These
                // zooms are chosen so the radius fits comfortably inside the viewport.
                $radiusZoom = [1 => 14, 5 => 12, 10 => 11, 25 => 10, 50 => 9];

                $mapCentre = null;
                if (($filters['lat'] ?? '') !== '' && ($filters['lng'] ?? '') !== '') {
                    $zoom      = $radiusZoom[(int) ($filters['radius'] ?? 0)] ?? 11;
                    $mapCentre = $filters['lat'] . ',' . $filters['lng'] . ',' . $zoom;
                } else {
                    foreach ($result['items'] as $l) {
                        if (! empty($l['latitude']) && ! empty($l['longitude'])) {
                            $mapCentre = $l['latitude'] . ',' . $l['longitude'] . ',11';
                            break;
                        }
                    }
                }
                ?>
                <?php if ($mapCentre !== null): ?>
                    <?php // id + scroll-mt: the header's "Map" link is /directory#map, and
                          // without the margin the map's top edge lands underneath the bar.
                          // scroll-mt-28 (7rem), matching html's md:scroll-pt-28 and the
                          // sidebar's lg:top-28 — .site-header is fixed and GROWS to a
                          // measured 109px once .is-solid is on, which is its state after
                          // any scroll. The old scroll-mt-20 was sized for the resting bar
                          // and left the map tucked under the solid one.
                          // Nothing else links here, so if this block is ever made
                          // conditional on something new, the anchor degrades to the page top. ?>
                    <div class="results-map scroll-mt-28"
                         id="map"
                         data-results-map
                         data-endpoint="<?= base_url('directory/map') ?>"
                         data-centre="<?= esc($mapCentre, 'attr') ?>"
                         data-tile-url="<?= esc(config('Directory')->mapTileUrl(), 'attr') ?>"
                         data-tile-attribution="<?= esc(config('Directory')->mapTileAttribution(), 'attr') ?>"
                         data-icon-path="<?= base_url('assets/vendor/leaflet/images/') ?>"
                         data-leaflet-css="<?= base_url('assets/vendor/leaflet/leaflet.css') ?>"
                         data-leaflet-js="<?= base_url('assets/vendor/leaflet/leaflet.js') ?>"
                         data-cluster-js="<?= base_url('assets/vendor/leaflet/markercluster.js') ?>"
                         data-cluster-css="<?= base_url('assets/vendor/leaflet/markercluster.css') ?>"
                         data-cluster-default-css="<?= base_url('assets/vendor/leaflet/markercluster.default.css') ?>">
                        <div class="results-map-canvas" data-results-map-canvas role="application"
                             aria-label="Map of results matching your search"></div>
                        <p class="results-map-status" role="status" data-results-map-status></p>
                    </div>
                <?php endif; ?>

                <?php if (empty($result['items'])): ?>
                    <div class="empty">
                        <p class="mb-4">Nothing matches your search.</p>
                        <a class="btn btn-ghost" href="<?= base_url('directory') ?>">Clear filters</a>
                    </div>
                <?php else: ?>
                    <div class="card-grid">
                        <?php foreach ($result['items'] as $l): ?>
                            <?= view('directory/_card', ['l' => $l]) ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($result['totalPages'] > 1): ?>
                        <nav class="pager">
                            <?php
                            $q = $urlFilters;
                            for ($i = 1; $i <= $result['totalPages']; $i++):
                                $q['page'] = $i;
                                $href = base_url('directory') . '?' . http_build_query(array_filter($q, fn ($v) => $v !== '' && $v !== null));
                            ?>
                                <?php if ($i === $result['page']): ?>
                                    <span class="current"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="<?= esc($href, 'attr') ?>"><?= $i ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
