<?= $this->extend('layouts/public') ?>

<?php $siteName = config('Directory')->siteName(); ?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'     => 'Partner dashboard | ' . $siteName,
    'canonical' => base_url('partners/dashboard'),
    'robots'    => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\DirectoryPartnerCommissionModel;
use App\Models\DirectoryPartnerModel;

/**
 * @var array<string,mixed>          $partner
 * @var array<string,mixed>          $d       PartnerService::dashboard()
 * @var App\Services\PartnerService  $svc
 * @var array<string,string>         $errors
 */
$err       = fn (string $f) => $errors[$f] ?? '';
$suspended = $partner['status'] === DirectoryPartnerModel::STATUS_SUSPENDED;
$waText    = 'Get your business found on ' . $siteName . ': ' . $d['link'];

$stateLabel = [
    DirectoryPartnerCommissionModel::STATE_PENDING   => 'Held',
    DirectoryPartnerCommissionModel::STATE_AVAILABLE => 'Ready to pay',
    DirectoryPartnerCommissionModel::STATE_PAID      => 'Paid',
    DirectoryPartnerCommissionModel::STATE_VOID      => 'Cancelled',
];
$prettyDate = static function (?string $date): string {
    $ts = $date ? strtotime($date) : false;

    return $ts === false ? '' : date('j M Y', $ts);
};
?>
<section class="section">
    <div class="container page-flow">
        <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <span class="eyebrow">Partner Program</span>
                <h1 class="text-2xl font-extrabold text-slate-900 dark:text-white">Hi <?= esc(strtok((string) $partner['name'], ' ')) ?></h1>
            </div>
            <a class="text-sm text-slate-500 hover:underline dark:text-slate-400" href="<?= base_url('partners/signout') ?>">Sign out</a>
        </div>

        <?php if ($suspended): ?>
            <div class="alert alert-warning mb-6">
                Your partner link is paused, so it is not tracking new visitors or earning. Commission you have already earned is still yours.
                Reply to any of our emails if you would like to talk about it.
            </div>
        <?php endif; ?>

        <div class="panel mb-6">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Your link</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                You earn <?= esc($svc->percent($d['rate'])) ?> of every payment from a business that signs up through it, for its first
                <?= (int) $svc->config()->commissionMonths ?> months. You can also add <code>?ref=<?= esc($partner['code']) ?></code> to any page on this site.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <input type="text" readonly value="<?= esc($d['link'], 'attr') ?>" class="w-full min-w-0 sm:w-auto sm:flex-1" aria-label="Your partner link">
                <button type="button" class="btn btn-primary" data-copy="<?= esc($d['link'], 'attr') ?>">Copy link</button>
                <a class="btn btn-whatsapp" href="https://wa.me/?text=<?= rawurlencode($waText) ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
            </div>
        </div>

        <div class="card-grid mb-6">
            <div class="panel">
                <div class="text-sm text-slate-500 dark:text-slate-400">Clicks</div>
                <div class="text-2xl font-extrabold text-slate-900 dark:text-white"><?= number_format((int) $d['clicks']) ?></div>
                <div class="text-xs text-slate-500 dark:text-slate-400"><?= number_format((int) $d['clicks30']) ?> in the last 30 days</div>
            </div>
            <div class="panel">
                <div class="text-sm text-slate-500 dark:text-slate-400">Profiles created</div>
                <div class="text-2xl font-extrabold text-slate-900 dark:text-white"><?= number_format((int) $d['signups']) ?></div>
                <div class="text-xs text-slate-500 dark:text-slate-400"><?= number_format((int) $d['paying']) ?> paying</div>
            </div>
            <div class="panel">
                <div class="text-sm text-slate-500 dark:text-slate-400">Ready to pay</div>
                <div class="text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($svc->rand($d['totals']['available'])) ?></div>
                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($svc->rand($d['totals']['pending'])) ?> still held</div>
            </div>
            <div class="panel">
                <div class="text-sm text-slate-500 dark:text-slate-400">Paid to you</div>
                <div class="text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($svc->rand($d['totals']['paid'])) ?></div>
                <div class="text-xs text-slate-500 dark:text-slate-400">Paid monthly from <?= esc($svc->rand($svc->config()->minimumPayout)) ?></div>
            </div>
        </div>

        <div class="panel mb-6">
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Commission</h2>
            <?php if ($d['commissions'] === []): ?>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nothing yet. Commission appears here as soon as a business you referred pays.</p>
            <?php else: ?>
                <div class="tablewrap mt-3">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Business</th><th>Payment</th><th>You earn</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['commissions'] as $c): ?>
                            <tr>
                                <td><?= esc($prettyDate($c['created_at'])) ?></td>
                                <td><?= esc($c['listing_name'] ?? 'Removed profile') ?></td>
                                <td><?= esc($svc->rand((float) $c['payment_amount'])) ?></td>
                                <td><?= esc($svc->rand((float) $c['amount'])) ?></td>
                                <td>
                                    <?= esc($stateLabel[$c['state']] ?? $c['state']) ?>
                                    <?php if ($c['state'] === DirectoryPartnerCommissionModel::STATE_PENDING): ?>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">until <?= esc($prettyDate($c['available_at'])) ?></div>
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
            <div class="panel mb-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Payments to you</h2>
                <div class="tablewrap mt-3">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Amount</th><th>EFT reference</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['payouts'] as $p): ?>
                            <tr>
                                <td><?= esc($prettyDate($p['paid_at'])) ?></td>
                                <td><?= esc($svc->rand((float) $p['total'])) ?></td>
                                <td><?= esc($p['eft_reference']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-card" id="bank">
            <h2 class="mb-1.5 text-lg font-bold text-slate-900 dark:text-white">Where we pay you</h2>
            <?php if ($d['bank'] !== ''): ?>
                <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">We pay into <strong><?= esc($d['bank']) ?></strong>. To change it, enter the new account below.</p>
            <?php else: ?>
                <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">Add a South African bank account so we can pay you. It is stored encrypted and only used to pay you.</p>
            <?php endif; ?>

            <form method="post" action="<?= base_url('partners/bank') ?>" autocomplete="off">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="b-holder">Account holder</label>
                    <input type="text" id="b-holder" name="holder" required maxlength="120">
                    <?php if ($err('holder')): ?><div class="err"><?= esc($err('holder')) ?></div><?php endif; ?>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="b-bank">Bank</label>
                        <input type="text" id="b-bank" name="bank" required maxlength="60" placeholder="FNB, Capitec, Standard Bank…">
                        <?php if ($err('bank')): ?><div class="err"><?= esc($err('bank')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="b-type">Account type</label>
                        <select id="b-type" name="account_type">
                            <option value="cheque">Cheque or current</option>
                            <option value="savings">Savings</option>
                            <option value="business">Business</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="b-branch">Branch code</label>
                        <input type="text" id="b-branch" name="branch_code" required inputmode="numeric" maxlength="10">
                        <?php if ($err('branch_code')): ?><div class="err"><?= esc($err('branch_code')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="b-account">Account number</label>
                        <input type="text" id="b-account" name="account_number" required inputmode="numeric" maxlength="16">
                        <?php if ($err('account_number')): ?><div class="err"><?= esc($err('account_number')) ?></div><?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-accent btn-block">Save bank details</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
