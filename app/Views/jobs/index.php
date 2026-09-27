<?= $this->extend('layouts/public') ?>

<?php
/**
 * /jobs — vacancies and service requests.
 *
 * @var array<string,string>          $filters
 * @var list<array<string,mixed>>     $items
 * @var int                           $total
 * @var list<array<string,mixed>>     $categories
 * @var list<string>                  $provinces
 * @var array<string,array>           $types
 * @var App\Services\JobBoardService  $svc
 * @var bool                          $indexable
 */
$siteName  = config('Directory')->siteName();
$canonical = base_url('jobs');

$kindHref = static function (string $kind) use ($filters): string {
    $q = array_filter(['kind' => $kind] + $filters, static fn ($v) => $v !== '');
    if ($kind === '') {
        unset($q['kind']);
    }
    if ($kind === 'service') {
        unset($q['type']);
    }

    return base_url('jobs') . ($q === [] ? '' : '?' . http_build_query($q));
};
$kinds = ['' => 'Everything', 'job' => 'Jobs', 'service' => 'Services needed'];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Jobs and services needed — ' . $siteName,
    'description' => 'Local jobs and requests for services across South Africa, posted by businesses listed on ' . $siteName . ' and by people who need work done.',
    'canonical'   => $canonical,
    'robots'      => $indexable,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <h1 class="text-2xl sm:text-3xl">Jobs &amp; services needed</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/80">
            Vacancies from local businesses, and people looking for someone to do a job.
            Posting is free.
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
            <a class="btn btn-accent" href="<?= base_url('jobs/post?kind=job') ?>"><?= lucide('briefcase', 'h-4 w-4 shrink-0') ?>Post a job</a>
            <a class="btn btn-primary" href="<?= base_url('jobs/post?kind=service') ?>"><?= lucide('wrench', 'h-4 w-4 shrink-0') ?>Request a service</a>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="sort-toggle mb-4" role="group" aria-label="Show">
            <?php foreach ($kinds as $value => $label): ?>
                <?php if ($value === $filters['kind']): ?>
                    <span class="near-chip is-active" aria-current="true"><?= esc($label) ?></span>
                <?php else: ?>
                    <a class="near-chip" href="<?= esc($kindHref($value), 'attr') ?>"><?= esc($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?= base_url('jobs') ?>" class="panel mb-6 p-4">
            <?php if ($filters['kind'] !== ''): ?>
                <input type="hidden" name="kind" value="<?= esc($filters['kind'], 'attr') ?>">
            <?php endif; ?>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="field mb-0">
                    <label for="jobs-q">Keyword</label>
                    <input type="text" id="jobs-q" name="q" value="<?= esc($filters['q'], 'attr') ?>" placeholder="e.g. electrician">
                </div>
                <div class="field mb-0">
                    <label for="jobs-province">Province</label>
                    <select id="jobs-province" name="province">
                        <option value="">Anywhere</option>
                        <?php foreach ($provinces as $prov): ?>
                            <option value="<?= esc($prov, 'attr') ?>" <?= $filters['province'] === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field mb-0">
                    <label for="jobs-category">Category</label>
                    <select id="jobs-category" name="category">
                        <option value="">Any category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= esc($cat['slug'], 'attr') ?>" <?= $filters['category'] === $cat['slug'] ? 'selected' : '' ?>><?= esc($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($filters['kind'] !== 'service'): ?>
                    <div class="field mb-0">
                        <label for="jobs-type">Job type</label>
                        <select id="jobs-type" name="type">
                            <option value="">Any type</option>
                            <?php foreach ($types as $key => $t): ?>
                                <option value="<?= esc($key, 'attr') ?>" <?= $filters['type'] === $key ? 'selected' : '' ?>><?= esc($t['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-3 flex gap-2">
                <button class="btn btn-primary" type="submit"><?= lucide('search', 'h-4 w-4 shrink-0') ?>Search</button>
                <?php if (array_filter($filters) !== []): ?>
                    <a class="btn btn-ghost" href="<?= base_url('jobs') ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <p class="mb-3 text-sm text-slate-500 dark:text-slate-400"><?= (int) $total ?> open post<?= $total === 1 ? '' : 's' ?></p>

        <?php if ($items === []): ?>
            <div class="empty">
                Nothing matches yet.
                <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('jobs/post') ?>">Be the first to post</a>.
            </div>
        <?php else: ?>
            <div class="card-grid">
                <?php foreach ($items as $p): ?>
                    <?= view('jobs/_card', ['p' => $p, 'svc' => $svc], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
            <?php if ($pager->getPageCount() > 1): ?>
                <div class="mt-6"><?= $pager->links() ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php // The one safety message every job board in SA needs, stated where
              // job seekers browse, not only on each post. ?>
        <div class="alert alert-info mt-8">
            Never pay to apply for a job. No real employer asks for a registration, training or uniform fee.
            If a post asks for money, open it and use "Report this post".
        </div>
    </div>
</section>
<?= $this->endSection() ?>
