<?= $this->extend('layouts/public') ?>

<?php
/**
 * A post that has closed or expired. Served with 410 Gone, which is how Google
 * for Jobs learns to drop a vacancy quickly; noindex says the same to anything
 * that reads the page anyway.
 *
 * @var array<string,mixed>          $post
 * @var App\Services\JobBoardService $svc
 */
$siteName = config('Directory')->siteName();
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'This post has closed | ' . $siteName,
    'description' => 'This post on ' . $siteName . ' is no longer open.',
    'canonical'   => $svc->url($post),
    'robots'      => 'noindex, follow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow">Closed</span>
            <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($post['title']) ?></h1>
            <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
                This <?= $post['kind'] === 'job' ? 'vacancy' : 'request' ?> is no longer open.
            </p>
            <a class="btn btn-accent" href="<?= base_url('jobs?kind=' . $post['kind']) ?>">See what's open now</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
