<?= $this->extend('layouts/public') ?>

<?php
/**
 * A country (/directory/{country}) or region (/directory/{country}/{region})
 * on local.webscheduler.app: province.php's job for every country with a
 * profile in Config\Countries. Same shape, same thin content rule (indexed
 * only once it clears landingMinListings), same internal links downwards.
 *
 * @var string      $code       ISO code
 * @var string      $country    the country's name
 * @var string      $slug       the country's URL slug
 * @var string|null $region     the region, or null on the country page
 */
$siteName   = config('Directory')->siteName();
$place      = $region ?? $country;
$heading    = 'Local services in ' . $place;
$countryUrl = base_url('directory/' . $slug);
$canonical  = $region === null ? $countryUrl : base_url('directory/' . $slug . '/' . slugify($region));
$total      = (int) $result['total'];

$crumbs = [
    ['name' => 'Discover', 'url' => base_url('directory')],
    ['name' => $country, 'url' => $countryUrl],
];
if ($region !== null) {
    $crumbs[] = ['name' => $region, 'url' => $canonical];
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
    'title'       => $heading . ' | ' . $siteName,
    'description' => 'Discover local businesses in ' . $place . ': doctors, attorneys, vets, dog walkers, home bakers, plumbers and more. Their services, locations and contact details, free.',
    'canonical'   => $canonical,
    'robots'      => $indexable,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="<?= base_url('directory') ?>">Discover</a>
            <span class="crumbs-sep">/</span>
            <?php if ($region !== null): ?>
                <a href="<?= esc($countryUrl, 'attr') ?>"><?= esc($country) ?></a>
                <span class="crumbs-sep">/</span>
            <?php endif; ?>
            <span aria-current="page"><?= esc($place) ?></span>
        </nav>
        <h1 class="text-2xl sm:text-3xl"><?= esc($heading) ?></h1>
        <p class="mt-2 text-sm text-white/80">
            <?= number_format($total) ?> <?= $total === 1 ? 'profile' : 'profiles' ?> in <?= esc($place) ?>.
        </p>
        <form class="search-form" method="get" action="<?= base_url('directory') ?>">
            <?= view('directory/_search_composer', [
                'listId'      => 'search-suggest-hero',
                'placeholder' => 'Discover in ' . $place,
            ], ['saveData' => false]) ?>
            <input type="hidden" name="country" value="<?= esc($code, 'attr') ?>">
            <?php if ($region !== null): ?>
                <input type="hidden" name="region" value="<?= esc($region, 'attr') ?>">
            <?php endif; ?>
        </form>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($total === 0): ?>
            <div class="empty">
                <p class="mb-4">Nothing in <?= esc($place) ?> yet. Be the first!</p>
                <a class="btn btn-accent" href="<?= esc(signup_cta()['url']) ?>"><?= esc(signup_cta()['label']) ?></a>
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
                        <a href="<?= esc($canonical . '?page=' . $i) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

        <?php // Down to the regions: the country page is their parent, as a
              // province page is the parent of its category pages on co.za. ?>
        <?php if ($regions !== []): ?>
            <div class="link-group">
                <h3>Discover by region</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($regions as $name => $count): ?>
                        <?= view('directory/_chip', [
                            'label' => (string) $name,
                            'href'  => base_url('directory/' . $slug . '/' . slugify((string) $name)),
                            'count' => (int) $count,
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php // There are no category pages per country yet, so these are
              // filtered discovery links, like the city chips below. ?>
        <?php if ($categories !== []): ?>
            <div class="link-group">
                <h3>Popular categories in <?= esc($place) ?></h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($categories as $c): ?>
                        <?= view('directory/_chip', [
                            'label' => $c['name'],
                            'href'  => base_url('directory') . '?' . http_build_query(array_filter([
                                'category' => $c['slug'], 'country' => $code, 'region' => $region,
                            ])),
                            'count' => (int) $c['listing_count'],
                            'tint'  => category_group_tint($c['group_name'] ?? null),
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($cityCounts !== []): ?>
            <div class="link-group">
                <h3>Towns and cities in <?= esc($place) ?></h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($cityCounts as $city => $count): ?>
                        <?= view('directory/_chip', [
                            'label' => (string) $city,
                            'href'  => base_url('directory') . '?' . http_build_query(array_filter([
                                'country' => $code, 'region' => $region, 'city' => (string) $city,
                            ])),
                            'count' => (int) $count,
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="link-group">
            <h3>Other countries</h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach (config('Countries')->profiles as $other => $profile): ?>
                    <?php if ($other === 'ZA' || $other === $code) { continue; } ?>
                    <?= view('directory/_chip', [
                        'label' => (string) config('Countries')->nameForCode($other),
                        'href'  => base_url('directory/' . $profile['slug']),
                    ], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-8 text-center">
            <a class="btn btn-accent" href="<?= esc(signup_cta()['url']) ?>"><?= esc(signup_cta()['label']) ?></a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
