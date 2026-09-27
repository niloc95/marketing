<?= $this->extend('layouts/public') ?>

<?php
/**
 * "Post a job" / "Request a service", for anyone (mode 'unlisted') or for a
 * signed-in listed business (mode 'listed').
 *
 * @var 'unlisted'|'listed'     $mode
 * @var 'job'|'service'         $kind
 * @var string                  $action
 * @var array<string,mixed>|null $listing
 */
$siteName = config('Directory')->siteName();
$isJob    = $kind === 'job';
$base     = $mode === 'listed' ? base_url('manage/jobs/new') : base_url('jobs/post');
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => ($isJob ? 'Post a job' : 'Request a service') . ' — ' . $siteName,
    'description' => 'Post a job or ask for a service on ' . $siteName . '. Free.',
    'canonical'   => base_url('jobs/post'),
    'robots'      => $mode === 'unlisted' && $isJob,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-2xl">
            <span class="eyebrow">Jobs board &middot; free</span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white"><?= $isJob ? 'Post a job' : 'Request a service' ?></h1>

            <div class="sort-toggle my-4" role="group" aria-label="What are you posting?">
                <?php foreach (['job' => 'I am hiring', 'service' => 'I need a service'] as $value => $label): ?>
                    <?php if ($value === $kind): ?>
                        <span class="near-chip is-active" aria-current="true"><?= esc($label) ?></span>
                    <?php else: ?>
                        <a class="near-chip" href="<?= esc($base . '?kind=' . $value, 'attr') ?>" rel="nofollow"><?= esc($label) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if ($mode === 'listed'): ?>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    Posting as <strong><?= esc($listing['display_name']) ?></strong>. Your post links to your profile and normally goes live straight away.
                </p>
            <?php else: ?>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    <?= $isJob
                        ? 'We check every post before it goes live, usually within one business day.'
                        : 'Local businesses listed on ' . esc($siteName) . ' can reply to you. We check every request before it goes live.' ?>
                    Already listed?
                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">Sign in to your profile</a>
                    and your posts normally go live straight away.
                </p>
            <?php endif; ?>

            <form method="post" action="<?= esc($action, 'attr') ?>">
                <?= csrf_field() ?>
                <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>
                <?= $this->include('jobs/_fields') ?>
                <button type="submit" class="btn btn-accent btn-block"><?= $isJob ? 'Post the job' : 'Post my request' ?></button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
