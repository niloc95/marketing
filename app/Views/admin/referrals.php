<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Referrals — ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\DirectoryReferralModel;

/**
 * @var string                        $status
 * @var list<array<string,mixed>>     $rows
 * @var array<string,int>             $counts
 * @var App\Services\ReferralService  $svc
 */

$tabs = [
    DirectoryReferralModel::STATUS_PENDING   => 'To review',
    DirectoryReferralModel::STATUS_INVITED   => 'Invited',
    DirectoryReferralModel::STATUS_LISTED    => 'Listed',
    DirectoryReferralModel::STATUS_DISMISSED => 'Dismissed',
];

$link = static fn (string $s) => base_url('admin/referrals') . '?' . http_build_query(['status' => $s]);

$prettyDate = static function (?string $date): string {
    $ts = $date ? strtotime($date) : false;

    return $ts === false ? '—' : date('j M Y', $ts);
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Recommended businesses</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Invite sends the business one email with a pre-filled signup link. Nothing else here contacts them.
        </p>

        <div class="tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="<?= $status === $key ? 'active' : '' ?>" href="<?= esc($link($key), 'attr') ?>"><?= esc($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>

        <?php if ($rows === []): ?>
            <div class="empty">Nothing in this view.</div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Contact</th>
                            <th>Recommended by</th>
                            <th>Received</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $duplicate = $r['status'] === DirectoryReferralModel::STATUS_PENDING ? $svc->likelyDuplicate($r) : null;
                        $blocker   = $r['status'] === DirectoryReferralModel::STATUS_PENDING ? $svc->inviteBlocker($r) : null;
                        $website   = safe_external_url($r['website'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <strong><?= esc($r['business_name']) ?></strong>
                                <div class="text-xs text-slate-500 dark:text-slate-400">
                                    <?= esc(trim(($r['category_name'] ?? '') . ' · ' . trim(($r['city'] ?? '') . ', ' . ($r['province'] ?? ''), ', '), ' ·')) ?>
                                </div>
                                <?php if ($duplicate !== null): ?>
                                    <div class="text-xs text-brand-crimson">
                                        Possibly already listed:
                                        <a href="<?= esc(base_url('admin/edit/' . $duplicate['id']), 'attr') ?>"><?= esc($duplicate['display_name']) ?></a>
                                        (<?= esc($duplicate['status']) ?>)
                                    </div>
                                <?php endif; ?>
                                <?php if (! empty($r['listing_id'])): ?>
                                    <div class="text-xs">
                                        <span class="pill pill-verified">Listed</span>
                                        <a href="<?= esc(base_url('directory/' . ($r['listing_slug'] ?? '')), 'attr') ?>" target="_blank" rel="noopener"><?= esc($r['listing_name'] ?? '') ?></a>
                                    </div>
                                <?php endif; ?>
                                <?php if (! empty($r['note'])): ?>
                                    <details class="disclosure mt-1">
                                        <summary class="disclosure-summary">Why they recommend it</summary>
                                        <div class="disclosure-body text-sm"><?= nl2br(esc($r['note'])) ?></div>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs">
                                <?php if (! empty($r['pruned_at'])): ?>
                                    <span class="text-slate-400">Wiped <?= esc($prettyDate($r['pruned_at'])) ?></span>
                                <?php endif; ?>
                                <div><?= esc($r['business_email'] ?? '') ?></div>
                                <div><?= esc($r['business_phone'] ?? '') ?></div>
                                <?php if ($website !== ''): ?>
                                    <div><a href="<?= esc($website, 'attr') ?>" target="_blank" rel="noopener nofollow"><?= esc(parse_url($website, PHP_URL_HOST) ?: $website) ?></a></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-xs">
                                <div><?= esc($r['referrer_name'] ?: '(no name)') ?></div>
                                <div class="text-slate-500 dark:text-slate-400"><?= esc($r['referrer_email'] ?? '') ?></div>
                                <div class="text-slate-500 dark:text-slate-400"><?= esc(DirectoryReferralModel::RELATIONSHIPS[$r['relationship']] ?? $r['relationship']) ?></div>
                                <?php if ((int) $r['notify_referrer'] === 1): ?>
                                    <div class="text-slate-500 dark:text-slate-400">Wants to hear when listed</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= esc($prettyDate($r['created_at'] ?? null)) ?>
                                <?php if (! empty($r['invited_at'])): ?>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">Invited <?= esc($prettyDate($r['invited_at'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($r['status'] === DirectoryReferralModel::STATUS_PENDING): ?>
                                        <?php if ($blocker === null): ?>
                                            <form method="post" action="<?= base_url('admin/referrals/' . $r['id'] . '/invite') ?>"
                                                  data-confirm="Email <?= esc($r['business_email'], 'attr') ?> an invitation to list? They get one email only.">
                                                <?= csrf_field() ?>
                                                <button class="btn btn-primary btn-xs">Invite</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500 dark:text-slate-400"><?= esc($blocker) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (in_array($r['status'], [DirectoryReferralModel::STATUS_PENDING, DirectoryReferralModel::STATUS_INVITED], true)): ?>
                                        <form method="post" action="<?= base_url('admin/referrals/' . $r['id'] . '/dismiss') ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-ghost btn-xs">Dismiss</button>
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
