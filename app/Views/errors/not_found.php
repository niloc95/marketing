<?= $this->extend('layouts/public') ?>

<?php
/**
 * The branded 404. Rendered by Controllers\Errors, the router's 404 override.
 *
 * @var bool                            $wasListing the missing URL looked like a business profile
 * @var array<int,array<string,mixed>>  $popular    header_quick_categories(); [] when unavailable
 */
$siteName = config('Directory')->siteName();
$link     = 'font-medium text-primary-500 dark:text-primary-300 hover:underline';
?>

<?= $this->section('head') ?>
<?php // An explicit canonical: seo_meta() defaults to current_url(), which here
      // is whatever a scanner or a spam link asked for. That must not be
      // reflected into the page. ?>
<?= seo_meta([
    'title'     => 'Page not found — ' . $siteName,
    'robots'    => 'noindex, follow',
    'canonical' => base_url('/'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <p class="mb-2 text-sm font-semibold uppercase tracking-wide text-white/70">Error 404</p>
        <?php if ($wasListing): ?>
            <h1 class="text-2xl sm:text-3xl">We couldn't find that business</h1>
            <p class="mt-2 text-sm text-white/80">
                It may have changed its name, or its owner may have removed it. Search for it below, or browse the directory.
            </p>
        <?php else: ?>
            <h1 class="text-2xl sm:text-3xl">We couldn't find that page</h1>
            <p class="mt-2 text-sm text-white/80">
                The link may be old or mistyped. Try a search, or pick up from one of the links below.
            </p>
        <?php endif; ?>

        <form class="searchbar mt-5" method="get" action="<?= base_url('directory') ?>">
            <?= view('directory/_search_input', [
                'listId'      => 'search-suggest-404',
                'value'       => '',
                'placeholder' => 'Name, service or keyword',
                'ariaLabel'   => 'Search the directory',
                'type'        => 'text',
            ]) ?>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($popular !== []): ?>
            <div class="panel mb-6">
                <h2 class="mb-3 text-lg font-bold text-slate-900 dark:text-white">Popular categories</h2>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($popular as $cat): ?>
                        <?= view('directory/_chip', [
                            'label' => $cat['name'],
                            'href'  => base_url('directory/' . $cat['slug']),
                        ], ['saveData' => false]) ?>
                    <?php endforeach; ?>
                    <?= view('directory/_chip', [
                        'label' => 'All categories',
                        'href'  => base_url('directory/categories'),
                    ], ['saveData' => false]) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2 class="mb-3 text-lg font-bold text-slate-900 dark:text-white">Or try one of these</h2>
            <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-300">
                <li><a class="<?= $link ?>" href="<?= base_url('directory') ?>">Browse every business</a></li>
                <li><a class="<?= $link ?>" href="<?= base_url('jobs') ?>">Jobs &amp; services needed</a></li>
                <?php if ($wasListing): ?>
                    <li>Is this your business? <a class="<?= $link ?>" href="<?= base_url('manage') ?>">Manage your profile</a> or <a class="<?= $link ?>" href="<?= esc(signup_cta()['url']) ?>">list it again</a>.</li>
                <?php else: ?>
                    <li><a class="<?= $link ?>" href="<?= esc(signup_cta()['url']) ?>"><?= esc(signup_cta()['label']) ?></a></li>
                <?php endif; ?>
                <li><a class="<?= $link ?>" href="<?= base_url('recommend') ?>">Recommend a business we're missing</a></li>
                <li>Followed a broken link on our site? <a class="<?= $link ?>" href="<?= base_url('contact') ?>">Tell us</a> and we'll fix it.</li>
            </ul>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
