<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Partner payouts | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * @var list<array<string,mixed>>    $rows  PartnerService::payable()
 * @var App\Services\PartnerService  $svc
 */
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <p class="mb-2 text-sm"><a href="<?= base_url('admin/partners') ?>">&larr; All partners</a></p>
        <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Payouts due</h1>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            Partners owed <?= esc($svc->rand($svc->config()->minimumPayout)) ?> or more in commission past its hold. Pay by EFT from the bank first,
            then enter the reference here. That marks the commission paid and emails the partner a statement.
        </p>

        <?php if ($rows === []): ?>
            <div class="empty">Nobody is owed the minimum yet.</div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table">
                    <thead><tr><th>Partner</th><th>Owed</th><th>Pay into</th><th>Record the EFT</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $p): ?>
                        <tr>
                            <td>
                                <a href="<?= base_url('admin/partners/' . $p['id']) ?>"><strong><?= esc($p['name']) ?></strong></a>
                                <div class="text-xs text-slate-500"><?= esc($p['email']) ?> · <?= esc($p['status']) ?></div>
                            </td>
                            <td><strong><?= esc($svc->rand($p['owed'])) ?></strong><div class="text-xs text-slate-500"><?= (int) $p['owed_count'] ?> payments</div></td>
                            <td class="text-xs">
                                <?php if ($p['bank'] === null): ?>
                                    <span class="text-brand-crimson">No bank details. Ask them to add an account on their dashboard.</span>
                                <?php else: ?>
                                    <div><?= esc($p['bank']['holder']) ?></div>
                                    <div><?= esc($p['bank']['bank']) ?> (<?= esc($p['bank']['account_type']) ?>)</div>
                                    <div>Branch <?= esc($p['bank']['branch_code']) ?></div>
                                    <div>Account <?= esc($p['bank']['account_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['bank'] !== null): ?>
                                    <form method="post" action="<?= base_url('admin/partners/payouts/' . $p['id']) ?>"
                                          data-confirm="Record an EFT of <?= esc($svc->rand($p['owed']), 'attr') ?> to <?= esc($p['name'], 'attr') ?>? Only do this after the money has gone.">
                                        <?= csrf_field() ?>
                                        <input type="text" name="eft_reference" maxlength="120" required placeholder="EFT reference" class="w-40">
                                        <button class="btn btn-primary btn-xs">Mark paid</button>
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
</section>
<?= $this->endSection() ?>
