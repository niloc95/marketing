<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Reviews queue — ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\DirectoryReviewModel;
use App\Services\ReviewService;

/**
 * @var string                                $status
 * @var list<array<string,mixed>>             $rows
 * @var array<string,int>                     $counts
 * @var App\Models\DirectoryReviewReportModel $reports
 */

// Pending first: it is the only tab with work in it.
$tabs = [
    DirectoryReviewModel::STATUS_PENDING    => 'Awaiting review',
    DirectoryReviewModel::STATUS_PUBLISHED  => 'Live',
    DirectoryReviewModel::STATUS_UNVERIFIED => 'Email not confirmed',
    DirectoryReviewModel::STATUS_REJECTED   => 'Rejected',
    DirectoryReviewModel::STATUS_HIDDEN     => 'Hidden',
];

$link = static fn (string $s) => base_url('admin/reviews') . '?' . http_build_query(['status' => $s]);
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-2 text-xl font-bold text-slate-900 dark:text-white">Customer reviews</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Approve an honest review even when it is negative. Reject or hide one that is fake, from someone who was not a
            customer, abusive, defamatory on its face, or that shares private details. Nobody is emailed when you reject or hide.
        </p>

        <div class="tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="<?= $status === $key ? 'active' : '' ?>" href="<?= esc($link($key)) ?>"><?= esc($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>

        <?php if ($rows === []): ?>
            <div class="empty">Nothing in this view.</div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Review</th>
                            <th>Business</th>
                            <th>Reviewer</th>
                            <th>Written</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <?= rating_stars((float) $r['rating']) ?>
                                <?php // Flag reasons first: they are why the review is here. ?>
                                <?php if (! empty($r['flagged_reason'])): ?>
                                    <div class="text-xs text-brand-crimson"><?= esc($r['flagged_reason']) ?></div>
                                <?php endif; ?>
                                <?php if (! empty($r['reject_reason'])): ?>
                                    <div class="text-xs text-brand-crimson">Note: <?= esc($r['reject_reason']) ?></div>
                                <?php endif; ?>
                                <?php // The full text, because the decision is about the words. ?>
                                <p class="review-body max-w-prose"><?= esc($r['body']) ?></p>
                                <?php if (! empty($r['owner_reply'])): ?>
                                    <div class="review-reply max-w-prose">
                                        <strong>Owner's reply</strong>
                                        <p class="review-reply-body"><?= esc($r['owner_reply']) ?></p>
                                        <form method="post" action="<?= base_url('admin/reviews/' . $r['id'] . '/remove-reply') ?>"
                                              data-confirm="Remove the owner's reply? The review stays." class="mt-1">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-ghost btn-xs text-brand-crimson">Remove reply</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                <?php if ((int) $r['report_count'] > 0): ?>
                                    <details class="disclosure mt-1">
                                        <summary class="disclosure-summary"><?= (int) $r['report_count'] ?> report<?= (int) $r['report_count'] === 1 ? '' : 's' ?></summary>
                                        <div class="disclosure-body">
                                            <?php foreach ($reports->forReview((int) $r['id']) as $rep): ?>
                                                <p class="hint"><?= esc(local_datetime($rep['created_at'])) ?><?= ! empty($rep['by_owner']) ? ' (the owner)' : '' ?>: <?= esc($rep['reason'] ?: '(no reason given)') ?></p>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= esc(base_url('directory/' . ($r['listing_slug'] ?? ''))) ?>#reviews" target="_blank" rel="noopener"><?= esc($r['listing_name'] ?? '') ?></a>
                            </td>
                            <td>
                                <?= esc($r['reviewer_name'] ?? '(wiped)') ?>
                                <div class="text-xs text-slate-500 dark:text-slate-400">Shown as <?= esc(ReviewService::displayName($r['reviewer_name'] ?? null)) ?></div>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($r['reviewer_email'] ?? '') ?></div>
                            </td>
                            <td class="whitespace-nowrap"><?= esc(local_datetime($r['created_at'] ?? null)) ?></td>
                            <td>
                                <div class="actions">
                                    <?php if ($r['status'] === DirectoryReviewModel::STATUS_PENDING): ?>
                                        <form method="post" action="<?= base_url('admin/reviews/' . $r['id'] . '/approve') ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-primary btn-xs">Approve</button>
                                        </form>
                                        <?php // A review that was live before reports sent it back is
                                              // hidden, not rejected: it was already public once. ?>
                                        <?php $action = empty($r['published_at']) ? 'reject' : 'hide'; ?>
                                        <form method="post" action="<?= base_url('admin/reviews/' . $r['id'] . '/' . $action) ?>" class="flex items-center gap-1">
                                            <?= csrf_field() ?>
                                            <input type="text" name="reason" placeholder="Note for the record" class="text-xs" size="22">
                                            <button class="btn btn-ghost btn-xs text-brand-crimson"><?= $action === 'reject' ? 'Reject' : 'Hide' ?></button>
                                        </form>
                                    <?php elseif ($r['status'] === DirectoryReviewModel::STATUS_PUBLISHED): ?>
                                        <form method="post" action="<?= base_url('admin/reviews/' . $r['id'] . '/hide') ?>" class="flex items-center gap-1"
                                              data-confirm="Take this review off the profile?">
                                            <?= csrf_field() ?>
                                            <input type="text" name="reason" placeholder="Note for the record" class="text-xs" size="22">
                                            <button class="btn btn-ghost btn-xs text-brand-crimson">Hide</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?= $pager->links() ?>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
