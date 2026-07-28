<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'WebScheduler Directory — Find a healthcare provider',
    'description' => 'Search South Africa\'s healthcare providers — doctors, dentists, physiotherapists, dieticians and more. Find a practitioner or list your practice.',
    'canonical'   => base_url('/'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <span class="eyebrow">Africa's healthcare directory</span>
        <h1>Find the right <span class="accent">healthcare provider</span></h1>
        <p>Search doctors, dentists, therapists and specialists across South Africa — or list your own practice so new clients can find you.</p>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <input type="text" name="q" placeholder="Name, service or keyword">
            <select name="profession">
                <option value="">All professions</option>
                <?php foreach ($professions as $p): ?>
                    <option value="<?= esc($p['slug'], 'attr') ?>"><?= esc($p['name']) ?></option>
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
        <h2>Featured providers</h2>
        <?php if (empty($featured)): ?>
            <div class="empty">
                <p>No providers listed yet. Be the first!</p>
                <a class="btn btn-accent" href="<?= base_url('list-your-practice') ?>">List your practice</a>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($featured as $l): ?>
                    <?= view('directory/_card', ['l' => $l]) ?>
                <?php endforeach; ?>
            </div>
            <div style="text-align:center;margin-top:24px">
                <a class="btn btn-ghost" href="<?= base_url('directory') ?>">Browse all providers</a>
            </div>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
