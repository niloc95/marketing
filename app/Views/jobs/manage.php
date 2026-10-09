<?= $this->extend('layouts/public') ?>

<?php
/**
 * An unlisted poster's own post: status, close, renew, edit. Reached only
 * through the emailed manage link (Jobs::manageRedeem).
 *
 * @var array<string,mixed>          $post
 * @var App\Services\JobBoardService $svc
 * @var bool                         $canRenew
 */
use App\Models\JobPostModel;

$status = (string) $post['status'];
$labels = [
    JobPostModel::STATUS_PENDING   => ['Waiting for review', 'pill-pending'],
    JobPostModel::STATUS_PUBLISHED => ['Live', 'pill-published'],
    JobPostModel::STATUS_REJECTED  => ['Not published', 'pill-rejected'],
    JobPostModel::STATUS_CLOSED    => ['Closed', 'pill-unpublished'],
    JobPostModel::STATUS_EXPIRED   => ['Expired', 'pill-unpublished'],
];
[$label, $pill] = $labels[$status] ?? [$status, ''];
$editable = in_array($status, [JobPostModel::STATUS_PENDING, JobPostModel::STATUS_PUBLISHED], true);
?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Manage your post | ' . config('Directory')->siteName(), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card">
            <span class="eyebrow">Your post</span>
            <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($post['title']) ?></h1>
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                <span class="pill <?= esc($pill, 'attr') ?>"><?= esc($label) ?></span>
                Closes <?= esc(date('j F Y', strtotime((string) $post['valid_through']))) ?>.
                <?php if ($post['kind'] === JobPostModel::KIND_SERVICE): ?>
                    <?= (int) $post['response_count'] ?> repl<?= (int) $post['response_count'] === 1 ? 'y' : 'ies' ?> so far.
                <?php endif; ?>
            </p>
            <?php if ($status === JobPostModel::STATUS_REJECTED && ! empty($post['reject_reason'])): ?>
                <div class="alert alert-error mb-4">Reason: <?= esc($post['reject_reason']) ?></div>
            <?php endif; ?>

            <div class="flex flex-wrap gap-2">
                <?php if ($status === JobPostModel::STATUS_PUBLISHED): ?>
                    <a class="btn btn-ghost" href="<?= esc($svc->url($post)) ?>">View post</a>
                <?php endif; ?>
                <?php if ($canRenew): ?>
                    <form method="post" action="<?= base_url('jobs/manage/renew') ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-accent">Renew for <?= (int) config('JobBoard')->defaultDays ?> days</button>
                    </form>
                <?php endif; ?>
                <?php if ($editable): ?>
                    <form method="post" action="<?= base_url('jobs/manage/close') ?>" data-confirm="Close this post now? It comes down straight away.">
                        <?= csrf_field() ?>
                        <button class="btn btn-ghost text-brand-crimson">Close post</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($editable): ?>
                <form method="post" action="<?= base_url('jobs/manage') ?>" class="mt-4">
                    <?= csrf_field() ?>
                    <?= $this->include('jobs/_fields') ?>
                    <button type="submit" class="btn btn-primary btn-block">Save changes</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
