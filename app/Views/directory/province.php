<?= $this->extend('layouts/public') ?>

<?php
helper('slug');
$siteName  = config('Directory')->siteName();
$heading   = 'Local services in ' . $province;
$canonical = base_url('directory/province/' . slugify($province));
$total     = (int) $result['total'];

// The templated FAQ that used to live here is gone for the same reason it went
// from landing.php: two boilerplate questions repeated across every province
// page is a thin-content signal, and FAQ rich results have been deprecated
// since August 2023 so it earned nothing back.
$schema = schema_page(
    [
        schema_breadcrumb([
            ['name' => 'Browse', 'url' => base_url('directory')],
            ['name' => $province, 'url' => $canonical],
        ], $canonical),
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
    'description' => 'Find someone local in ' . $province . ' — doctors, attorneys, vets, dog walkers, home bakers, plumbers and more. Browse contact details and locations, free.',
    'canonical'   => $canonical,
    'robots'      => $indexable,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero py-8 sm:py-10">
    <div class="container">
        <nav class="mb-2 text-sm text-white/70" aria-label="Breadcrumb">
            <a class="hover:text-white" href="<?= base_url('directory') ?>">Browse</a>
            <span class="mx-1">/</span>
            <span class="text-white"><?= esc($province) ?></span>
        </nav>
        <h1 class="text-2xl sm:text-3xl"><?= esc($heading) ?></h1>
        <p class="mt-2 text-sm text-white/80">
            <?= number_format($total) ?> <?= $total === 1 ? 'profile' : 'profiles' ?> in <?= esc($province) ?>.
        </p>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <input type="text" name="q" placeholder="Search in <?= esc($province, 'attr') ?>">
            <input type="hidden" name="province" value="<?= esc($province, 'attr') ?>">
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($total === 0): ?>
            <div class="empty">
                <p class="mb-4">Nothing in <?= esc($province) ?> yet. Be the first!</p>
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

        <?php // Internal links, same job they do on landing.php: this page is the
              // parent of the category × province pages, so it has to pass equity
              // down to them rather than dead-ending. ?>
        <?php if ($categories !== []): ?>
            <div class="panel mt-8">
                <h3>Popular categories in <?= esc($province) ?></h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($categories as $c): ?>
                        <?= view('directory/_chip', [
                            'label' => $c['name'],
                            'href'  => base_url('directory/' . $c['slug'] . '/' . slugify($province)),
                            'count' => (int) $c['listing_count'],
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                    <?= view('directory/_chip', ['label' => 'All categories →', 'href' => base_url('directory/categories')], ['saveData' => false]) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php // Towns have no landing tier of their own, so these are filtered
              // search links — navigation for a visitor, not pages for a crawler. ?>
        <?php if ($cityCounts !== []): ?>
            <div class="panel mt-4">
                <h3>Towns and cities in <?= esc($province) ?></h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($cityCounts as $city => $count): ?>
                        <?= view('directory/_chip', [
                            'label' => (string) $city,
                            'href'  => base_url('directory') . '?' . http_build_query(['province' => $province, 'city' => $city]),
                            'count' => (int) $count,
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="panel mt-4">
            <h3>Other provinces</h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach (App\Services\DirectoryService::SA_PROVINCES as $p): ?>
                    <?php if ($p === $province) { continue; } ?>
                    <?= view('directory/_chip', [
                        'label' => $p,
                        'href'  => base_url('directory/province/' . slugify($p)),
                    ], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-8 text-center">
            <a class="btn btn-accent" href="<?= base_url('add-listing') ?>">List your business — free</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
