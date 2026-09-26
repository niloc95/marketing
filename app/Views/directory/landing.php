<?= $this->extend('layouts/public') ?>

<?php
helper('slug');
$siteName = config('Directory')->siteName();
$catName  = (string) $category['name'];
$catSlug  = (string) $category['slug'];
$plural   = rtrim($catName, 's') . 's';           // "Hair Salon" -> "Hair Salons"
$where    = $province !== null ? ' in ' . $province : ' in South Africa';
$heading  = $plural . $where;
$canonical = base_url('directory/' . $catSlug . ($province !== null ? '/' . slugify($province) : ''));
$total    = (int) $result['total'];
$sort     = (string) ($sort ?? '');            // '' or 'new' — see Directory::sortParam()

// This category's own identity: its group's colour and icon, its vocabulary, and
// the photograph behind the hero. $category is the full row, so group_name is
// already here — no extra query for any of it.
//
// category_photo() needs nothing new: it already prefers a photo named for this
// exact slug and falls back to the group's, and CategoryPhotoTest already holds
// every entry to a file on disk. A category whose group has no photo returns null
// and the hero is the plain gradient band, which is what every one of them was
// before this existed.
$group  = $category['group_name'] ?? null;
$style  = category_group_style($group);
$v      = vertical_profile($group, $catSlug);
$photo  = category_photo($category);

// No FAQ block here any more, visible or structured. It used to be templated
// from the category name, which meant the same two questions repeated verbatim
// across all 147 categories × 9 provinces — the thin-content pattern itself.
// FAQ rich results have also been Google-deprecated since August 2023, so
// there was nothing being earned in exchange. The search box, province chips
// and related-category links below cover the same ground more usefully.

// ItemList tells search engines this is a curated set rather than prose. Its
// members carry full LocalBusiness detail — the search query already selects
// every field used, so this costs no extra queries.
$crumbs = [
    ['name' => 'Browse', 'url' => base_url('directory')],
    ['name' => $catName, 'url' => base_url('directory/' . $catSlug)],
];
if ($province !== null) {
    $crumbs[] = ['name' => $province, 'url' => $canonical];
}

$schema = schema_page(
    [
        schema_breadcrumb($crumbs, $canonical),
        schema_item_list($heading, schema_listing_elements($result['items']), $total, $canonical),
    ],
    $canonical,
    'CollectionPage',
    $heading
);
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => $heading . ' — ' . $siteName,
    'description' => 'Find ' . strtolower($plural) . $where . '. Browse verified contact details, locations and services, free.',
    'canonical'   => $canonical,
    'robots'      => $indexable,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php // hero-vertical swaps the shared navy gradient for this group's hue, and
      // vertical-scope carries the tint vars down to the panel headings further
      // down the page. Still .hero, and still a direct first child of .site-main:
      // the padding, the type scale and the header's transparent-over-hero
      // treatment all depend on both of those, so this is a modifier on the band
      // rather than a replacement for it and must not be wrapped in anything. ?>
