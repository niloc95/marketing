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
<section class="hero pt-24 pb-8 sm:pt-28 sm:pb-10">
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
        <h1 class="text-2xl sm:text-3xl"><?= esc($heading) ?></h1>
        <p class="mt-2 text-sm text-white/80">
            <?= $total ?> <?= $total === 1 ? 'profile' : 'profiles' ?><?= $province !== null ? ' in ' . esc($province) : '' ?>.
        </p>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <input type="text" name="q" placeholder="Search within <?= esc(strtolower($plural), 'attr') ?>">
            <input type="hidden" name="category" value="<?= esc($catSlug, 'attr') ?>">
            <?php if ($province !== null): ?><input type="hidden" name="province" value="<?= esc($province, 'attr') ?>"><?php endif; ?>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($total === 0): ?>
            <div class="empty">
                <p class="mb-4">No <?= esc(strtolower($plural)) ?> here yet<?= esc($where) ?>. Be the first!</p>
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
                        <a href="<?= esc($canonical . '?page=' . $i, 'attr') ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

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
