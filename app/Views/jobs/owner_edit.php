<?= $this->extend('layouts/public') ?>

<?php
/**
 * A listed business editing one of its own posts, from the dashboard.
 *
 * @var array<string,mixed> $post
 * @var array<string,mixed> $listing
 * @var string              $action
 */
$isJob = $post['kind'] === App\Models\JobPostModel::KIND_JOB;
?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Edit your post — ' . config('Directory')->siteName(), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-2xl">
            <a class="text-sm text-slate-500 hover:underline dark:text-slate-400" href="<?= base_url('manage/edit') ?>#jobs">&larr; Back to your dashboard</a>
            <span class="eyebrow mt-3">Edit <?= $isJob ? 'job' : 'request' ?></span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($post['title']) ?></h1>
            <p class="mb-2 text-sm text-slate-500 dark:text-slate-400">
                Posting as <strong><?= esc($listing['display_name']) ?></strong>. Changes to a live post show straight away.
            </p>

            <form method="post" action="<?= esc($action) ?>">
                <?= csrf_field() ?>
                <?= $this->include('jobs/_fields') ?>
                <button type="submit" class="btn btn-accent btn-block">Save changes</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
