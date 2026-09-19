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

        <div class="profile-head">
            <?php // avatar-logo only when there is a logo: it swaps the square crop for
                  // a contained fit that shows a wide lockup whole. Without an image the
                  // box stays square for the initials. ?>
            <div class="avatar<?= $logoUrl !== '' ? ' avatar-logo' : '' ?>">
                <?php if ($logoUrl !== ''): ?><img src="<?= esc($logoUrl, 'attr') ?>" alt=""><?php else: ?><?= esc($initials) ?><?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
                <?php // The seal here, not the pill: this is the page a customer is on when
                      // they decide whether to call, and it is the one public surface with
                      // room for the mark itself. The pill still carries the same claim on
                      // search cards, where 14px is all there is.
                      //
                      // Floated, and first in the column on purpose: a float only clears
                      // content that comes after it, so placed lower it sat on its own row
                      // and pushed the header taller. Up here the name and address wrap
                      // beside it and the header keeps its height.
                      //
                      // Still a link, and the label still says what was actually checked.
                      // "Verified" with no referent is the kind of badge that erodes trust
                      // rather than building it, and the page it links to spells it out.
                      // The seal stays aria-hidden so the link is named once, by the
                      // anchor, rather than twice. ?>
                <?php if (listing_is_verified_business($l)): ?>
                    <a class="float-right ml-3 block" href="<?= base_url('verified') ?>"
                       title="We checked this business's registration document and the owner's ID"
                       aria-label="Verified Business &mdash; we checked this business's registration document and the owner's ID"><?= verified_seal('verified-seal w-16 sm:w-20') ?></a>
                <?php endif; ?>
                <?php // Category and province link to their landing pages: this is what
                      // stops those pages being orphans and gives them internal authority. ?>
                <?php if ($prof): ?>
                    <?php if ($catSlug !== ''): ?>
                        <a class="text-sm font-bold text-brand-orange hover:underline" href="<?= esc(base_url('directory/' . $catSlug), 'attr') ?>"><?= esc($prof) ?></a>
                    <?php else: ?>
                        <span class="text-sm font-bold text-brand-orange"><?= esc($prof) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <h1 class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-white sm:text-3xl"><?= esc($name) ?></h1>
                <?php if ($place): ?>
                    <?php // items-start, and the address wrapped in one <span>: the address
                          // is inline text with a link spliced into it, and as bare flex
                          // children the comma before the province would become its own
                          // item and take a gap on each side. ?>
                    <div class="mt-1 flex items-start gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                        <?= lucide('map-pin', 'mt-0.5 h-4 w-4 shrink-0') ?>
                        <span>
                        <?php if ($catSlug !== '' && $province !== ''): ?>
                            <?= esc(trim(implode(', ', array_filter([$l['suburb'] ?? '', $l['city'] ?? ''])))) ?><?= ($l['suburb'] ?? '') || ($l['city'] ?? '') ? ', ' : '' ?><a class="hover:text-primary-500 dark:hover:text-primary-300 hover:underline" href="<?= esc(base_url('directory/' . $catSlug . '/' . slugify($province)), 'attr') ?>"><?= esc($province) ?></a>
                        <?php else: ?>
                            <?= esc($place) ?>
                        <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if (! empty($l['is_featured'])): ?><span class="badge badge-featured mt-2 gap-1"><?= lucide('star', 'h-3.5 w-3.5 shrink-0') ?>Featured</span><?php endif; ?>
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
                    <?= lucide('share') ?>
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
                    <?= lucide('link', 'icon-link') ?>
                    <?= lucide('check', 'icon-check') ?>
                </button>
            </div>
        </div>

        <?php // Below the header: the category and the name are what tell a visitor
              // they landed on the right business, so they come first and the
              // photos follow — above the fold on a desktop either way. ?>
        <?php if (! empty($l['photos'])): ?>
            <div class="gallery" data-gallery>
                <?php foreach ($l['photos'] as $i => $p): ?>
                    <button type="button" class="gallery-item" data-gallery-open data-index="<?= $i ?>">
                        <img src="<?= esc(base_url($p['path']), 'attr') ?>"
                             <?php if (! empty($p['width'])): ?>width="<?= (int) $p['width'] ?>"<?php endif; ?>
                             <?php if (! empty($p['height'])): ?>height="<?= (int) $p['height'] ?>"<?php endif; ?>
                             alt="<?= esc($name) ?> — photo <?= $i + 1 ?>"
                             loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="lightbox" data-lightbox hidden>
            <button type="button" class="lightbox-close" data-lightbox-close aria-label="Close"><?= lucide('x', 'h-5 w-5') ?></button>
            <button type="button" class="lightbox-prev" data-lightbox-prev aria-label="Previous photo"><?= lucide('chevron-left', 'h-5 w-5') ?></button>
            <img src="" alt="" data-lightbox-img>
            <button type="button" class="lightbox-next" data-lightbox-next aria-label="Next photo"><?= lucide('chevron-right', 'h-5 w-5') ?></button>
        </div>

        <div class="profile-grid">
            <div>
                <?php if (! empty($l['description'])): ?>
                    <div class="panel mb-5">
                        <h3>Business description</h3>
                        <?php /* The only unescaped listing content on the site. listing_rich_text()
                                 runs it through RichText::sanitise() first — do not swap this for a
                                 bare echo, and do not add esc() (it would print the tags). */ ?>
                        <div class="listing-prose text-sm text-slate-700 dark:text-slate-300"><?= listing_rich_text($l['description']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['services'])): ?>
                    <div class="panel mb-5">
                        <h3>Services</h3>
                        <ul class="service-list">
                            <?php foreach ($l['services'] as $svc): ?>
                                <li>
                                    <span class="service-name"><?= esc($svc['name']) ?></span>
                                    <?php if (! empty($svc['price_label'])): ?><span class="service-price"><?= esc($svc['price_label']) ?></span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php $features = listing_feature_labels($l); ?>
                <?php if ($features): ?>
                    <div class="panel mb-5">
                        <h3>Features &amp; amenities</h3>
                        <ul class="feature-list">
                            <?php foreach ($features as $label): ?>
                                <li><?= lucide('check', 'feature-check') ?><span><?= esc($label) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['credentials'])): ?>
                    <div class="panel mb-5">
                        <h3>Credentials</h3>
                        <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300"><?= esc($l['credentials']) ?></p>
                    </div>
                <?php endif; ?>

                <?php // The complex this business is in, and the way through to
                      // everything else in it. getProfile() loads the row and the
                      // count; a listing with no venue renders nothing here. ?>
                <?php if (! empty($l['venue'])): ?>
                    <?php $others = (int) ($l['venue']['listing_count'] ?? 0) - 1; ?>
                    <div class="panel mb-5">
                        <h3>In this complex</h3>
                        <p class="text-sm text-slate-700 dark:text-slate-300">
                            <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(base_url('directory/at/' . $l['venue']['slug']), 'attr') ?>"><?= esc($l['venue']['name']) ?></a>
                            <?php if ($others > 0): ?>
                                &middot; <?= $others ?> other <?= $others === 1 ? 'business' : 'businesses' ?> here
                            <?php endif; ?>
                        </p>
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

                <?php // Empty unless the listing carries a live badge — the gate is
                      // in getProfile(), not here. ?>
                <?php if (! empty($l['team'])): ?>
                    <?= view('directory/_team_panel', ['members' => $l['team']]) ?>
                <?php endif; ?>

                <?php // Branches, rendered by the same three panels as the address
                      // below — see _location_panel.php. ?>
                <?php if (! empty($l['locations'])): ?>
                    <?= view('directory/_location_panel', ['locations' => $l['locations']]) ?>
                <?php endif; ?>
            </div>

            <div>
                <?php // The listing's own address, rendered by the same three panels
                      // each branch above gets. One definition per panel, used twice. ?>
                <?= view('directory/_contact_panel', [
                    'row'     => $l,
                    'heading' => 'Contact',
                    'showWeb' => true,
                ]) ?>

                <?= view('directory/_map_panel', [
                    'row'     => $l,
                    'name'    => $name,
                    'heading' => 'Location',
                    'class'   => 'mt-5',
                ]) ?>

                <?= view('directory/_hours_panel', [
                    'hours'   => $l['trading_hours'] ?? null,
                    'heading' => 'Trading hours',
                    'class'   => 'mt-5',
                ]) ?>
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
                           href="<?= esc(base_url('directory/' . $catSlug . ($province !== '' ? '/' . slugify($province) : '')), 'attr') ?>">See all <?= lucide('arrow-right', 'inline-block h-4 w-4') ?></a>
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
