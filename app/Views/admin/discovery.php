<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Discovery | Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * @var string $site 'za' or 'app'
 * @var string $term
 * @var array{categories: list<array<string,mixed>>, related: list<array<string,mixed>>} $links
 * @var list<array{query:string,searches:int,last:string}> $empty
 * @var list<array{query:string,searches:int,last:string}> $top
 */
$here = static fn (array $query): string => base_url('admin/discovery') . ($query === [] ? '' : '?' . http_build_query($query));
$siteQuery = $site === 'app' ? ['site' => 'app'] : [];
$blockForm = static function (string $kind, string $word, string $target) use ($site, $term): string {
    $hidden = ['site' => $site, 'kind' => $kind, 'term' => $word, 'target' => $target, 'return_term' => $term];
    $html   = '<form method="post" action="' . esc(base_url('admin/discovery/block'), 'attr') . '">' . csrf_field();
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . $name . '" value="' . esc($value, 'attr') . '">';
    }

    return $html . '<button type="submit" class="btn btn-ghost btn-xs">Block</button></form>';
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Discovery</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            What the discovery model learned from the profiles and from opened results (rebuilt every night by
            <code>discovery:learn</code>), and what people asked for in the last 30 days. Block removes a link now and for good.
            Nothing here changes the order of results.
        </p>

        <div class="tabs">
            <a class="<?= $site === 'za' ? 'active' : '' ?>" href="<?= esc($here(array_filter(['term' => $term]))) ?>">local.webscheduler.co.za</a>
            <a class="<?= $site === 'app' ? 'active' : '' ?>" href="<?= esc($here(array_filter(['site' => 'app', 'term' => $term]))) ?>">local.webscheduler.app</a>
        </div>

        <h2 class="mt-6 mb-2 text-lg font-semibold text-slate-900 dark:text-white">Searches that found nothing</h2>
        <?php if ($empty === []): ?>
            <div class="empty">None in the last 30 days.</div>
        <?php else: ?>
            <div class="tablewrap mb-6">
                <table class="table">
                    <thead><tr><th>Words</th><th>Times</th><th>Last</th></tr></thead>
                    <tbody>
                    <?php foreach ($empty as $row): ?>
                        <tr><td><?= esc($row['query']) ?></td><td><?= (int) $row['searches'] ?></td><td><?= esc($row['last']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="mt-6 mb-2 text-lg font-semibold text-slate-900 dark:text-white">Most asked</h2>
        <?php if ($top === []): ?>
            <div class="empty">None in the last 30 days.</div>
        <?php else: ?>
            <div class="tablewrap mb-6">
                <table class="table">
                    <thead><tr><th>Words</th><th>Times</th><th>Last</th></tr></thead>
                    <tbody>
                    <?php foreach ($top as $row): ?>
                        <tr><td><a href="<?= esc($here($siteQuery + ['term' => (string) strtok($row['query'], ' ')])) ?>"><?= esc($row['query']) ?></a></td><td><?= (int) $row['searches'] ?></td><td><?= esc($row['last']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <form method="get" action="<?= esc(base_url('admin/discovery'), 'attr') ?>" class="mt-8 mb-4 flex flex-wrap items-end gap-3">
            <?php if ($site === 'app'): ?><input type="hidden" name="site" value="app"><?php endif; ?>
            <label class="block">
                <span class="label">Learned links for a word</span>
                <input class="input" type="text" name="term" value="<?= esc($term, 'attr') ?>" placeholder="cancer">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">Show</button>
            <?php if ($term !== ''): ?><a class="text-sm" href="<?= esc($here($siteQuery)) ?>">Show the strongest</a><?php endif; ?>
        </form>

        <h2 class="mb-2 text-lg font-semibold text-slate-900 dark:text-white">Word → category</h2>
        <?php if ($links['categories'] === []): ?>
            <div class="empty">Nothing learned<?= $term !== '' ? ' for “' . esc($term) . '”' : '' ?> yet.</div>
        <?php else: ?>
            <div class="tablewrap mb-6">
                <table class="table">
                    <thead><tr><th>Word</th><th>Category</th><th>Profiles</th><th>Score</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($links['categories'] as $row): ?>
                        <tr>
                            <td><?= esc($row['term']) ?></td>
                            <td><?= esc($row['category']) ?></td>
                            <td><?= (int) $row['profiles'] ?></td>
                            <td><?= esc(number_format((float) $row['score'], 2)) ?></td>
                            <td><?= $blockForm('category', (string) $row['term'], (string) $row['category_id']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2 class="mb-2 text-lg font-semibold text-slate-900 dark:text-white">Related words (used to widen a search)</h2>
        <?php if ($links['related'] === []): ?>
            <div class="empty">Nothing learned<?= $term !== '' ? ' for “' . esc($term) . '”' : '' ?> yet.</div>
        <?php else: ?>
            <div class="tablewrap mb-6">
                <table class="table">
                    <thead><tr><th>Word</th><th>Related</th><th>Shared profiles</th><th>Score</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($links['related'] as $row): ?>
                        <tr>
                            <td><?= esc($row['term']) ?></td>
                            <td><?= esc($row['related']) ?></td>
                            <td><?= (int) $row['profiles'] ?></td>
                            <td><?= esc(number_format((float) $row['score'], 2)) ?></td>
                            <td><?= $blockForm('related', (string) $row['term'], (string) $row['related']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
