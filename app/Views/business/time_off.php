<?php
/**
 * @var list<array<string,mixed>> $rows
 * @var list<array<string,mixed>> $providers
 * @var bool                      $wholeBusiness may close the whole business
 * @var string                    $timezone
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<h1 class="text-xl font-bold text-slate-900 dark:text-white">Time off</h1>
<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Nobody can book these times. Public holidays follow your business profile's holiday hours. Times are in <?= esc($timezone) ?>.</p>

<form method="post" action="<?= base_url('business/time-off') ?>" class="form-card mt-5">
    <?= csrf_field() ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="field">
            <label for="to-who">Who</label>
            <select id="to-who" name="provider_id">
                <?php if ($wholeBusiness): ?><option value="0">The whole business is closed</option><?php endif ?>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= esc((string) $p['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="to-reason">Note <span class="font-normal text-slate-500">(optional, only your team sees it)</span></label>
            <input id="to-reason" type="text" name="reason" maxlength="160" placeholder="Leave, training">
        </div>
        <div class="field">
            <label for="to-start">From</label>
            <input id="to-start" type="datetime-local" name="start" required>
        </div>
        <div class="field">
            <label for="to-end">Until</label>
            <input id="to-end" type="datetime-local" name="end" required>
        </div>
    </div>
    <button type="submit" class="btn btn-primary">Add time off</button>
</form>

<h2 class="mt-8 mb-3 text-lg font-bold text-slate-900 dark:text-white">Coming up</h2>
<?php if ($rows === []): ?>
    <p class="text-sm text-slate-500 dark:text-slate-400">No time off planned.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Who</th><th>From</th><th>Until</th><th>Note</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= esc((string) ($r['provider_name'] ?? 'Whole business')) ?></td>
                        <td class="whitespace-nowrap"><?= esc($r['start_local']) ?></td>
                        <td class="whitespace-nowrap"><?= esc($r['end_local']) ?></td>
                        <td><?= esc((string) ($r['reason'] ?? '')) ?></td>
                        <td>
                            <?php if ($wholeBusiness || $r['provider_id'] !== null): ?>
                                <form method="post" action="<?= base_url('business/time-off/' . $r['id'] . '/delete') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-xs">Remove</button>
                                </form>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
