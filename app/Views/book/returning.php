<?php
/**
 * The emailed "continue my booking" link (Book::returningLink, T37). Opening
 * it changes nothing, because mail scanners open every link; the button
 * posts, which uses the link once and goes back to the chosen time.
 *
 * @var array<string,mixed>  $listing
 * @var string               $action
 * @var array<string,string> $selection service_id, provider_id, place, start
 */
$siteName = config('Directory')->siteName();
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Continue your booking | ' . $siteName, 'description' => 'Continue booking with ' . $listing['display_name'] . '.', 'canonical' => base_url('directory/' . $listing['slug'] . '/book'), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <h1 class="mb-2 text-2xl font-extrabold text-slate-900 dark:text-white">Continue your booking</h1>
            <p class="mb-6 text-sm text-slate-600 dark:text-slate-300">With <?= esc($listing['display_name']) ?>. We fill in your details from your last booking, so you only need to check them.</p>
            <form method="post" action="<?= esc($action, 'attr') ?>">
                <?= csrf_field() ?>
                <?php foreach ($selection as $name => $value): ?>
                    <?php if ($value !== ''): ?><input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>"><?php endif ?>
                <?php endforeach ?>
                <button type="submit" class="btn btn-accent btn-block">Continue my booking</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
