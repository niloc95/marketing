<?= $this->extend('layouts/public') ?>

<?php
/**
 * Every newsletter page in one view (Newsletter controller): the signup form,
 * "check your inbox", the Confirm button, unsubscribe, and a dead link.
 *
 * @var string $state     form | sent | confirm | confirmed | unsubscribe | unsubscribed | invalid
 * @var string $brand     the list's name: "WebScheduler Local", or "WebScheduler" for the brand site's form
 * @var bool   $fromBrand the brand site's form, so its people get a way back
 * @var string $token
 */
$siteName = config('Directory')->siteName();
$old      = flash('old');
$link     = 'font-medium text-primary-500 dark:text-primary-300 hover:underline';
?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Newsletter | ' . $siteName,
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow"><?= esc($brand) ?> newsletter</span>

            <?php if ($state === 'form'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Stay in the know</h1>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    Occasional updates from <?= esc($brand) ?>. We send one confirmation email first,
                    and you can unsubscribe at any time.
                </p>
                <form method="post" action="<?= esc(base_url('newsletter')) ?>">
                    <?php if ($fromBrand): ?>
                        <input type="hidden" name="source" value="brand">
                    <?php endif; ?>
                    <div class="field">
                        <label for="newsletter_email">Email address</label>
                        <input type="email" id="newsletter_email" name="email" required autocomplete="email"
                               value="<?= esc(is_array($old) ? (string) ($old['email'] ?? '') : '', 'attr') ?>">
                    </div>
                    <button type="submit" class="btn btn-accent btn-block mt-2">Subscribe</button>
                </form>
            <?php elseif ($state === 'sent'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Almost there</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Check your inbox for an email from us and press the button in it to confirm your subscription.
                    You are not on the list until you do. Nothing there? Look in your spam folder.
                </p>
            <?php elseif ($state === 'confirm'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Confirm your subscription</h1>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    Press the button to receive <?= esc($brand) ?> updates by email. You can unsubscribe at any time
                    from the link in every email.
                </p>
                <form method="post" action="<?= esc(base_url('newsletter/confirm/' . $token)) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-accent btn-block">Confirm my subscription</button>
                </form>
            <?php elseif ($state === 'confirmed'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">You're subscribed</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Thank you. Every email we send has an unsubscribe link at the bottom.
                </p>
            <?php elseif ($state === 'unsubscribe'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Unsubscribe?</h1>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    This stops <?= esc($brand) ?> newsletter emails to this address.
                    Emails about a business profile you own are not affected.
                </p>
                <form method="post" action="<?= esc(base_url('newsletter/unsubscribe/' . $token)) ?>">
                    <button type="submit" class="btn btn-accent btn-block">Unsubscribe</button>
                </form>
            <?php elseif ($state === 'unsubscribed'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">You're unsubscribed</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    We won't send you the newsletter again. Changed your mind?
                    <a class="<?= $link ?>" href="<?= base_url('newsletter') ?>">Subscribe again</a>.
                </p>
            <?php else: ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">This link isn't valid</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    It may have expired or been copied incompletely.
                    <a class="<?= $link ?>" href="<?= base_url('newsletter') ?>">Sign up again</a> and we will send you a fresh one.
                </p>
            <?php endif; ?>

            <?php if ($fromBrand && in_array($state, ['sent', 'confirmed', 'unsubscribed'], true)): ?>
                <p class="mt-6 text-sm">
                    <a class="<?= $link ?>" href="https://webscheduler.co.za/">Back to webscheduler.co.za</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
