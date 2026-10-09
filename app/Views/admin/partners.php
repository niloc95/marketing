<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Partners | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\DirectoryPartnerModel;

/**
 * @var string                       $status
 * @var list<array<string,mixed>>    $rows
 * @var array<string,int>            $counts
 * @var App\Services\PartnerService  $svc
 */

$tabs = [
    DirectoryPartnerModel::STATUS_APPLIED   => 'Applications',
    DirectoryPartnerModel::STATUS_APPROVED  => 'Partners',
    DirectoryPartnerModel::STATUS_SUSPENDED => 'Suspended',
    DirectoryPartnerModel::STATUS_REJECTED  => 'Declined',
];

$link = static fn (string $s) => base_url('admin/partners') . '?' . http_build_query(['status' => $s]);

$prettyDate = static function (?string $date): string {
    $ts = $date ? strtotime($date) : false;

    return $ts === false ? 'n/a' : date('j M Y', $ts);
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Partner Program</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Default rate <?= esc($svc->percent($svc->config()->commissionRate)) ?> for <?= (int) $svc->config()->commissionMonths ?> months,
                    held <?= (int) $svc->config()->holdDays ?> days, paid from <?= esc($svc->rand($svc->config()->minimumPayout)) ?>.
                </p>
            </div>
            <a class="btn btn-primary btn-xs" href="<?= base_url('admin/partners/payouts') ?>">Payouts due</a>
        </div>

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
                        <tr><th>Partner</th><th>How they will promote</th><th>Received</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $p): ?>
                        <?php $website = safe_external_url($p['website'] ?? ''); ?>
                        <tr>
                            <td>
                                <a href="<?= base_url('admin/partners/' . $p['id']) ?>"><strong><?= esc($p['name']) ?></strong></a>
                                <?php if (! empty($p['company'])): ?><div class="text-xs"><?= esc($p['company']) ?></div><?php endif; ?>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($p['email']) ?><?= ! empty($p['phone']) ? ' · ' . esc($p['phone']) : '' ?></div>
                                <?php if ($website !== ''): ?>
                                    <div class="text-xs"><a href="<?= esc($website) ?>" target="_blank" rel="noopener nofollow"><?= esc(parse_url($website, PHP_URL_HOST) ?: $website) ?></a></div>
                                <?php endif; ?>
                                <div class="text-xs text-slate-500 dark:text-slate-400">Link: /p/<?= esc($p['code']) ?> · <?= esc($svc->percent($svc->rateFor($p))) ?></div>
                            </td>
                            <td class="text-sm"><?= nl2br(esc((string) $p['promo_plan'])) ?>
                                <?php if (! empty($p['admin_note'])): ?><div class="mt-1 text-xs text-slate-500 dark:text-slate-400">Note: <?= esc($p['admin_note']) ?></div><?php endif; ?>
                            </td>
                            <td><?= esc($prettyDate($p['created_at'] ?? null)) ?></td>
                            <td>
                                <div class="actions">
                                    <?php if (in_array($p['status'], [DirectoryPartnerModel::STATUS_APPLIED, DirectoryPartnerModel::STATUS_SUSPENDED], true)): ?>
                                        <form method="post" action="<?= base_url('admin/partners/' . $p['id'] . '/approve') ?>"
                                              data-confirm="Approve <?= esc($p['name'], 'attr') ?>? They are emailed their link straight away.">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-primary btn-xs"><?= $p['status'] === DirectoryPartnerModel::STATUS_SUSPENDED ? 'Reinstate' : 'Approve' ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($p['status'] === DirectoryPartnerModel::STATUS_APPLIED): ?>
                                        <form method="post" action="<?= base_url('admin/partners/' . $p['id'] . '/reject') ?>"
                                              data-confirm="Decline <?= esc($p['name'], 'attr') ?>? They are emailed a polite no.">
                                            <?= csrf_field() ?>
                                            <input type="text" name="note" maxlength="500" placeholder="Note for us (optional)" class="input-xs">
                                            <button class="btn btn-ghost btn-xs">Decline</button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="btn btn-ghost btn-xs" href="<?= base_url('admin/partners/' . $p['id']) ?>">Open</a>
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
