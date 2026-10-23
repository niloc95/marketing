<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Profile setup orders | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\ProfileSetupOrderModel;

/**
 * Profile setup orders from /profile-setup, newest first. Paid orders are
 * the default view: that is the work waiting.
 *
 * @var string                    $status '' for all
 * @var array<string,int>         $counts
 * @var list<array<string,mixed>> $rows
 */
$link = static fn (string $s) => base_url('admin/profile-setup') . '?status=' . rawurlencode($s);
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <h1 class="mb-3 text-xl font-bold text-slate-900 dark:text-white">Profile setup orders</h1>

        <div class="tabs">
            <a class="<?= $status === '' ? 'active' : '' ?>" href="<?= esc($link('')) ?>">All</a>
            <?php foreach (ProfileSetupOrderModel::STATUSES as $key => $label): ?>
                <a class="<?= $status === $key ? 'active' : '' ?>" href="<?= esc($link($key)) ?>"><?= esc($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>

        <?php if ($rows === []): ?>
            <div class="empty">Nothing in this view.</div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table">
                    <thead>
                        <tr><th>Business</th><th>Details</th><th>Ordered</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $o): ?>
                        <?php $profile = safe_external_url($o['profile_url'] ?? ''); ?>
                        <tr id="order-<?= (int) $o['id'] ?>">
                            <td>
                                <strong><?= esc($o['business_name']) ?></strong>
                                <div class="text-xs"><?= esc($o['name']) ?></div>
                                <div class="text-xs"><a href="mailto:<?= esc($o['email'], 'attr') ?>"><?= esc($o['email']) ?></a> · <?= esc($o['phone']) ?></div>
                                <div class="text-xs text-slate-500"><?= esc($o['reference']) ?> · <?= esc(money($o['amount'])) ?></div>
                            </td>
                            <td class="text-sm">
                                <?php if ($profile !== ''): ?>
                                    <a href="<?= esc($profile, 'attr') ?>" target="_blank" rel="noopener noreferrer">Existing profile</a>
                                <?php else: ?>
                                    <span class="text-slate-500">No profile yet</span>
                                <?php endif; ?>
                                <?php if (! empty($o['notes'])): ?>
                                    <div class="mt-1 whitespace-pre-line"><?= esc($o['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm">
                                <?= esc(date('j M Y H:i', strtotime((string) $o['created_at']))) ?>
                                <?php if (! empty($o['paid_at'])): ?>
                                    <div class="text-xs">Paid <?= esc(date('j M Y H:i', strtotime((string) $o['paid_at']))) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= base_url('admin/profile-setup/' . (int) $o['id']) ?>" class="space-y-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_status" value="<?= esc($status, 'attr') ?>">
                                    <select name="status" aria-label="Status">
                                        <option value="<?= esc($o['status'], 'attr') ?>" selected><?= esc(ProfileSetupOrderModel::STATUSES[$o['status']] ?? $o['status']) ?></option>
                                        <?php foreach (ProfileSetupOrderModel::ADMIN_SETTABLE as $key): ?>
                                            <?php if ($key !== $o['status']): ?>
                                                <option value="<?= esc($key, 'attr') ?>"><?= esc(ProfileSetupOrderModel::STATUSES[$key]) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <textarea name="admin_notes" rows="2" placeholder="Notes, only seen here"><?= esc($o['admin_notes'] ?? '') ?></textarea>
                                    <button class="btn btn-ghost btn-xs">Save</button>
                                </form>
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
