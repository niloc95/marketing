<?= $this->extend('layouts/public') ?>

<?php
/**
 * One post: a vacancy (with JobPosting markup for Google for Jobs) or a
 * service request (noindex, answered by listed businesses only).
 *
 * @var array<string,mixed>          $post
 * @var App\Services\JobBoardService $svc
 * @var bool                         $isAdmin
 * @var array<string,mixed>|null     $responder the signed-in listing, if any
 * @var bool                         $hasResponded
 * @var int                          $maxResponses
 */
$siteName  = config('Directory')->siteName();
$isJob     = $post['kind'] === App\Models\JobPostModel::KIND_JOB;
$canonical = $svc->url($post);
$isLive    = $svc->isLive($post);
$place     = $svc->placeText($post);
$salary    = $isJob ? $svc->salaryText($post) : '';
$type      = $isJob ? $svc->employmentLabel($post) : '';
$employer  = $svc->hiringName($post);
$verified  = listing_is_verified_business(['verified_until' => $post['listing_verified_until'] ?? null]);
$v         = static fn (string $f) => (string) ($old[$f] ?? '');
$err       = static fn (string $f) => $errors[$f] ?? '';
$applyUrl  = $isJob ? safe_external_url($post['apply_url'] ?? '') : '';
$full      = ! $isJob && (int) $post['response_count'] >= $maxResponses;
$ownPost   = $responder !== null && (int) ($post['listing_id'] ?? 0) === (int) $responder['id'];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => $post['title'] . ($isJob ? ' — ' . $employer : '') . ' — ' . $siteName,
    'description' => seo_excerpt((string) $post['description'], 155),
    'canonical'   => $canonical,
    'image'       => ! empty($post['listing_logo']) ? listing_image_url((string) $post['listing_logo']) : '',
    // Service requests are a private person's errand, not something to rank.
    'robots'      => $isJob && $isLive,
    'schema'      => $svc->jobPostingSchema($post),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container max-w-3xl">
        <nav class="mb-3 text-sm text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
            <a class="hover:underline" href="<?= base_url('jobs') ?>">Jobs &amp; services</a>
            <span class="mx-1">/</span>
            <a class="hover:underline" href="<?= base_url('jobs?kind=' . $post['kind']) ?>"><?= $isJob ? 'Vacancies' : 'Services needed' ?></a>
        </nav>

        <?php if (! $isLive && $isAdmin): ?>
            <div class="alert alert-warning mb-4">Admin preview. This post is <strong><?= esc($post['status']) ?></strong> and not public.</div>
        <?php endif; ?>

        <div class="form-card">
            <span class="badge badge-category mb-2 gap-1 <?= $isJob ? 'cat-tint-blue' : 'cat-tint-amber' ?>">
                <?= lucide($isJob ? 'briefcase' : 'wrench', 'h-3 w-3 shrink-0') ?><?= $isJob ? 'Job' : 'Service needed' ?>
            </span>
            <h1 class="text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($post['title']) ?></h1>

            <p class="mt-1 text-slate-600 dark:text-slate-300">
                <?php if (! empty($post['listing_slug'])): ?>
                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(base_url('directory/' . $post['listing_slug']), 'attr') ?>"><?= esc($employer) ?></a>
                <?php elseif ($isJob || ! empty($post['company_name'])): ?>
                    <?= esc($employer) ?>
                <?php endif; ?>
                <?php if ($verified): ?>
                    <a class="badge badge-verified gap-1" href="<?= base_url('verified') ?>"><?= lucide('badge-check', 'h-3.5 w-3.5 shrink-0') ?>Verified Business</a>
                <?php endif; ?>
            </p>

            <?php
            $facts = array_filter([
                'Where'     => $place,
                'Type'      => $type,
                'Pay'       => $salary,
                'Budget'    => $isJob ? '' : (string) ($post['budget_text'] ?? ''),
                'Needed by' => ! $isJob && ! empty($post['needed_by']) ? date('j F Y', strtotime((string) $post['needed_by'])) : '',
                'Category'  => (string) ($post['category_name'] ?? ''),
                'Posted'    => ! empty($post['published_at']) ? date('j F Y', strtotime((string) $post['published_at'])) : '',
                'Closes'    => date('j F Y', strtotime((string) $post['valid_through'])),
            ], static fn ($v) => $v !== '');
            ?>
            <div class="mt-4">
                <?php foreach ($facts as $label => $value): ?>
                    <div class="kv"><span class="k"><?= esc($label) ?></span><span><?= esc($value) ?></span></div>
                <?php endforeach; ?>
            </div>

            <div class="listing-prose mt-5"><?= nl2br(esc((string) $post['description'])) ?></div>
        </div>

        <?php if ($isJob): ?>
            <div class="form-card mt-6" id="apply">
                <h2 class="mb-3 text-lg font-bold text-slate-900 dark:text-white">Apply</h2>
                <?php if ($applyUrl !== ''): ?>
                    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">This employer takes applications on their own site.</p>
                    <a class="btn btn-accent" href="<?= esc($applyUrl, 'attr') ?>" target="_blank" rel="noopener nofollow"><?= lucide('external-link', 'h-4 w-4 shrink-0') ?>Apply on the employer's site</a>
                <?php elseif (! empty($post['apply_email'])): ?>
                    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                        Your message goes straight to the employer by email, and they reply to you directly.
                        We do not keep a copy.
                    </p>
                    <form method="post" action="<?= base_url('jobs/' . (int) $post['id'] . '/apply') ?>">
                        <?= csrf_field() ?>
                        <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>
                        <div class="form-row">
                            <div class="field">
                                <label for="apply-name">Your name</label>
                                <input type="text" id="apply-name" name="name" required value="<?= esc($v('name'), 'attr') ?>">
                                <?php if ($err('name')): ?><div class="err"><?= esc($err('name')) ?></div><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="apply-email">Email address</label>
                                <input type="email" id="apply-email" name="email" required value="<?= esc($v('email'), 'attr') ?>">
                                <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="field">
                                <label for="apply-phone">Phone <span class="text-slate-400">(optional)</span></label>
                                <input type="tel" id="apply-phone" name="phone" value="<?= esc($v('phone'), 'attr') ?>">
                            </div>
                            <div class="field">
                                <label for="apply-cv">Link to your CV <span class="text-slate-400">(optional)</span></label>
                                <input type="url" id="apply-cv" name="cv_link" value="<?= esc($v('cv_link'), 'attr') ?>" placeholder="https://drive.google.com/…">
                                <div class="hint">A Google Drive, Dropbox or LinkedIn link. We do not take uploads.</div>
                                <?php if ($err('cv_link')): ?><div class="err"><?= esc($err('cv_link')) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="field">
                            <label for="apply-message">Message to the employer</label>
                            <textarea id="apply-message" name="message" rows="6" required placeholder="Your experience, and why you are a good fit."><?= esc($v('message')) ?></textarea>
                            <?php if ($err('message')): ?><div class="err"><?= esc($err('message')) ?></div><?php endif; ?>
                        </div>
                        <div class="field">
                            <label class="font-medium"><input type="checkbox" name="consent" value="1" required> I agree to <?= esc($siteName) ?> passing my name, contact details and message to <?= esc($employer) ?> for this vacancy. See our <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('privacy') ?>" target="_blank" rel="noopener">Privacy policy</a>.</label>
                            <?php if ($err('consent')): ?><div class="err"><?= esc($err('consent')) ?></div><?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-accent"><?= lucide('mail', 'h-4 w-4 shrink-0') ?>Send application</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="form-card mt-6" id="respond">
                <h2 class="mb-3 text-lg font-bold text-slate-900 dark:text-white">Can you do this job?</h2>
                <?php if ($ownPost): ?>
                    <p class="text-sm text-slate-500 dark:text-slate-400">This is your own request.</p>
                <?php elseif ($hasResponded): ?>
                    <p class="text-sm text-slate-500 dark:text-slate-400">You have replied. The customer will contact you directly if they are interested.</p>
                <?php elseif ($full): ?>
                    <p class="text-sm text-slate-500 dark:text-slate-400">This request already has <?= (int) $maxResponses ?> replies, the most it takes.</p>
                <?php elseif ($responder !== null && ($responder['status'] ?? '') === 'published'): ?>
                    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                        Replying as <strong><?= esc($responder['display_name']) ?></strong>. Your message, profile link, phone and email go to the customer,
                        who replies to you directly. Each request takes up to <?= (int) $maxResponses ?> replies.
                    </p>
                    <form method="post" action="<?= base_url('jobs/' . (int) $post['id'] . '/respond') ?>">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="respond-message">Your message</label>
                            <textarea id="respond-message" name="message" rows="5" required placeholder="When you could come out, a rough price, and anything you need to know."></textarea>
                        </div>
                        <button type="submit" class="btn btn-accent">Send reply</button>
                    </form>
                <?php else: ?>
                    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                        Businesses listed on <?= esc($siteName) ?> can reply to this request. Listing your business is free.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <a class="btn btn-accent" href="<?= base_url('add-listing') ?>">List your business free</a>
                        <a class="btn btn-ghost" href="<?= base_url('manage') ?>">I'm listed: sign in</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-info mt-6">
            <?php if ($isJob): ?>
                <strong>Never pay to apply.</strong> No genuine employer asks for a registration, training, uniform or placement fee.
            <?php else: ?>
                <strong>Stay safe.</strong> Agree the price in writing before any work starts, and never pay the full amount up front.
            <?php endif; ?>
        </div>

        <details class="disclosure mt-4">
            <summary class="disclosure-summary">Report this post</summary>
            <div class="disclosure-body">
                <form method="post" action="<?= base_url('jobs/' . (int) $post['id'] . '/report') ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="report-reason">What is wrong with it?</label>
                        <input type="text" id="report-reason" name="reason" maxlength="500" placeholder="e.g. asks for a fee, fake company, offensive">
                    </div>
                    <button type="submit" class="btn btn-ghost btn-xs text-brand-crimson">Send report</button>
                </form>
            </div>
        </details>
    </div>
</section>
<?= $this->endSection() ?>
