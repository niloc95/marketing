<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?php
$title = 'Browse healthcare providers';
if (! empty($filters['profession'])) { $title = ucwords(str_replace('-', ' ', $filters['profession'])) . ' listings'; }
?>
<?= seo_meta([
    'title'       => $title . ' — WebScheduler Directory',
    'description' => 'Browse and search healthcare providers by profession, province and city.',
    'canonical'   => base_url('directory'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero" style="padding:34px 0 26px">
    <div class="container">
        <h1 style="font-size:28px">Find a provider</h1>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <input type="text" name="q" value="<?= esc($filters['q'], 'attr') ?>" placeholder="Name, service or keyword">
            <select name="profession">
                <option value="">All professions</option>
                <?php foreach ($professions as $p): ?>
                    <option value="<?= esc($p['slug'], 'attr') ?>" <?= ($filters['profession'] === $p['slug']) ? 'selected' : '' ?>><?= esc($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="province">
                <option value="">All provinces</option>
                <?php foreach ($provinces as $prov): ?>
                    <option value="<?= esc($prov, 'attr') ?>" <?= ($filters['province'] === $prov) ? 'selected' : '' ?>><?= esc($prov) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container">
        <p style="color:var(--muted);margin:0 0 16px"><?= (int) $result['total'] ?> provider<?= $result['total'] === 1 ? '' : 's' ?> found</p>

        <?php if (empty($result['items'])): ?>
            <div class="empty">
                <p>No providers match your search.</p>
                <a class="btn btn-ghost" href="<?= base_url('directory') ?>">Clear filters</a>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($result['items'] as $l): ?>
                    <?= view('directory/_card', ['l' => $l]) ?>
                <?php endforeach; ?>
            </div>

            <?php if ($result['totalPages'] > 1): ?>
                <nav class="pager">
                    <?php
                    $q = $filters;
                    for ($i = 1; $i <= $result['totalPages']; $i++):
                        $q['page'] = $i;
                        $href = base_url('directory') . '?' . http_build_query(array_filter($q, fn ($v) => $v !== '' && $v !== null));
                    ?>
                        <?php if ($i === $result['page']): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= esc($href, 'attr') ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
