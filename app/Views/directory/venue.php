<?= $this->extend('layouts/public') ?>

<?php
/**
 * A venue page — every business in one complex, mall or building.
 *
 * Built from landing.php and follows its rules: always renders, noindex below
 * landingMinListings, ItemList + BreadcrumbList so the set reads as curated
 * rather than as prose.
 *
 * The one deliberate difference is the map. Every shop in a building geocodes
 * to the same point (ListingGeocoder leaves address_line_2 out of the lookup),
 * so a map of the listings would be one stack of identical pins. This shows the
 * venue's own single coordinate instead.
 *
 * @var array  $venue      the venue row
 * @var array  $result     browse() result for this venue (and ?category=)
 * @var bool   $indexable
 * @var string $category   the category slug being filtered on, or ''
 * @var array  $categories category counts within this venue, biggest first
 * @var int    $total      published listings in the venue, ignoring ?category=
 * @var string $q          search term within the venue, or ''
 */
helper(['slug', 'map', 'directory_ui']);
$siteName  = config('Directory')->siteName();
$name      = (string) $venue['name'];
$canonical = base_url('directory/at/' . $venue['slug']);
$shown     = (int) $result['total'];
$place     = trim(implode(', ', array_filter([
    (string) ($venue['suburb'] ?? ''),
    (string) ($venue['city'] ?? ''),
    (string) ($venue['province'] ?? ''),
])));
$addressLine = trim(implode(', ', array_filter([(string) ($venue['address_line'] ?? ''), $place])));

// The chip's own label, so the filtered view says what it is filtered to.
$activeCategory = '';
foreach ($categories as $c) {
    if ($c['slug'] === $category) {
        $activeCategory = $c['name'];
    }
}
$heading = $activeCategory !== '' ? $activeCategory . ' at ' . $name : $name;

$schema = schema_page(
    [
        schema_breadcrumb([
            ['name' => 'Browse', 'url' => base_url('directory')],
            ['name' => $name, 'url' => $canonical],
        ], $canonical),
        schema_item_list($heading, schema_listing_elements($result['items']), $shown, $canonical),
    ],
    $canonical,
    'CollectionPage',
    $heading
);
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => $name . ($place !== '' ? ' — ' . $place : '') . ' — ' . $siteName,
    'description' => 'Every business at ' . $name . ($place !== '' ? ', ' . $place : '') . '. Shops, services and contact details, free to browse.',
    'canonical'   => $canonical,
    'robots'      => $indexable,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <nav class="mb-2 text-sm text-white/70">
            <a class="hover:text-white" href="<?= base_url('directory') ?>">Browse</a>
            <span class="mx-1">/</span>
            <?php if ($activeCategory !== ''): ?>
                <a class="hover:text-white" href="<?= esc($canonical, 'attr') ?>"><?= esc($name) ?></a>
                <span class="mx-1">/</span><span class="text-white"><?= esc($activeCategory) ?></span>
            <?php else: ?>
                <span class="text-white"><?= esc($name) ?></span>
            <?php endif; ?>
        </nav>
        <h1 class="text-2xl sm:text-3xl"><?= esc($heading) ?></h1>
        <p class="mt-2 text-sm text-white/80">
            <?= $total ?> <?= $total === 1 ? 'business' : 'businesses' ?> here<?= $addressLine !== '' ? ' &middot; ' . esc($addressLine) : '' ?>.
        </p>
        <?php // Posts back to this page, not to /directory: the box says "search
              // within {venue}" and it has to mean it. venue() passes q straight
              // to browse(), so the venue filter is never lost. ?>
        <form class="searchbar" method="get" action="<?= esc($canonical, 'attr') ?>">
            <?= view('directory/_search_input', [
                'listId'      => 'search-suggest-hero',
                'value'       => $q,
                'placeholder' => 'Search within ' . $name,
                'ariaLabel'   => '',
                'type'        => 'text',
            ]) ?>
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= esc($category, 'attr') ?>"><?php endif; ?>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if (! empty($venue['description'])): ?>
            <p class="mb-6 max-w-2xl text-sm text-slate-600 dark:text-slate-300"><?= esc($venue['description']) ?></p>
        <?php endif; ?>

        <?php if ($categories !== []): ?>
            <div class="panel mb-6">
                <h3>What's here</h3>
                <div class="flex flex-wrap gap-2">
                    <?php $carry = $q !== '' ? '&q=' . rawurlencode($q) : ''; ?>
                    <?php if ($category !== ''): ?>
                        <?= view('directory/_chip', ['label' => 'Everything', 'href' => $canonical . ($q !== '' ? '?q=' . rawurlencode($q) : '')], ['saveData' => false]) ?>
                    <?php endif; ?>
                    <?php foreach ($categories as $c): ?>
                        <?php if ($c['slug'] === $category) { continue; } ?>
                        <?= view('directory/_chip', [
                            'label' => $c['name'],
                            'href'  => $canonical . '?category=' . rawurlencode($c['slug']) . $carry,
                            'count' => $c['c'],
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($shown === 0): ?>
            <div class="empty">
                <p class="mb-4"><?= $q !== '' || $category !== '' ? 'Nothing here matches that.' : 'No businesses listed here yet.' ?></p>
                <a class="btn btn-accent" href="<?= base_url('add-listing') ?>">List your business — free</a>
            </div>
        <?php else: ?>
            <div class="card-grid">
                <?php foreach ($result['items'] as $l): ?>
                    <?= view('directory/_card', ['l' => $l, 'hideVenue' => true], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($result['totalPages'] > 1): ?>
            <nav class="pager">
                <?php
                $params = array_filter(['category' => $category, 'q' => $q]);
                $base   = $canonical . '?' . ($params !== [] ? http_build_query($params) . '&amp;' : '');
                ?>
                <?php for ($i = 1; $i <= $result['totalPages']; $i++): ?>
                    <?php if ($i === $result['page']): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= esc($base . 'page=' . $i, 'attr') ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

        <?php // One pin for the building — see the note at the top of this file. ?>
        <?= view('directory/_map_panel', [
            'row'     => $venue,
            'name'    => $name,
            'heading' => 'Where it is',
            'class'   => 'mt-8',
        ]) ?>

        <div class="mt-8 text-center">
            <a class="btn btn-accent" href="<?= base_url('add-listing') ?>">List your business — free</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= $this->endSection() ?>
