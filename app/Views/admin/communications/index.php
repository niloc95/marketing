<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Communications | Admin']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\CampaignModel;

/**
 * Every communications campaign, newest first.
 *
 * @var list<array<string,mixed>> $campaigns CommunicationsService::all()
 * @var Config\Campaigns          $config
 */
$chip = [
    CampaignModel::STATUS_DRAFT     => 'badge badge-category cat-tint-amber',
    CampaignModel::STATUS_SENDING   => 'badge badge-category cat-tint-green',
    CampaignModel::STATUS_PAUSED    => 'badge badge-category cat-tint-amber',
    CampaignModel::STATUS_DONE      => 'badge badge-category cat-tint-slate',
    CampaignModel::STATUS_CANCELLED => 'badge badge-category cat-tint-slate',
];
?>

<div class="shell-page">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Emails to a chosen group of profiles, sent a few at a time through the working day.
            Each profile gets a campaign once, and every email carries the opt out link.
        </p>
        <a class="btn btn-primary" href="<?= base_url('admin/communications/new') ?>">New campaign</a>
    </div>

    <?php if ($campaigns === []): ?>
        <div class="empty">No campaigns yet.</div>
    <?php else: ?>
        <div class="tablewrap">
            <table class="table">
                <thead>
                    <tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Queued</th><th>Failed</th><th>Skipped</th><th>Daily limit</th><th>Started</th></tr>
                </thead>
                <tbody>
                <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td>
                            <a href="<?= base_url('admin/communications/' . $c['id']) ?>"><strong><?= esc($c['name']) ?></strong></a>
                            <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($config->kinds[$c['kind']]['label'] ?? $c['kind']) ?></div>
                        </td>
                        <td><span class="<?= $chip[$c['status']] ?? 'badge' ?>"><?= esc(ucfirst((string) $c['status'])) ?></span></td>
                        <td><?= number_format((int) ($c['counts']['sent'] ?? 0)) ?></td>
                        <td><?= number_format((int) ($c['counts']['queued'] ?? 0)) ?></td>
                        <td><?= number_format((int) ($c['counts']['failed'] ?? 0)) ?></td>
                        <td><?= number_format((int) ($c['counts']['skipped'] ?? 0)) ?></td>
                        <td><?= (int) $c['daily_limit'] ?></td>
                        <td class="whitespace-nowrap text-sm"><?= ! empty($c['started_at']) ? esc(date('j M Y', strtotime((string) $c['started_at']))) : '<span class="text-slate-500 dark:text-slate-400">Not yet</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
