<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Partner | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\DirectoryPartnerCommissionModel;
use App\Models\DirectoryPartnerModel;

/**
 * @var array<string,mixed>          $partner
 * @var array<string,mixed>          $d
 * @var App\Services\PartnerService  $svc
 * @var list<array<string,mixed>>    $listings
 */
$prettyDate = static function (?string $date): string {
    $ts = $date ? strtotime($date) : false;

    return $ts === false ? '' : date('j M Y', $ts);
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container page-flow">
        <p class="mb-2 text-sm"><a href="<?= base_url('admin/partners') ?>">&larr; All partners</a></p>
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white"><?= esc($partner['name']) ?></h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            <?= esc($partner['email']) ?> · <?= esc(ucfirst((string) $partner['status'])) ?> · <?= esc($d['link']) ?>
            <?php if (! empty($partner['decided_by'])): ?> · decided by <?= esc($partner['decided_by']) ?> on <?= esc($prettyDate($partner['decided_at'])) ?><?php endif; ?>
        </p>

        <div class="card-grid mb-6">
            <div class="panel"><div class="text-sm text-slate-500">Clicks</div><div class="text-xl font-bold"><?= number_format((int) $d['clicks']) ?></div></div>
            <div class="panel"><div class="text-sm text-slate-500">Profiles / paying</div><div class="text-xl font-bold"><?= (int) $d['signups'] ?> / <?= (int) $d['paying'] ?></div></div>
            <div class="panel"><div class="text-sm text-slate-500">Held / ready</div><div class="text-xl font-bold"><?= esc($svc->rand($d['totals']['pending'])) ?> / <?= esc($svc->rand($d['totals']['available'])) ?></div></div>
            <div class="panel"><div class="text-sm text-slate-500">Paid</div><div class="text-xl font-bold"><?= esc($svc->rand($d['totals']['paid'])) ?></div><div class="text-xs text-slate-500">Bank: <?= esc($d['bank'] !== '' ? $d['bank'] : 'none yet') ?></div></div>
        </div>

        <div class="card-grid mb-6">
            <div class="panel">
                <h2 class="mb-2 font-bold">Rate</h2>
                <form method="post" action="<?= base_url('admin/partners/' . $partner['id'] . '/rate') ?>" class="flex flex-wrap items-center gap-2">
                    <?= csrf_field() ?>
                    <input type="text" name="rate" value="<?= esc((string) ($partner['commission_rate'] ?? ''), 'attr') ?>" placeholder="<?= esc((string) $svc->config()->commissionRate, 'attr') ?> (default)" class="w-32">
                    <span>%</span>
                    <button class="btn btn-primary btn-xs">Save</button>
                </form>
                <p class="hint mt-2">Leave empty for the default. Only payments from now on use a new rate.</p>
            </div>
            <div class="panel">
                <h2 class="mb-2 font-bold">Credit a profile</h2>
                <form method="post" action="<?= base_url('admin/partners/' . $partner['id'] . '/credit') ?>" class="flex flex-wrap items-center gap-2">
                    <?= csrf_field() ?>
                    <input type="text" name="slug" placeholder="profile slug" class="w-48" required>
                    <button class="btn btn-primary btn-xs">Credit</button>
                </form>
                <p class="hint mt-2">For a referral the link missed. Only payments made after this earn.</p>
            </div>
            <div class="panel">
                <h2 class="mb-2 font-bold">Status</h2>
                <?php if ($partner['status'] === DirectoryPartnerModel::STATUS_APPROVED): ?>
                    <form method="post" action="<?= base_url('admin/partners/' . $partner['id'] . '/suspend') ?>"
                          data-confirm="Suspend <?= esc($partner['name'], 'attr') ?>? Their link stops tracking and earning.">
                        <?= csrf_field() ?>
                        <input type="text" name="note" maxlength="500" placeholder="Why (for us)" class="mb-2 w-full">
                        <button class="btn btn-ghost btn-xs">Suspend</button>
                    </form>
                <?php elseif (in_array($partner['status'], [DirectoryPartnerModel::STATUS_APPLIED, DirectoryPartnerModel::STATUS_SUSPENDED], true)): ?>
                    <form method="post" action="<?= base_url('admin/partners/' . $partner['id'] . '/approve') ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary btn-xs"><?= $partner['status'] === DirectoryPartnerModel::STATUS_SUSPENDED ? 'Reinstate' : 'Approve' ?></button>
                    </form>
                <?php else: ?>
                    <p class="text-sm text-slate-500">Declined.</p>
                <?php endif; ?>
                <?php if (! empty($partner['admin_note'])): ?><p class="hint mt-2">Note: <?= esc($partner['admin_note']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="panel mb-6">
            <h2 class="mb-2 font-bold">Credited profiles</h2>
            <?php if ($listings === []): ?>
                <p class="text-sm text-slate-500">None yet.</p>
            <?php else: ?>
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th>Profile</th><th>Credited</th><th>Verified until</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($listings as $l): ?>
                            <tr>
                                <td><a href="<?= base_url('admin/edit/' . $l['id']) ?>"><?= esc($l['display_name']) ?></a><div class="text-xs text-slate-500"><?= esc($l['email']) ?></div></td>
                                <td><?= esc($prettyDate($l['partner_attributed_at'])) ?></td>
                                <td><?= esc($prettyDate($l['verified_until'])) ?></td>
                                <td>
                                    <form method="post" action="<?= base_url('admin/partners/listings/' . $l['id'] . '/uncredit') ?>"
                                          data-confirm="Stop crediting <?= esc($l['display_name'], 'attr') ?> to this partner?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-ghost btn-xs">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel mb-6">
            <h2 class="mb-2 font-bold">Commission</h2>
            <?php if ($d['commissions'] === []): ?>
                <p class="text-sm text-slate-500">None yet.</p>
            <?php else: ?>
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Profile</th><th>PayFast id</th><th>Payment</th><th>Rate</th><th>Commission</th><th>State</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($d['commissions'] as $c): ?>
                            <tr>
                                <td><?= esc($prettyDate($c['created_at'])) ?></td>
                                <td><?= esc($c['listing_name'] ?? '') ?></td>
                                <td class="text-xs"><?= esc($c['pf_payment_id']) ?></td>
                                <td><?= esc($svc->rand((float) $c['payment_amount'])) ?></td>
                                <td><?= esc($svc->percent((float) $c['rate'])) ?></td>
                                <td><?= esc($svc->rand((float) $c['amount'])) ?></td>
                                <td>
                                    <?= esc($c['state']) ?>
                                    <?php if ($c['state'] === DirectoryPartnerCommissionModel::STATE_PENDING): ?><div class="text-xs text-slate-500">until <?= esc($prettyDate($c['available_at'])) ?></div><?php endif; ?>
                                    <?php if (! empty($c['void_reason'])): ?><div class="text-xs text-slate-500"><?= esc($c['void_reason']) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (in_array($c['state'], [DirectoryPartnerCommissionModel::STATE_PENDING, DirectoryPartnerCommissionModel::STATE_AVAILABLE], true)): ?>
                                        <form method="post" action="<?= base_url('admin/partners/commissions/' . $c['id'] . '/void') ?>"
                                              data-confirm="Cancel this commission? The partner will not be paid it.">
                                            <?= csrf_field() ?>
                                            <input type="text" name="reason" maxlength="255" placeholder="Reason" class="w-32">
                                            <button class="btn btn-ghost btn-xs">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($d['payouts'] !== []): ?>
            <div class="panel">
                <h2 class="mb-2 font-bold">Payouts</h2>
                <div class="tablewrap">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Total</th><th>EFT reference</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['payouts'] as $p): ?>
                            <tr><td><?= esc($prettyDate($p['paid_at'])) ?></td><td><?= esc($svc->rand((float) $p['total'])) ?></td><td><?= esc($p['eft_reference']) ?></td><td><?= esc($p['paid_by'] ?? '') ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
