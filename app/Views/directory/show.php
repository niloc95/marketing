<?= $this->extend('layouts/public') ?>

<?php
helper(['slug', 'directory_hours']); // slugify() for breadcrumbs; hours_* for the trading-hours card
$siteName = config('Directory')->siteName();
$name = $l['display_name'] ?? '';
$prof = $l['category']['name'] ?? ($l['category_name'] ?? '');
$city = trim((string) ($l['city'] ?? ''));
$place = trim(implode(', ', array_filter([$l['suburb'] ?? '', $l['city'] ?? '', $l['province'] ?? ''])));
$logoUrl = listing_image_url($l['logo_path'] ?? null);
$canonical = base_url('directory/' . ($l['slug'] ?? ''));
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));

// The business node, its type mapping and its deliberate omissions (no
// aggregateRating, no owner email) all live in schema_helper.php now.
$business = schema_local_business($l, $canonical);

$catSlug  = $l['category']['slug'] ?? ($l['category_slug'] ?? '');
$province = (string) ($l['province'] ?? '');
$crumbs   = [
    ['name' => 'Browse', 'url' => base_url('directory')],
];
if ($catSlug !== '') {
    $crumbs[] = ['name' => $prof, 'url' => base_url('directory/' . $catSlug)];
    if ($province !== '') {
        $crumbs[] = ['name' => $province, 'url' => base_url('directory/' . $catSlug . '/' . slugify($province))];
    }
}
$crumbs[] = ['name' => $name, 'url' => $canonical];

