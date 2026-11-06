<?php
/**
 * @var list<array<string,mixed>> $rows
 * @var int $page
 * @var int $pageSize
 */
$actions = [
    'bookings.turned_on'  => 'Turned on online bookings',
    'bookings.turned_off' => 'Turned off online bookings',
    'settings.saved'      => 'Changed settings',
    // Phase 10: the plan, privacy and platform support.
    'plan.trial_started'  => 'Free trial started',
    'plan.paid'           => 'Plan paid',
    'plan.cancelled'      => 'Plan set not to renew',
    'plan.extended'       => 'WebScheduler Local added free time',
    'customer.exported'   => 'Downloaded a customer\'s data',
    'customers.retention_purged' => 'Removed personal details of customers past the keeping period',
    'support.opened'      => 'WebScheduler Local support opened your bookings',
    'api_key.created'     => 'Made an API key',
    'api_key.revoked'     => 'Revoked an API key',
];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<h1 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Activity</h1>
<?php if ($rows === []): ?>
    <p class="text-sm text-slate-500 dark:text-slate-400">Nothing yet.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>When (UTC)</th><th>Who</th><th>What</th><th>Change</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="whitespace-nowrap"><?= esc((string) $r['created_at']) ?></td>
                        <td><?= esc((string) ($r['account_name'] ?: ($r['account_email'] ?? '')) ?: (str_starts_with((string) $r['action'], 'support.') || $r['action'] === 'plan.extended' ? 'WebScheduler Local' : 'Automatic')) ?></td>
                        <td><?= esc($actions[$r['action']] ?? (string) $r['action']) ?></td>
                        <td class="text-xs text-slate-500 dark:text-slate-400">
                            <?php if ($r['new_value'] !== null): ?>
                                <?= esc((string) ($r['old_value'] ?? '')) ?> → <?= esc((string) $r['new_value']) ?>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <p class="mt-4 flex gap-3 text-sm">
        <?php if ($page > 1): ?><a class="underline" href="<?= base_url('bookings/audit?page=' . ($page - 1)) ?>">Newer</a><?php endif ?>
        <?php if (count($rows) === $pageSize): ?><a class="underline" href="<?= base_url('bookings/audit?page=' . ($page + 1)) ?>">Older</a><?php endif ?>
    </p>
<?php endif ?>
<?= $this->endSection() ?>
