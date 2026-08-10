<?= $this->extend('layouts/public') ?>

<?php
$siteName = config('Directory')->siteName();

// WebSite+SearchAction unlocks the sitelinks searchbox; Organization backs the
// brand knowledge panel. One canonical instance of each, here on the homepage.
$schema = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'          => 'WebSite',
            'name'           => $siteName,
            'url'            => base_url('/'),
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => base_url('directory') . '?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ],
        [
            '@type' => 'Organization',
            'name'  => $siteName,
            'url'   => base_url('/'),
            'logo'  => base_url(config('Directory')->ogImage()),
        ],
    ],
];
?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => $siteName . ' — Find someone local',
    'description' => 'Search South African services, professionals and home industry — doctors, attorneys, vets, dog walkers, home bakers, plumbers and more. Find someone local, or add your business free.',
    'canonical'   => base_url('/'),
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <span class="eyebrow">Local services, professionals &amp; home industry</span>
        <h1>Find someone <span class="text-brand-golden">local</span> you can trust</h1>
        <p>Doctors, attorneys, vets, dog walkers, home bakers, plumbers and more — across South Africa. Or add your own business, free.</p>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <input type="text" name="q" placeholder="Name, service or keyword">
            <select name="category">
                <option value="">All categories</option>
                <?php foreach ($groups as $groupName => $cats): ?>
                    <optgroup label="<?= esc($groupName, 'attr') ?>">
                    <?php foreach ($cats as $p): ?>
                        <option value="<?= esc($p['slug'], 'attr') ?>"><?= esc($p['name']) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
            <select name="province">
                <option value="">All provinces</option>
                <?php foreach ($provinces as $prov): ?>
                    <option value="<?= esc($prov, 'attr') ?>"><?= esc($prov) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>

        <?php // Crawlable shortcuts into the busiest landing pages, straight off
              // the hero — the fastest route in for someone who does not yet
              // know what to type. ?>
        <?php if ($topCategories !== []): ?>
            <div class="hero-chips">
                <p class="hero-chips-label">Popular categories</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($topCategories as $c): ?>
                        <?= view('directory/_chip', [
                            'label' => $c['name'],
                            'href'  => base_url('directory/' . $c['slug']),
                            'count' => (int) $c['listing_count'],
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($stats['listings'] > 0): ?>
    <div class="stat-bar">
        <div class="container flex flex-wrap items-center justify-center gap-x-8 gap-y-2">
            <span class="stat"><strong><?= number_format($stats['listings']) ?></strong> profiles</span>
            <span class="stat"><strong><?= number_format($stats['categories']) ?></strong> categories</span>
            <span class="stat"><strong><?= number_format($stats['provinces']) ?></strong> provinces</span>
            <span class="stat"><strong>Always</strong> free</span>
        </div>
    </div>
<?php endif; ?>

<?php if ($stats['listings'] === 0): ?>
    <section class="section">
        <div class="container">
            <div class="empty">
                <p class="mb-4">Nothing here yet. Be the first!</p>
                <a class="btn btn-accent" href="<?= base_url('list-your-practice') ?>">Add your business — free</a>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php // Only renders when an admin has actually flagged something. featured() is
      // strict now, so an empty result means "nothing curated yet", not "nothing
      // listed" — the section stands down rather than showing an empty grid. ?>
<?php if ($featured !== []): ?>
    <section class="section">
        <div class="container">
            <div class="section-head">
                <h2>Featured</h2>
                <p>Hand-picked from across South Africa — verified, published and open for enquiries.</p>
            </div>
            <div class="card-grid-4">
                <?php foreach ($featured as $l): ?>
                    <?= view('directory/_card', ['l' => $l]) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($topCategories !== []): ?>
    <section class="section section-alt">
        <div class="container">
            <div class="section-head">
                <h2>Explore by Category</h2>
                <p>Doctors, attorneys, vets, dog walkers, home bakers, plumbers and more — browse what South Africans search for most.</p>
            </div>
            <div class="tile-grid">
                <?php foreach ($topCategories as $c): ?>
                    <?= view('directory/_category_tile', ['c' => $c], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
            <div class="mt-8 text-center">
                <a class="btn btn-ghost" href="<?= base_url('directory/categories') ?>">Browse all categories &rarr;</a>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($provinceCounts !== []): ?>
    <section class="section">
        <div class="container">
            <div class="section-head-split">
                <div>
                    <h2>Browse by Location</h2>
                    <p>Every province we cover, with the towns and cities where our profiles actually are.</p>
                </div>
                <a class="btn btn-ghost shrink-0" href="<?= base_url('directory') ?>">Browse everything &rarr;</a>
            </div>
            <div class="loc-grid">
                <?php $i = 0; ?>
                <?php foreach ($provinceCounts as $province => $count): ?>
                    <?= view('directory/_location_card', [
                        'province' => (string) $province,
                        'count'    => (int) $count,
                        'cities'   => $provinceCities[$province] ?? [],
                        'i'        => $i++,
                    ], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($recent !== []): ?>
    <section class="section section-alt">
        <div class="container">
            <div class="section-head-split">
                <div>
                    <h2>Recently added</h2>
                    <p>The newest to join <?= esc($siteName) ?>.</p>
                </div>
                <a class="btn btn-ghost shrink-0" href="<?= base_url('directory') ?>">See all &rarr;</a>
            </div>
            <div class="card-grid-4">
                <?php foreach ($recent as $l): ?>
                    <?= view('directory/_card', ['l' => $l]) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($stats['listings'] > 0): ?>
    <section class="section">
        <div class="container text-center">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Run a business in South Africa?</h2>
            <p class="mx-auto mt-2 max-w-xl text-slate-500 dark:text-slate-400">Add it to <?= esc($siteName) ?> in a couple of minutes. No fee, no card, no contract.</p>
            <a class="btn btn-accent mt-5" href="<?= base_url('list-your-practice') ?>">Add your business — free</a>
        </div>
    </section>
<?php endif; ?>
<?= $this->endSection() ?>
