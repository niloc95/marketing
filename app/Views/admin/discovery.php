<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Discovery | Admin']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * @var string $site 'za' or 'app'
 * @var string $term
 * @var array{categories: list<array<string,mixed>>, related: list<array<string,mixed>>} $links
 * @var list<array{query:string,searches:int,last:string}> $empty
 * @var list<array{query:string,searches:int,last:string}> $top
 * @var list<array{text:string,text_key:string,times:int,last:string,category_id:?int,category:string}> $unmatched
 * @var list<array{id:int,phrase:string,category_id:int,category:string,created_at:string}> $categoryLinks
 * @var list<array<string,mixed>> $categoryChoices active categories in group order
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

<div class="shell-page">
    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        What the discovery model learned from the profiles and from opened results (rebuilt every night by
        <code>discovery:learn</code>), and what people asked for in the last 30 days. Block removes a link now and for good.
        Nothing here changes the order of results.
    </p>

    <div class="tabs">
        <a class="<?= $site === 'za' ? 'active' : '' ?>" href="<?= esc($here(array_filter(['term' => $term]))) ?>">local.webscheduler.co.za</a>
        <a class="<?= $site === 'app' ? 'active' : '' ?>" href="<?= esc($here(array_filter(['site' => 'app', 'term' => $term]))) ?>">local.webscheduler.app</a>
    </div>

    <h2 class="mt-6 mb-2 section-title">Searches that found nothing</h2>
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

    <h2 class="mt-6 mb-2 section-title">Most asked</h2>
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

    <h2 class="mt-6 mb-2 section-title" id="business-types">Business types we could not match</h2>
    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
        What new businesses typed under "What does your business do?" in the last 90 days when nothing we suggested was
        the category they chose. Link sends that wording to a category, first, for every business after them.
        Typed wording is kept 180 days.
    </p>
    <?php
    $categorySelect = static function (?int $selected) use ($categoryChoices): string {
        $html = '<select class="input" name="category_id" required><option value="">Category…</option>';
        $group = null;
        foreach ($categoryChoices as $c) {
            if (($c['group_name'] ?? '') !== $group) {
                $html .= ($group !== null ? '</optgroup>' : '') . '<optgroup label="' . esc((string) $c['group_name'], 'attr') . '">';
                $group = (string) ($c['group_name'] ?? '');
            }
            $html .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $selected ? ' selected' : '') . '>' . esc((string) $c['name']) . '</option>';
        }

        return $html . ($group !== null ? '</optgroup>' : '') . '</select>';
    };
    ?>
    <?php if ($unmatched === []): ?>
        <div class="empty">None in the last 90 days.</div>
    <?php else: ?>
        <div class="tablewrap mb-6">
            <table class="table">
                <thead><tr><th>Typed</th><th>Times</th><th>Chose</th><th>Last</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($unmatched as $row): ?>
                    <tr>
                        <td><?= esc($row['text']) ?></td>
                        <td><?= (int) $row['times'] ?></td>
                        <td><?= esc($row['category'] !== '' ? $row['category'] : 'Nothing yet') ?></td>
                        <td><?= esc($row['last']) ?></td>
                        <td>
                            <?php if ($row['category_id'] !== null): ?>
                                <form method="post" action="<?= esc(base_url('admin/discovery/category-link'), 'attr') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="site" value="<?= esc($site, 'attr') ?>">
                                    <input type="hidden" name="phrase" value="<?= esc($row['text_key'], 'attr') ?>">
                                    <input type="hidden" name="category_id" value="<?= (int) $row['category_id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-xs">Link to <?= esc($row['category']) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= esc(base_url('admin/discovery/category-link'), 'attr') ?>" class="mb-4 flex flex-wrap items-end gap-3">
        <?= csrf_field() ?>
        <input type="hidden" name="site" value="<?= esc($site, 'attr') ?>">
        <label class="block">
            <span class="label">Wording</span>
            <input class="input" type="text" name="phrase" maxlength="120" required placeholder="spaza shop">
        </label>
        <label class="block">
            <span class="label">Category</span>
            <?= $categorySelect(null) ?>
        </label>
        <button type="submit" class="btn btn-primary btn-sm">Link</button>
    </form>

    <h2 class="mt-6 mb-2 section-title">Linked business wording</h2>
    <?php if ($categoryLinks === []): ?>
        <div class="empty">Nothing linked yet.</div>
    <?php else: ?>
        <div class="tablewrap mb-6">
            <table class="table">
                <thead><tr><th>Wording</th><th>Category</th><th>Linked</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($categoryLinks as $row): ?>
                    <tr>
                        <td><?= esc($row['phrase']) ?></td>
                        <td><?= esc($row['category']) ?></td>
                        <td><?= esc($row['created_at']) ?></td>
                        <td>
                            <form method="post" action="<?= esc(base_url('admin/discovery/category-unlink'), 'attr') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="site" value="<?= esc($site, 'attr') ?>">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-xs">Remove</button>
                            </form>
                        </td>
                    </tr>
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

    <h2 class="mb-2 section-title">Word → category</h2>
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

    <h2 class="mb-2 section-title">Related words (used to widen a search)</h2>
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
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Discovery<?= $this->endSection() ?>