// ProfilePage, not WebPage: the page exists to describe one business, and
// schema_page() wires it to the business node as mainEntity.
$schema = schema_page(
    [$business, schema_breadcrumb($crumbs, $canonical)],
    $canonical,
    'ProfilePage',
    $name
);
$metaDesc = $prof ? ($name . ' — ' . $prof . ($place ? ' in ' . $place : '') . '.') : $name;
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => trim($name . ($prof ? ' · ' . $prof : '') . ($city !== '' ? ' in ' . $city : '')) . ' — ' . $siteName,
    'description' => $metaDesc,
    'canonical'   => $canonical,
    'image'       => $logoUrl,
    'type'        => 'profile',
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <nav class="mb-4 text-sm text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
            <?php foreach ($crumbs as $i => $crumb): ?>
                <?php if ($i > 0): ?><span class="mx-1">/</span><?php endif; ?>
                <?php if ($i < count($crumbs) - 1): ?>
                    <a class="hover:text-primary-500 dark:hover:text-primary-300 hover:underline" href="<?= esc($crumb['url'], 'attr') ?>"><?= esc($crumb['name']) ?></a>
                <?php else: ?>
                    <span class="text-slate-700 dark:text-slate-300"><?= esc($crumb['name']) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <?php if (! empty($l['photos'])): ?>
            <div class="gallery" data-gallery>
                <?php foreach ($l['photos'] as $i => $p): ?>
                    <button type="button" class="gallery-item" data-gallery-open data-index="<?= $i ?>">
                        <img src="<?= base_url($p['path']) ?>"
                             <?php if (! empty($p['width'])): ?>width="<?= (int) $p['width'] ?>"<?php endif; ?>
                             <?php if (! empty($p['height'])): ?>height="<?= (int) $p['height'] ?>"<?php endif; ?>
                             alt="<?= esc($name) ?> — photo <?= $i + 1 ?>"
                             loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="profile-head">
            <div class="avatar">
                <?php if ($logoUrl !== ''): ?><img src="<?= esc($logoUrl, 'attr') ?>" alt=""><?php else: ?><?= esc($initials) ?><?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
                <?php // Category and province link to their landing pages: this is what
                      // stops those pages being orphans and gives them internal authority. ?>
                <?php if ($prof): ?>
                    <?php if ($catSlug !== ''): ?>
                        <a class="text-sm font-bold text-brand-orange hover:underline" href="<?= base_url('directory/' . $catSlug) ?>"><?= esc($prof) ?></a>
                    <?php else: ?>
                        <span class="text-sm font-bold text-brand-orange"><?= esc($prof) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <h1 class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-white sm:text-3xl"><?= esc($name) ?></h1>
                <?php if ($place): ?>
                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        📍
                        <?php if ($catSlug !== '' && $province !== ''): ?>
                            <?= esc(trim(implode(', ', array_filter([$l['suburb'] ?? '', $l['city'] ?? ''])))) ?><?= ($l['suburb'] ?? '') || ($l['city'] ?? '') ? ', ' : '' ?><a class="hover:text-primary-500 dark:hover:text-primary-300 hover:underline" href="<?= base_url('directory/' . $catSlug . '/' . slugify($province)) ?>"><?= esc($province) ?></a>
                        <?php else: ?>
                            <?= esc($place) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (listing_is_verified_business($l)): ?>
                    <?php // The title says what was actually checked. "Verified" with no
                          // referent is the kind of badge that erodes trust rather than
                          // building it, and the FAQ this links to spells it out. ?>
                    <a class="badge badge-verified mt-2" href="<?= base_url('verified') ?>"
                       title="We checked this business's registration document and the owner's ID">✓ Verified Business</a>
                <?php endif; ?>
                <?php if (! empty($l['is_featured'])): ?><span class="badge badge-featured mt-2">★ Featured</span><?php endif; ?>
            </div>
            <?php
            $shareText = $name . ($prof ? ' — ' . $prof : '');
            $shareLinks = [
                'whatsapp' => 'https://wa.me/?text=' . rawurlencode($shareText . ' ' . $canonical),
                'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($canonical),
                'x'        => 'https://twitter.com/intent/tweet?text=' . rawurlencode($shareText) . '&url=' . rawurlencode($canonical),
            ];
            ?>
            <div class="share-row" data-share data-share-title="<?= esc($shareText, 'attr') ?>" data-share-url="<?= esc($canonical, 'attr') ?>">
                <button type="button" class="share-icon" data-share-native hidden aria-label="Share" title="Share">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="M7 8l5-5 5 5"/><path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>
                </button>
                <a class="share-icon" data-share-fallback href="<?= esc($shareLinks['whatsapp'], 'attr') ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp" title="Share on WhatsApp">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.39 1.26 4.81L2 22l5.42-1.36a9.87 9.87 0 0 0 4.62 1.15h.01c5.46 0 9.91-4.45 9.91-9.91C21.96 6.45 17.5 2 12.04 2Zm5.83 14.02c-.25.7-1.24 1.29-1.99 1.44-.53.11-1.22.19-3.55-.76-2.98-1.23-4.9-4.24-5.05-4.44-.15-.2-1.2-1.6-1.2-3.05 0-1.45.76-2.16 1.03-2.46.27-.3.59-.37.79-.37.2 0 .4 0 .57.01.18.01.43-.07.67.51.25.6.85 2.06.92 2.21.07.15.12.33.02.53-.1.2-.15.33-.3.5-.15.18-.31.4-.44.53-.15.15-.3.31-.13.61.17.3.76 1.25 1.63 2.02 1.12.99 2.06 1.3 2.36 1.45.3.15.48.13.66-.08.18-.2.76-.89.96-1.19.2-.3.4-.25.67-.15.27.1 1.73.82 2.03.97.3.15.5.22.57.35.08.13.08.73-.17 1.43Z"/></svg>
                </a>
                <a class="share-icon" data-share-fallback href="<?= esc($shareLinks['facebook'], 'attr') ?>" target="_blank" rel="noopener" aria-label="Share on Facebook" title="Share on Facebook">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 22v-8.5H16l.5-3.5h-3V7.8c0-1 .3-1.8 1.8-1.8H16.6V2.8C16.2 2.7 15.2 2.6 14 2.6c-2.5 0-4.2 1.5-4.2 4.3V10H7v3.5h2.8V22h3.7Z"/></svg>
                </a>
                <a class="share-icon" data-share-fallback href="<?= esc($shareLinks['x'], 'attr') ?>" target="_blank" rel="noopener" aria-label="Share on X" title="Share on X">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.9 2H22l-7.2 8.2L23 22h-6.6l-5.2-6.8L5.2 22H2l7.7-8.8L1.5 2h6.8l4.7 6.2L18.9 2Zm-1.2 18h1.8L7.4 4H5.5l12.2 16Z"/></svg>
                </a>
                <button type="button" class="share-icon" data-share-copy aria-label="Copy link" title="Copy link">
                    <svg class="icon-link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 17H7a5 5 0 0 1 0-10h2"/><path d="M15 7h2a5 5 0 0 1 0 10h-2"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                    <svg class="icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
            </div>
        </div>

        <div class="lightbox" data-lightbox hidden>
            <button type="button" class="lightbox-close" data-lightbox-close aria-label="Close">&times;</button>
            <button type="button" class="lightbox-prev" data-lightbox-prev aria-label="Previous photo">&lsaquo;</button>
            <img src="" alt="" data-lightbox-img>
            <button type="button" class="lightbox-next" data-lightbox-next aria-label="Next photo">&rsaquo;</button>
        </div>

        <div class="profile-grid">
            <div>
                <?php
                $pills = array_filter([
                    'accepts_card_payments' => 'Accepts card payments',
                    'offers_delivery'       => 'Delivery / mobile service',
                    'offers_online_booking' => 'Online booking',
                ], fn ($k) => ! empty($l[$k]), ARRAY_FILTER_USE_KEY);
                ?>
                <?php if (! empty($l['description']) || $pills): ?>
                    <div class="panel mb-5">
                        <h3>About</h3>
                        <?php if (! empty($l['description'])): ?><p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300"><?= esc($l['description']) ?></p><?php endif; ?>
                        <?php if ($pills): ?>
                            <div class="pillrow">
                                <?php foreach ($pills as $label): ?><span class="pill pill-capability"><?= esc($label) ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['credentials'])): ?>
                    <div class="panel mb-5">
                        <h3>Credentials</h3>
                        <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300"><?= esc($l['credentials']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['tags'])): ?>
                    <div class="panel mb-5">
                        <h3>Areas of focus</h3>
                        <div class="taglist">
                            <?php foreach ($l['tags'] as $t): ?><span class="tag"><?= esc($t) ?></span><?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['locations'])): ?>
                    <div class="panel">
                        <h3>Other locations</h3>
                        <?php foreach ($l['locations'] as $loc): ?>
                            <div class="kv">
                                <span class="k"><?= esc($loc['name'] ?: 'Location') ?></span>
                                <span><?= esc(trim(implode(', ', array_filter([$loc['address_line'] ?? '', $loc['suburb'] ?? '', $loc['city'] ?? '', $loc['province'] ?? ''])))) ?><?php if (! empty($loc['phone'])): ?> · <?= esc($loc['phone']) ?><?php endif; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <div class="panel">
                    <h3>Contact</h3>
                    <?php // title ("Dr, Mrs, Prof…") is the contact person's honorific, not the
                          // business name's — pairing it here (not the H1) keeps them together. ?>
                    <?php $contactLine = trim(($l['title'] ?? '') . ' ' . ($l['contact_person'] ?? '')); ?>
                    <?php if ($contactLine !== ''): ?><div class="kv"><span class="k">Contact</span><span><?= esc($contactLine) ?></span></div><?php endif; ?>
                    <?php // Phone/email are reversed in data-reveal-value (not plaintext tel:/mailto:)
                          // so naive HTML scrapers can't lift them; the LocalBusiness JSON-LD below
                          // still carries the real values, so crawlers are unaffected. ?>
                    <?php if (! empty($l['phone'])): ?>
                        <div class="kv"><span class="k">Phone</span><span><button type="button" class="reveal-btn" data-reveal data-reveal-type="tel" data-reveal-value="<?= esc(strrev($l['phone']), 'attr') ?>">Show phone</button></span></div>
                    <?php endif; ?>
                    <?php if (! empty($l['email'])): ?>
                        <div class="kv"><span class="k">Email</span><span><button type="button" class="reveal-btn" data-reveal data-reveal-type="mailto" data-reveal-value="<?= esc(strrev($l['email']), 'attr') ?>">Show email</button></span></div>
                    <?php endif; ?>
                    <?php $websiteUrl = safe_external_url($l['website'] ?? ''); ?>
                    <?php if ($websiteUrl !== ''): ?><div class="kv"><span class="k">Website</span><span><a href="<?= esc($websiteUrl, 'attr') ?>" target="_blank" rel="noopener nofollow">Visit</a></span></div><?php endif; ?>
                    <?php
                    $addr = trim(implode(', ', array_filter([$l['address_line'] ?? '', $l['suburb'] ?? '', $l['city'] ?? '', $l['province'] ?? '', $l['postal_code'] ?? ''])));
                    // Two different intents, so two different Google Maps schemes.
                    // The address text means "show me where this is" — /maps/search/
                    // drops a pin. The button below means "take me there" — that one
                    // uses /maps/dir/ instead (see map_directions_url), which opens
                    // routing directly rather than making the visitor tap Directions
                    // once they arrive. Neither needs an API key or billing account.
                    //
                    // Both take their destination from map_destination(), which only
                    // trusts our coordinates when the pin was actually pinpointed —
                    // otherwise Google gets the address text and does far better with
                    // it than our street-centroid guess would.
                    $mapsQuery = map_destination($l);
                    $mapsUrl   = $mapsQuery !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapsQuery) : '';
                    ?>
                    <?php if ($addr !== ''): ?>
                        <div class="kv"><span class="k">Address</span><span>
                            <?php if ($mapsUrl !== ''): ?><a href="<?= esc($mapsUrl, 'attr') ?>" target="_blank" rel="noopener nofollow"><?= esc($addr) ?></a><?php else: ?><?= esc($addr) ?><?php endif; ?>
                        </span></div>
                    <?php endif; ?>
                    <?php
                    $socials = array_filter([
                        'Facebook'  => safe_external_url($l['social_facebook'] ?? ''),
                        'Instagram' => safe_external_url($l['social_instagram'] ?? ''),
                        'LinkedIn'  => safe_external_url($l['social_linkedin'] ?? ''),
                    ]);
                    ?>
                    <?php if ($socials): ?>
                        <div class="kv"><span class="k">Social</span><span>
                            <?php foreach ($socials as $label => $url): ?><a href="<?= esc($url, 'attr') ?>" target="_blank" rel="noopener nofollow"><?= esc($label) ?></a>&nbsp; <?php endforeach; ?>
                        </span></div>
                    <?php endif; ?>
                </div>

                <?php // An interactive Leaflet map over OpenStreetMap tiles, loaded lazily —
                      // directory.js injects the library and the tiles only when this scrolls
                      // into view, so the majority of visitors who never reach it pay nothing
                      // for it. Without JavaScript the panel is a heading and a directions
                      // link, which is the part that actually gets someone to the door.
                      //
                      // Needs real coordinates, unlike the old address-text embed. A listing
                      // that never geocoded shows no map; geocoding_status is how those get
                      // found, and the owner's pin picker is how they get fixed. ?>
                <?php $map = map_point($l); ?>
                <?php if ($map !== null): ?>
                    <div class="panel mt-5">
                        <h3>Location</h3>
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
                        <?php $directionsUrl = map_directions_url($l); ?>
                        <?php if ($directionsUrl !== ''): ?>
                            <a class="mt-2 inline-block text-sm font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($directionsUrl, 'attr') ?>" target="_blank" rel="noopener nofollow">Get directions &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['trading_hours'])): ?>
                    <?php $todayKey = hours_today_key(); ?>
                    <div class="panel mt-5">
                        <h3>Trading hours</h3>
                        <?php foreach (hours_days() as $key => $label): $row = $l['trading_hours'][$key] ?? null; ?>
                            <div class="kv hours-day-row<?= $key === $todayKey ? ' hours-today' : '' ?>">
                                <span class="k"><?= esc($label) ?><?php if ($key === $todayKey): ?> <span class="hours-today-badge">Today</span><?php endif; ?></span>
                                <span>
                                    <?php if (empty($row) || ! empty($row['closed'])): ?>
                                        Closed
                                    <?php else: ?>
                                        <?= esc($row['open']) ?>&ndash;<?= esc($row['close']) ?>
                                    <?php endif; ?>
                                    <?php if (! empty($row['note'])): ?><br><span class="hours-note-text"><?= esc($row['note']) ?></span><?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php // "More like this" — keeps visitors moving and pushes crawl depth
              // into sibling listings and their landing page. ?>
        <?php if (! empty($related)): ?>
            <div class="mt-10">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        <?php // Category names are singular ("Hair Salon"); pluralise for the heading. ?>
                        More <?= esc(strtolower(rtrim($prof, 's') . 's')) ?><?= $province !== '' ? ' in ' . esc($province) : '' ?>
                    </h2>
                    <?php if ($catSlug !== ''): ?>
                        <a class="text-sm font-medium text-primary-500 dark:text-primary-300 hover:underline"
                           href="<?= base_url('directory/' . $catSlug . ($province !== '' ? '/' . slugify($province) : '')) ?>">See all &rarr;</a>
                    <?php endif; ?>
                </div>
                <div class="card-grid">
                    <?php foreach ($related as $r): ?>
                        <?= view('directory/_card', ['l' => $r]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
