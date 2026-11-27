<?= $this->extend('layouts/public') ?>

<?php
/**
 * The claim page (Claim controller, ClaimService). Opening it changes
 * nothing; each choice is a POST from here.
 *
 * @var array<string,mixed>|null $listing
 * @var array<string,mixed>|null $public   listing_public_view($listing)
 * @var string                   $category
 * @var string                   $token
 * @var string                   $state    ask | claimed | reported | removed | invalid
 * @var string|null              $error
 * @var string                   $source   where the details came from
 * @var string                   $greeting
 */
$siteName = config('Directory')->siteName();
$link     = 'font-medium text-primary-500 dark:text-primary-300 hover:underline';
$noun     = $listing !== null ? listing_noun($listing) : 'practice';
$address  = $public !== null ? array_values(array_filter(array_map('trim', [
    (string) ($public['address_line'] ?? ''),
    (string) ($public['address_line_2'] ?? ''),
    (string) ($public['suburb'] ?? ''),
    (string) ($public['city'] ?? ''),
    (string) ($public['province'] ?? ''),
]), static fn (string $p): bool => $p !== '')) : [];
?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Claim your profile | ' . $siteName,
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow">Your profile</span>

            <?php if ($state === 'invalid' || $listing === null): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">This link no longer works</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    It may have expired, or been copied incompletely. If the profile is yours you can still
                    <a class="<?= $link ?>" href="<?= base_url('business/sign-in') ?>">sign in with your email</a>,
                    no password needed, or <a class="<?= $link ?>" href="<?= base_url('contact') ?>">contact us</a>.
                </p>

            <?php elseif ($state === 'claimed'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">This profile is already claimed</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <?= esc($listing['display_name']) ?> already has an owner on <?= esc($siteName) ?>.
                    <a class="<?= $link ?>" href="<?= base_url('business/sign-in') ?>">Sign in with your email</a> to manage it.
                </p>

            <?php elseif ($state === 'reported'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Thank you, we will look at it</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    We have your note about <?= esc($listing['display_name']) ?> and have stopped our emails about the profile
                    while we check it. If we need more from you we will reply by email.
                </p>

            <?php elseif ($state === 'removed'): ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">The profile is removed</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <?= esc($listing['display_name']) ?> is no longer shown on <?= esc($siteName) ?>, and we will not email you about it again.
                    If you change your mind, <a class="<?= $link ?>" href="<?= base_url('contact') ?>">contact us</a>.
                </p>

            <?php else: ?>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Is this your <?= esc($noun) ?>?</h1>
                <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
                    We created this profile on <?= esc($siteName) ?> from <?= esc($source) ?>. Please check it, then choose below.
                    Nothing changes until you do.
                </p>

                <?php if ($error !== null): ?>
                    <div class="mb-5 rounded-surface bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300" role="alert"><?= esc($error) ?></div>
                <?php endif; ?>

                <div class="mb-6 rounded-surface bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                    <p class="text-base font-bold text-slate-900 dark:text-white"><?= esc($listing['display_name']) ?></p>
                    <?php if ($category !== ''): ?>
                        <p class="text-slate-500 dark:text-slate-400"><?= esc($category) ?></p>
                    <?php endif; ?>
                    <dl class="mt-3 space-y-1 text-slate-600 dark:text-slate-300">
                        <?php if ($address !== []): ?>
                            <div><dt class="inline font-medium">Address:</dt> <dd class="inline"><?= esc(implode(', ', $address)) ?></dd></div>
                        <?php endif; ?>
                        <?php if (trim((string) ($listing['phone'] ?? '')) !== ''): ?>
                            <div><dt class="inline font-medium">Phone:</dt> <dd class="inline"><?= esc((string) $listing['phone']) ?></dd></div>
                        <?php endif; ?>
                        <?php if (empty($listing['email_public'])): ?>
                            <div><dt class="inline font-medium">Email:</dt> <dd class="inline">not shown on the profile; visitors write through a private form</dd></div>
                        <?php endif; ?>
                    </dl>
                    <p class="mt-3"><a class="<?= $link ?>" href="<?= esc(listing_url($listing)) ?>" target="_blank" rel="noopener">See the full profile with hours</a></p>
                </div>

                <form method="post" action="<?= esc(base_url('claim/' . $token)) ?>" data-submit-once>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-accent btn-block">Yes, this is my <?= esc($noun) ?></button>
                    <p class="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">Claiming costs nothing and no subscription is needed. You can then check and change every detail.</p>
                </form>

                <details class="faq-item mt-6">
                    <summary>Something is wrong</summary>
                    <form method="post" action="<?= esc(base_url('claim/' . $token . '/report')) ?>" class="faq-answer" data-submit-once>
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="claim-note">What should we fix?</label>
                            <textarea id="claim-note" name="note" rows="4" maxlength="<?= \App\Services\Communications\ClaimService::NOTE_MAX ?>" placeholder="For example: we moved, the phone number changed, or this is not my practice."></textarea>
                        </div>
                        <button type="submit" class="btn btn-block">Send to <?= esc($siteName) ?></button>
                    </form>
                </details>

                <details class="faq-item mt-3">
                    <summary>Remove my profile</summary>
                    <form method="post" action="<?= esc(base_url('claim/' . $token . '/remove')) ?>" class="faq-answer" data-submit-once>
                        <?= csrf_field() ?>
                        <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">The profile stops showing at once and we will not email you about it again.</p>
                        <label class="mb-3 flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="confirm_remove" value="1" required>
                            <span>I am responsible for <?= esc($listing['display_name']) ?> and want its profile removed.</span>
                        </label>
                        <button type="submit" class="btn btn-block">Remove the profile</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
