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
 * @var list<array<string,mixed>> $paused    CommunicationsService::pausedProfiles()
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
            Each profile gets a campaign once (a sequence's emails spaced out), never two campaign emails within a few days, and every email carries the opt out link.
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

    <h2 class="mb-2 mt-8 text-base font-bold text-slate-900 dark:text-white">Paused profiles</h2>
    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
        No campaign email goes to these until you resume them: an owner replied, or said on the claim page that something is wrong.
        Pause one from its row in a campaign's send log, or from the profile's edit page.
    </p>
    <?php if ($paused === []): ?>
        <div class="empty">No paused profiles.</div>
    <?php else: ?>
        <div class="tablewrap">
            <table class="table">
                <thead><tr><th>Profile</th><th>Why</th><th>Since</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($paused as $p): ?>
                    <tr>
                        <td><a href="<?= base_url('admin/edit/' . (int) $p['id']) ?>"><?= esc((string) $p['display_name']) ?></a><?= $p['status'] !== 'published' ? ' <span class="text-xs text-slate-500 dark:text-slate-400">(' . esc((string) $p['status']) . ')</span>' : '' ?></td>
                        <td class="text-sm"><?= esc((string) $p['comms_paused_note']) ?></td>
                        <td class="whitespace-nowrap text-sm"><?= esc(date('j M Y', strtotime((string) $p['comms_paused_at']))) ?></td>
                        <td><?= view('admin/communications/_pause_button', ['listingId' => (int) $p['id'], 'paused' => true, 'back' => 'admin/communications'], ['saveData' => false]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
