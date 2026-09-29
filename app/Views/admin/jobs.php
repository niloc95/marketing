<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Jobs queue — ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\JobPostModel;

/**
 * @var string                          $status
 * @var list<array<string,mixed>>       $rows
 * @var array<string,int>               $counts
 * @var App\Services\JobBoardService    $svc
 * @var App\Models\JobReportModel       $reports
 */

// Pending first: it is the only tab with work in it.
$tabs = [
    JobPostModel::STATUS_PENDING    => 'Awaiting review',
    JobPostModel::STATUS_PUBLISHED  => 'Live',
    JobPostModel::STATUS_UNVERIFIED => 'Email not confirmed',
    JobPostModel::STATUS_REJECTED   => 'Rejected',
    'ended'                         => 'Closed / expired',
];

$link = static fn (string $s) => base_url('admin/jobs') . '?' . http_build_query(['status' => $s]);

$prettyDate = static function (?string $date): string {
    $ts = $date ? strtotime($date) : false;

    return $ts === false ? '—' : date('j M Y', $ts);
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Jobs board</h1>

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
                            <th>Post</th>
                            <th>Poster</th>
                            <th>Posted</th>
                            <th>Closes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <span class="pill"><?= $r['kind'] === JobPostModel::KIND_JOB ? 'Job' : 'Service request' ?></span>
                                <strong><?= esc($r['title']) ?></strong>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc(trim(($r['city'] ?? '') . ', ' . ($r['province'] ?? ''), ', ')) ?></div>
                                <?php // Flag reasons first: they are why the post is here. ?>
                                <?php if (! empty($r['flagged_reason'])): ?>
                                    <div class="text-xs text-brand-crimson"><?= esc($r['flagged_reason']) ?></div>
                                <?php endif; ?>
                                <?php if (! empty($r['reject_reason'])): ?>
                                    <div class="text-xs text-brand-crimson">Rejected: <?= esc($r['reject_reason']) ?></div>
                                <?php endif; ?>
                                <?php if ((int) $r['report_count'] > 0): ?>
                                    <details class="disclosure mt-1">
                                        <summary class="disclosure-summary"><?= (int) $r['report_count'] ?> report<?= (int) $r['report_count'] === 1 ? '' : 's' ?></summary>
                                        <div class="disclosure-body">
                                            <?php foreach ($reports->forPost((int) $r['id']) as $rep): ?>
                                                <p class="hint"><?= esc($prettyDate($rep['created_at'])) ?>: <?= esc($rep['reason'] ?: '(no reason given)') ?></p>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                                <?php // The full text, because the decision is about the words. ?>
                                <details class="disclosure mt-1">
                                    <summary class="disclosure-summary">Read the post</summary>
                                    <div class="disclosure-body text-sm"><?= nl2br(esc($r['description'])) ?></div>
                                </details>
                            </td>
                            <td>
                                <?php if (! empty($r['listing_id'])): ?>
                                    <span class="pill pill-verified">Listed</span>
                                    <a href="<?= esc(base_url('directory/' . ($r['listing_slug'] ?? ''))) ?>" target="_blank" rel="noopener"><?= esc($r['listing_name'] ?? '') ?></a>
                                <?php else: ?>
                                    <span class="pill pill-pending">Not listed</span>
                                    <?= esc($r['company_name'] ?: '') ?>
                                    <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($r['poster_name'] ?? '') ?></div>
                                <?php endif; ?>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($r['poster_email'] ?? '') ?></div>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($r['poster_phone'] ?? '') ?></div>
                            </td>
                            <td><?= esc($prettyDate($r['created_at'] ?? null)) ?></td>
                            <td><?= esc($prettyDate($r['valid_through'] ?? null)) ?></td>
                            <td>
                                <div class="actions">
                                    <?php if ($r['status'] === JobPostModel::STATUS_PENDING): ?>
                                        <form method="post" action="<?= base_url('admin/jobs/' . $r['id'] . '/approve') ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-primary btn-xs">Approve</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($r['status'], [JobPostModel::STATUS_PENDING, JobPostModel::STATUS_PUBLISHED], true)): ?>
                                        <form method="post" action="<?= base_url('admin/jobs/' . $r['id'] . '/reject') ?>" class="flex items-center gap-1">
                                            <?= csrf_field() ?>
                                            <input type="text" name="reason" placeholder="Reason the poster will see" required class="text-xs" size="26">
                                            <button class="btn btn-ghost btn-xs text-brand-crimson">Reject</button>
                                        </form>
                                        <form method="post" action="<?= base_url('admin/jobs/' . $r['id'] . '/close') ?>"
                                              data-confirm="Take this post down without emailing the poster?">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-ghost btn-xs">Close</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($r['status'] !== JobPostModel::STATUS_UNVERIFIED): ?>
                                        <a class="btn btn-ghost btn-xs" href="<?= esc($svc->url($r)) ?>" target="_blank" rel="noopener">View</a>
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