<section class="hero hero-vertical vertical-scope <?= $style['tint'] ?>">
    <?php if ($photo !== null): ?>
        <?php // Decorative: the heading says what this page is, and a photograph of
              // a generic clinic tells a screen reader nothing it needs. Eager, no
              // lazy attribute — it is the only image above the fold, and lazy on
              // an in-viewport hero image costs a round trip for nothing. ?>
        <img class="hero-vertical-img"
             src="<?= esc(base_url($photo['src']), 'attr') ?>"
             srcset="<?= esc(base_url($photo['src_sm']), 'attr') ?> 400w, <?= esc(base_url($photo['src']), 'attr') ?> 800w"
             sizes="100vw" alt="" decoding="async" fetchpriority="high">
    <?php endif; ?>
    <div class="container">
        <nav class="mb-2 text-sm text-white/70">
            <a class="hover:text-white" href="<?= base_url('directory') ?>">Browse</a>
            <span class="mx-1">/</span>
            <?php if ($province !== null): ?>
                <a class="hover:text-white" href="<?= base_url('directory/' . $catSlug) ?>"><?= esc($catName) ?></a>
                <span class="mx-1">/</span><span class="text-white"><?= esc($province) ?></span>
            <?php else: ?>
                <span class="text-white"><?= esc($catName) ?></span>
            <?php endif; ?>
        </nav>
        <?php // The same icon the homepage tile and the /categories heading use for
              // this group, so the three surfaces agree on what a category looks
              // like. shrink-0 because the heading wraps on a phone. ?>
        <h1 class="flex items-center gap-2.5 text-2xl sm:text-3xl">
            <?= lucide($style['icon'], 'h-7 w-7 shrink-0 opacity-80 sm:h-8 sm:w-8') ?>
            <span><?= esc($heading) ?></span>
        </h1>
        <p class="mt-2 text-sm text-white/80">
            <?= $total ?> <?= $total === 1 ? 'profile' : 'profiles' ?><?= $province !== null ? ' in ' . esc($province) : '' ?>.
        </p>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <?= view('directory/_search_input', [
                'listId'      => 'search-suggest-hero',
                'value'       => '',
                'placeholder' => 'Search within ' . strtolower($plural),
                'ariaLabel'   => '',
                'type'        => 'text',
            ]) ?>
            <input type="hidden" name="category" value="<?= esc($catSlug, 'attr') ?>">
            <?php if ($province !== null): ?><input type="hidden" name="province" value="<?= esc($province, 'attr') ?>"><?php endif; ?>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container vertical-scope <?= $style['tint'] ?>">
        <?php // Two columns only where there is a sidebar to put in one. Most
              // categories have no facets at all and _facet_filters returns
              // early for them — wrapping regardless would leave an empty 20rem
              // gutter on the great majority of landing pages. Same test the
              // partial makes, so the two cannot disagree. ?>
        <?php $hasRail = listing_has_facet_rail($category); ?>
        <div<?= $hasRail ? ' class="results-layout"' : '' ?>>
            <?php if ($hasRail): ?>
                <?php // Only the sort to carry — the category and province are in
                      // the path, not the query string. ?>
                <div class="results-filters">
                    <?= view('directory/_facet_filters', [
                        'category' => $category,
                        'facets'   => $facets,
                        'action'   => $canonical,
                        'carry'    => ['sort' => $sort],
                    ], ['saveData' => false]) ?>
                </div>
            <?php endif; ?>

            <div>
                <?php if ($total > 1): ?>
                    <div class="results-head justify-end">
                        <?= view('directory/_sort_toggle', [
                            'base'  => $canonical,
                            'query' => $facets === [] ? [] : ['f' => $facets],
                            'sort'  => $sort,
                        ], ['saveData' => false]) ?>
                    </div>
                <?php endif; ?>
                <?php if ($total === 0): ?>
                    <div class="empty">
                        <?php // The vertical's noun, not the category name pluralised: "No
                              // practices here yet" reads as a category with room in it,
                              // where "No general practitioners here yet" reads as a
                              // search that failed. ?>
                        <p class="mb-4">No <?= esc($v['nounPlural']) ?> here yet<?= esc($where) ?>. Be the first!</p>
                        <a class="btn btn-accent" href="<?= base_url('add-listing') ?>">List your business — free</a>
                    </div>
                <?php else: ?>
                    <div class="card-grid">
                        <?php foreach ($result['items'] as $l): ?>
                            <?= view('directory/_card', ['l' => $l]) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($result['totalPages'] > 1): ?>
                    <nav class="pager">
                        <?php for ($i = 1; $i <= $result['totalPages']; $i++): ?>
                            <?php if ($i === $result['page']): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <?php // http_build_query nests ?f[curriculum][]=ieb correctly;
                                      // without it page 2 of a narrowed list is the whole
                                      // category again. ?>
                                <a href="<?= esc($canonical . '?' . http_build_query(array_filter(['f' => $facets, 'sort' => $sort, 'page' => $i], static fn ($v): bool => $v !== [] && $v !== '')), 'attr') ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </div>

        <?php // Internal links: without these the landing pages are orphans. ?>
        <?php if ($provinceCounts !== []): ?>
            <div class="panel mt-8">
                <h3><?= esc($plural) ?> by province</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($provinceCounts as $prov => $count): ?>
                        <?php if ($province !== null && $prov === $province) { continue; } ?>
                        <?= view('directory/_chip', [
                            'label' => (string) $prov,
                            'href'  => base_url('directory/' . $catSlug . '/' . slugify((string) $prov)),
                            'count' => (int) $count,
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                    <?php if ($province !== null): ?>
                        <?= view('directory/_chip', ['label' => 'All provinces', 'href' => base_url('directory/' . $catSlug)], ['saveData' => false]) ?>
                        <?php // Up into the location tier, not just sideways within the category. ?>
                        <?= view('directory/_chip', [
                            'label' => 'Every business in ' . $province,
                            'href'  => base_url('directory/province/' . slugify($province)),
                        ], ['saveData' => false]) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($siblings !== []): ?>
            <div class="panel mt-4">
                <h3>Related categories</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($siblings as $s): ?>
                        <?= view('directory/_chip', ['label' => $s['name'], 'href' => base_url('directory/' . $s['slug']), 'tint' => category_group_tint($s['group_name'] ?? null)], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-8 text-center">
            <a class="btn btn-accent" href="<?= base_url('add-listing') ?>">List your business — free</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
