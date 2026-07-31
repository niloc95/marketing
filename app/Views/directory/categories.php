<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('directory/categories');

// One emoji per group — a lightweight visual anchor with no new image assets,
// mirroring the group_name -> schema.org type map in show.php (same idea,
// same scope: a small local lookup, not a new DB column or asset pipeline).
$groupEmoji = [
    'Health & Medical'      => '🩺',
    'Beauty & Wellness'     => '💆',
    'Hair'                  => '💇',
    'Motoring'              => '🚗',
    'Legal & Financial'     => '⚖️',
    'Home & Trades'         => '🔧',
    'Professional Services' => '💼',
    'Fitness & Sport'       => '🏋️',
    'Education & Training'  => '🎓',
    'Retail & Other'        => '🛍️',
];

// A zero-listing category has no reachable landing page (renderLanding() 404s
// on an empty result), so it must never be rendered as a link here — only
// count it into structured data / visible lists once it actually has one.
$crumbs = [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Directory', 'item' => base_url('directory')],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'All categories', 'item' => $canonical],
];

$items = [];
foreach ($groups as $cats) {
    foreach ($cats as $c) {
        if ((int) $c['listing_count'] === 0) {
            continue;
        }
        $items[] = [
            '@type'    => 'ListItem',
            'position' => count($items) + 1,
            'url'      => base_url('directory/' . $c['slug']),
            'name'     => $c['name'],
        ];
    }
}

$schema = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs],
        ['@type' => 'ItemList', 'name' => 'All categories', 'numberOfItems' => count($items), 'itemListElement' => $items],
    ],
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'All categories — ' . $siteName,
    'description' => 'Browse every business category on ' . $siteName . ' — find the right local service, or list your own business free.',
    'canonical'   => $canonical,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero py-8 sm:py-10">
    <div class="container">
        <nav class="mb-2 text-sm text-white/70" aria-label="Breadcrumb">
            <a class="hover:text-white" href="<?= base_url('directory') ?>">Directory</a>
            <span class="mx-1">/</span>
            <span class="text-white">All categories</span>
        </nav>
        <h1 class="text-2xl sm:text-3xl">Browse by category</h1>
        <p class="mt-2 text-sm text-white/80">Every service on <?= esc($siteName) ?>, grouped for easy browsing.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php foreach ($groups as $groupName => $cats): ?>
            <?php $linkable = array_filter($cats, static fn ($c) => (int) $c['listing_count'] > 0); ?>
            <?php if ($linkable === []): continue; endif; ?>
            <div class="panel mb-6">
                <h2 class="mb-3 text-lg font-bold text-slate-900">
                    <?= esc($groupEmoji[$groupName] ?? '📁') ?> <?= esc($groupName) ?>
                </h2>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($linkable as $c): ?>
                        <a class="badge hover:bg-primary-50 hover:text-primary-600" href="<?= base_url('directory/' . $c['slug']) ?>">
                            <?= esc($c['name']) ?> (<?= (int) $c['listing_count'] ?>)
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="mt-8 text-center">
            <a class="btn btn-accent" href="<?= base_url('list-your-practice') ?>">List your business — free</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
