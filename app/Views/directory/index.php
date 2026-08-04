<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?php
$title = 'Browse businesses';
if (! empty($filters['category'])) { $title = ucwords(str_replace('-', ' ', $filters['category'])) . ' listings'; }
if (! empty($filters['province'])) { $title .= ' in ' . $filters['province']; }
?>
<?php
helper('slug');
$siteName = config('Directory')->siteName();
// A filtered browse view duplicates a landing page, and a ?q= result set is
// endless thin permutations — canonicalise to the landing page where one exists
// and keep search/pagination out of the index.
$hasQuery  = ($filters['q'] ?? '') !== '';
$page      = (int) ($result['page'] ?? 1);
$indexable = ! $hasQuery && $page === 1;
$canonical = base_url('directory');
if (! $hasQuery && ($filters['category'] ?? '') !== '') {
    $canonical = base_url('directory/' . $filters['category']
        . (($filters['province'] ?? '') !== '' ? '/' . slugify($filters['province']) : ''));
}

// Only worth an ItemList when the page is actually indexable — no point
// emitting structured data for a page we've told search engines to skip.
$schema = null;
if ($indexable && ! empty($result['items'])) {
    $items = [];
    foreach ($result['items'] as $i => $l) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'url'      => base_url('directory/' . $l['slug']),
            'name'     => $l['display_name'],
        ];
    }
    $schema = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            ['@type' => 'ItemList', 'name' => $title, 'numberOfItems' => (int) $result['total'], 'itemListElement' => $items],
        ],
    ];
}
?>
<?= seo_meta([
    'title'       => $title . ' — ' . $siteName,
    'description' => 'Browse and search South African service businesses by category, province and city.',
    'canonical'   => $canonical,
    'robots'      => $indexable,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero py-8 sm:py-10">
    <div class="container">
        <h1 class="text-2xl sm:text-3xl">Find a business</h1>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <input type="text" name="q" value="<?= esc($filters['q'], 'attr') ?>" placeholder="Business, service or keyword">
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
                    <?php $q = array_filter($filters + ['radius' => (string) $km], static fn ($v) => $v !== ''); unset($q['bounds']); ?>
                    <a class="near-chip<?= (int) ($filters['radius'] ?? 0) === $km ? ' is-active' : '' ?>"
                       href="<?= base_url('directory') . '?' . http_build_query($q) ?>"><?= $km ?> km</a>
                <?php endforeach; ?>
                <?php $clear = array_filter($filters, static fn ($v) => $v !== ''); unset($clear['lat'], $clear['lng'], $clear['radius'], $clear['bounds']); ?>
                <a class="near-chip" href="<?= base_url('directory') . ($clear ? '?' . http_build_query($clear) : '') ?>">Clear</a>
            <?php endif; ?>
            <span class="near-bar-note" role="status" data-near-me-note></span>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400"><?= (int) $result['total'] ?> business<?= $result['total'] === 1 ? '' : 'es' ?> found</p>

        <?php // Category chips: crawlable links into the landing pages, which
              // query-string filters alone would never provide. ?>
        <?php if (! $hasQuery && ($filters['category'] ?? '') === ''): ?>
            <div class="mb-6 flex flex-wrap gap-2">
                <?php $shown = 0; $seenGroup = ''; ?>
                <?php foreach ($categories as $c): ?>
                    <?php if ($shown >= 18) { break; } ?>
                    <?php if (($c['group_name'] ?? '') === $seenGroup) { continue; } ?>
                    <?php $seenGroup = $c['group_name'] ?? ''; $shown++; ?>
                    <a class="badge hover:bg-primary-50 hover:text-primary-600 dark:hover:bg-primary-500/15 dark:hover:text-primary-300" href="<?= base_url('directory/' . $c['slug']) ?>"><?= esc($c['name']) ?></a>
                <?php endforeach; ?>
                <a class="badge hover:bg-primary-50 hover:text-primary-600 dark:hover:bg-primary-500/15 dark:hover:text-primary-300" href="<?= base_url('directory/categories') ?>">Browse all categories &rarr;</a>
            </div>
        <?php endif; ?>

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
            <div class="results-map"
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
                     aria-label="Map of businesses matching your search"></div>
                <p class="results-map-status" role="status" data-results-map-status></p>
            </div>
        <?php endif; ?>

        <?php if (empty($result['items'])): ?>
            <div class="empty">
                <p class="mb-4">No businesses match your search.</p>
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
                    $q = $filters;
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
</section>
<?= $this->endSection() ?>
