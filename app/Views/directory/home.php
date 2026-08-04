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
    'title'       => $siteName . ' — Find a local business or service',
    'description' => 'Search South African service businesses — hair salons, spas, attorneys, plumbers, mechanics, doctors and more. Find a business, or list yours free.',
    'canonical'   => base_url('/'),
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <span class="eyebrow">South Africa's business directory</span>
        <h1>Find the right <span class="text-brand-golden">local business</span></h1>
        <p>Salons, spas, attorneys, mechanics, plumbers, doctors and more — across South Africa. Or list your own business, free.</p>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <input type="text" name="q" placeholder="Business, service or keyword">
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
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Featured businesses</h2>
        <?php if (empty($featured)): ?>
            <div class="empty">
                <p class="mb-4">No businesses listed yet. Be the first!</p>
                <a class="btn btn-accent" href="<?= base_url('list-your-practice') ?>">List your business — free</a>
            </div>
        <?php else: ?>
            <div class="card-grid">
                <?php foreach ($featured as $l): ?>
                    <?= view('directory/_card', ['l' => $l]) ?>
                <?php endforeach; ?>
            </div>
            <div class="mt-6 text-center">
                <a class="btn btn-ghost" href="<?= base_url('directory') ?>">Browse all businesses</a>
                <a class="btn btn-ghost" href="<?= base_url('directory/categories') ?>">Browse by category</a>
            </div>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
