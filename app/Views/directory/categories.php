<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('directory/categories');

// A zero-listing category has no reachable landing page (renderLanding() 404s
// on an empty result), so it must never be rendered as a link here — only
// count it into structured data / visible lists once it actually has one.
$links = [];
foreach ($groups as $cats) {
    foreach ($cats as $c) {
        if ((int) $c['listing_count'] === 0) {
            continue;
        }
        $links[] = ['url' => base_url('directory/' . $c['slug']), 'name' => (string) $c['name']];
    }
}

// schema_link_elements(), not schema_listing_elements(): the members here are
// landing pages, not businesses.
$schema = schema_page(
    [
        schema_breadcrumb([
            ['name' => 'Browse', 'url' => base_url('directory')],
            ['name' => 'All categories', 'url' => $canonical],
        ], $canonical),
        schema_item_list('All categories', schema_link_elements($links), null, $canonical),
    ],
    $canonical,
    'CollectionPage',
    'All categories'
);
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'All categories — ' . $siteName,
    'description' => 'Every category on ' . $siteName . ' — discover local businesses, services and professionals, or create your free business profile.',
    'canonical'   => $canonical,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="<?= base_url('directory') ?>">Browse</a>
            <span class="crumbs-sep">/</span>
            <span aria-current="page">All categories</span>
        </nav>
        <h1 class="text-2xl sm:text-3xl">Browse by category</h1>
        <p class="mt-2 text-sm text-white/80">Everything on <?= esc($siteName) ?>, grouped for easy browsing.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php foreach ($groups as $groupName => $cats): ?>
            <?php $linkable = array_filter($cats, static fn ($c) => (int) $c['listing_count'] > 0); ?>
            <?php if ($linkable === []): continue; endif; ?>
            <?php $style = category_group_style($groupName); ?>
            <?php // The tint class on the group rather than on each of the three
                  // things that read it: the icon, the heading and every chip below
                  // all inherit the custom properties from here, so the group is
                  // one colour decision instead of three. ?>
            <div class="link-group link-group-lg <?= $style['tint'] ?>">
                <h2 class="cat-group-title mb-3 flex items-center gap-2 text-lg font-bold">
                    <span class="cat-group-icon"><?= lucide($style['icon'], 'h-5 w-5 shrink-0') ?></span><?= esc($groupName) ?>
                </h2>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($linkable as $c): ?>
                        <?= view('directory/_chip', [
                            'label' => $c['name'],
                            // No 'tint': the band above already sets the vars and
                            // .chip reads them by inheritance.
                            'href'  => base_url('directory/' . $c['slug']),
                            'count' => (int) $c['listing_count'],
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="mt-8 text-center">
            <a class="btn btn-accent" href="<?= esc(signup_cta()['url']) ?>"><?= esc(signup_cta()['label']) ?></a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
