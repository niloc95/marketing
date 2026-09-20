<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Verification queue — ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\DirectoryVerificationDocumentModel;
use App\Models\DirectoryVerificationModel;
use App\Models\DirectoryVerificationSubmissionModel;

$documents   = new DirectoryVerificationDocumentModel();
$submissions = new DirectoryVerificationSubmissionModel();

// First twelve characters of the digest, which is plenty to compare two by eye;
// the full value is on the title attribute for anyone who needs to paste it.
$digest = static function (?string $sha): string {
    $sha = (string) $sha;

    return $sha === '' ? '' : substr($sha, 0, 12);
};

// Submitted first: it is the only tab with work in it. The rest are for looking
// things up, not for getting through.
$tabs = [
    DirectoryVerificationModel::STATE_SUBMITTED => 'Awaiting review',
    DirectoryVerificationModel::STATE_APPROVED  => 'Awaiting payment',
    DirectoryVerificationModel::STATE_ACTIVE    => 'Active',
    DirectoryVerificationModel::STATE_LAPSED    => 'Lapsed',
    DirectoryVerificationModel::STATE_REJECTED  => 'Rejected',
];

$link = static fn (string $state, $page = '') => base_url('admin/verifications')
    . '?' . http_build_query(array_filter(['state' => $state, 'page' => $page], static fn ($v) => $v !== '' && $v !== null));

