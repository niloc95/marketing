<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'System handbook | Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * The system handbook. Written chapters live in admin/handbook/<id>.php
 * (order and titles in Config\Handbook::$chapters); every partial gets the
 * same data, so a written chapter can quote a live figure.
 *
 * @var array<string,string>           $chapters
 * @var ?list<array<string,mixed>>     $tables
 * @var ?list<array<string,mixed>>     $siteCounts
 * @var ?array<string,list<array{label:string,n:int}>> $breakdowns
 * @var ?array<string,mixed>           $migrations
 * @var ?array<string,mixed>           $routes
 * @var ?array<string,mixed>           $stack
 * @var ?list<array{when:string,command:string}> $schedule
 * @var ?array<string,array<string,string>> $tokens
 * @var ?array<string,mixed>           $ids
 * @var list<string>                   $failed
 * @var string                         $generated
 */
$data = compact('tables', 'siteCounts', 'breakdowns', 'migrations', 'routes', 'stack', 'schedule', 'tokens', 'ids');
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container handbook">
        <header class="handbook-head">
            <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">System handbook</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                WebScheduler Local (local.webscheduler.co.za and local.webscheduler.app), Bookings inside it,
                and the brand site webscheduler.co.za. Figures are read live: <?= esc($generated) ?>.
                Print to PDF from the browser for a copy.
            </p>
            <?php if ($failed !== []): ?>
                <p class="mt-2 text-sm text-brand-crimson">Not available right now: <?= esc(implode(', ', $failed)) ?> (see the log).</p>
            <?php endif; ?>
        </header>

        <nav class="handbook-toc" aria-label="Contents">
            <ol>
                <?php foreach ($chapters as $id => $title): ?>
                    <li><a href="#<?= esc($id, 'attr') ?>"><?= esc($title) ?></a></li>
                <?php endforeach; ?>
            </ol>
        </nav>

        <?php foreach ($chapters as $id => $title): ?>
            <section class="handbook-chapter" id="<?= esc($id, 'attr') ?>">
                <h2><?= esc($title) ?></h2>
                <?= view('admin/handbook/' . $id, $data, ['saveData' => false]) ?>
            </section>
        <?php endforeach; ?>
    </div>
</section>
<?= $this->endSection() ?>