$prettyDate = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);

    return $ts === false ? '—' : date('j M Y', $ts);
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-slate-900 dark:text-white">
                Verified Business
                <span class="text-sm font-normal text-slate-500 dark:text-slate-400">R<?= esc($amount) ?>/month</span>
            </h1>
        </div>

        <div class="tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="<?= $state === $key ? 'active' : '' ?>" href="<?= esc($link($key), 'attr') ?>"><?= esc($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
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
                            <th>Plan</th>
                            <th>Documents</th>
                            <th>Submitted</th>
                            <th>Paid through</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <strong><?= esc($r['listing_name'] ?? '—') ?></strong>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($r['listing_email'] ?? '') ?></div>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($r['listing_city'] ?? '') ?></div>
                                <?php // An application whose email was never confirmed is not worth
                                      // reviewing yet: nobody has proved they control the address the
                                      // approval mail would go to. ?>
                                <?php if (empty($r['listing_email_verified'])): ?>
                                    <span class="pill pill-pending" title="The owner has not clicked their verification email yet">email unconfirmed</span>
                                <?php endif; ?>
                                <?php if (! empty($r['rejection_reason'])): ?>
                                    <div class="text-xs text-brand-crimson">Rejected: <?= esc($r['rejection_reason']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php // Which of the two subscriptions this row is. It changes
                                      // what approving or revoking it DOES — revoke a badge and a
                                      // decoration goes away, revoke an International Listing and
                                      // the business's profile comes down — so an admin has to be
                                      // able to see it without opening the row. ?>
                                <?php if (($r['plan'] ?? '') === \App\Models\DirectoryVerificationModel::PLAN_INTERNATIONAL): ?>
                                    <span class="pill">International</span>
                                    <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($r['listing_country'] ?? '') ?></div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">publication, not a badge</div>
                                <?php else: ?>
                                    <span class="pill pill-verified">Badge</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $docs = $documents->forVerification((int) $r['id']); ?>
                                <?php if ($docs === []): ?>
                                    —
                                <?php else: ?>
                                    <div class="actions">
                                    <?php foreach ($docs as $doc): ?>
                                        <?php // target="_blank", never an iframe: SecureHeaders sets
                                              // X-Frame-Options DENY site-wide and a review screen is
                                              // not a good reason to carve an exception into it. ?>
                                        <a class="btn btn-ghost btn-xs"
                                           href="<?= base_url('admin/verification/document/' . $doc['id']) ?>"
                                           target="_blank" rel="noopener"
                                           title="<?= esc(trim(($doc['original_name'] ?? '') . "\n" . ($doc['sha256'] ?? '')), 'attr') ?>">
                                            <?= $doc['kind'] === DirectoryVerificationDocumentModel::KIND_REGISTRATION ? 'Registration' : 'Owner ID' ?>
                                        </a>
                                    <?php endforeach; ?>
                                    </div>
                                    <?php if ($digest($docs[0]['sha256'] ?? '') !== ''): ?>
                                        <div class="hint font-mono"><?= esc($digest($docs[0]['sha256'])) ?>…</div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php
                                // Earlier applications. Only rendered when there
                                // are any, so a listing that applied once looks
                                // exactly as it did before this existed.
                                $earlier = $documents->supersededBySubmission((int) $r['id']);
                                ?>
                                <?php if ($earlier !== []): ?>
                                    <?php $attempts = []; ?>
                                    <?php foreach ($submissions->forVerification((int) $r['id']) as $sub): ?>
                                        <?php $attempts[(int) $sub['id']] = $sub; ?>
                                    <?php endforeach; ?>
                                    <details class="disclosure mt-2">
                                        <summary class="disclosure-summary">
                                            <?= count($earlier) ?> earlier application<?= count($earlier) === 1 ? '' : 's' ?>
                                        </summary>
                                        <div class="disclosure-body">
                                            <?php foreach ($earlier as $submissionId => $oldDocs): ?>
                                                <?php $sub = $attempts[$submissionId] ?? null; ?>
                                                <p class="hint mb-1">
                                                    <?php if ($sub !== null): ?>
                                                        Attempt <?= (int) $sub['attempt_no'] ?> ·
                                                        <?= esc(DirectoryVerificationSubmissionModel::OUTCOME_LABELS[$sub['outcome']] ?? $sub['outcome']) ?>
                                                        <?= $sub['reviewed_by'] ? ' by ' . esc($sub['reviewed_by']) : '' ?>
                                                        <?= $sub['reviewed_at'] ? ' on ' . esc($prettyDate($sub['reviewed_at'])) : '' ?>
                                                        <?php if (! empty($sub['rejection_reason'])): ?>
                                                            <br><em><?= esc($sub['rejection_reason']) ?></em>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        An earlier application
                                                    <?php endif; ?>
                                                </p>
                                                <div class="actions">
                                                    <?php foreach ($oldDocs as $doc): ?>
                                                        <a class="btn btn-ghost btn-xs"
                                                           href="<?= base_url('admin/verification/document/' . $doc['id']) ?>"
                                                           target="_blank" rel="noopener"
                                                           title="<?= esc(trim(($doc['original_name'] ?? '') . "\n" . ($doc['sha256'] ?? '')), 'attr') ?>">
                                                            <?= $doc['kind'] === DirectoryVerificationDocumentModel::KIND_REGISTRATION ? 'Registration' : 'Owner ID' ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?= esc($prettyDate($r['submitted_at'] ?? null)) ?></td>
                            <td><?= esc($prettyDate($r['paid_until'] ?? null)) ?></td>
                            <td>
                                <div class="actions">
                                    <?php if ($r['state'] === DirectoryVerificationModel::STATE_SUBMITTED): ?>
                                        <form method="post" action="<?= base_url('admin/verifications/' . $r['id'] . '/approve') ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-primary btn-xs">Approve</button>
                                        </form>
                                        <?php // The reason is required and goes to the owner verbatim,
                                              // so it is a field here rather than a confirm dialog. ?>
                                        <form method="post" action="<?= base_url('admin/verifications/' . $r['id'] . '/reject') ?>" class="flex items-center gap-1">
                                            <?= csrf_field() ?>
                                            <input type="text" name="reason" placeholder="Reason the owner will see" required
                                                   class="text-xs" size="28">
                                            <button class="btn btn-ghost btn-xs text-brand-crimson">Reject</button>
                                        </form>
                                    <?php elseif ($r['state'] === DirectoryVerificationModel::STATE_ACTIVE): ?>
                                        <form method="post" action="<?= base_url('admin/verifications/' . $r['id'] . '/revoke') ?>"
                                              data-confirm="Remove this badge now? You must also cancel the subscription in PayFast — this does not do that.">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-ghost btn-xs text-brand-crimson">Revoke badge</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php // Manual activation: the EFT path, and the way out when a
                                          // PayFast notification is lost. Offered on anything already
                                          // reviewed — approved, lapsed, or active needing another
                                          // month — but never on a row still awaiting review, which
                                          // the service refuses anyway. ?>
                                    <?php if ($r['state'] !== DirectoryVerificationModel::STATE_SUBMITTED): ?>
                                        <form method="post" action="<?= base_url('admin/verifications/' . $r['id'] . '/activate') ?>"
                                              class="flex items-center gap-1"
                                              data-confirm="Activate this badge without PayFast? Only do this once payment has actually arrived.">
                                            <?= csrf_field() ?>
                                            <?php // w-16, not an inline width: CSP enforces style-src-attr. ?>
                                            <input type="number" name="months" value="1" min="1" max="24" class="text-xs w-16"
                                                   title="Months to add">
                                            <button class="btn btn-ghost btn-xs">Activate manually</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (! empty($r['listing_slug'])): ?>
                                        <a class="btn btn-ghost btn-xs" href="<?= esc(base_url('directory/' . $r['listing_slug']), 'attr') ?>" target="_blank" rel="noopener">View</a>
                                    <?php endif; ?>
                                    <a class="btn btn-ghost btn-xs" href="<?= base_url('admin/edit/' . $r['listing_id']) ?>">Edit listing</a>
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
