<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Career applications | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\CareerApplicationModel;

/**
 * Applications from /careers, newest first, filtered by status and role.
 * Purged rows (past the retention period) show as an empty line.
 *
 * @var string                     $status  '' for all
 * @var int|null                   $roleId  null for all, 0 for general applications
 * @var list<array<string,mixed>>  $rows
 * @var array<string,int>          $counts
 * @var list<array<string,mixed>>  $roles
 */
$link = static fn (array $q) => base_url('admin/careers/applications') . '?' . http_build_query(array_filter(
    $q + ['status' => $status, 'role' => $roleId],
    static fn ($v) => $v !== null && $v !== ''
));
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="<?= base_url('admin/careers') ?>">Careers</a>
            <span class="crumbs-sep">/</span>
            <span>Applications</span>
        </nav>
        <h1 class="mb-3 text-xl font-bold text-slate-900 dark:text-white">Applications</h1>

        <div class="tabs">
            <a class="<?= $status === '' ? 'active' : '' ?>" href="<?= esc($link(['status' => ''])) ?>">All</a>
            <?php foreach (CareerApplicationModel::STATUSES as $key => $label): ?>
                <a class="<?= $status === $key ? 'active' : '' ?>" href="<?= esc($link(['status' => $key])) ?>"><?= esc($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?= base_url('admin/careers/applications') ?>" class="mb-4 flex flex-wrap items-end gap-2">
            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= esc($status, 'attr') ?>"><?php endif; ?>
            <div class="field mb-0">
                <label for="f-role">Role</label>
                <select id="f-role" name="role">
                    <option value="">All roles</option>
                    <option value="0" <?= $roleId === 0 ? 'selected' : '' ?>>General applications</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" <?= $roleId === (int) $r['id'] ? 'selected' : '' ?>><?= esc($r['title']) ?><?= $r['status'] !== 'open' ? ' (' . esc($r['status']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-ghost btn-xs">Filter</button>
        </form>

        <?php if ($rows === []): ?>
            <div class="empty">Nothing in this view.</div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table">
                    <thead>
                        <tr><th>Applicant</th><th>Message</th><th>Received</th><th>Decision</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $a): ?>
                        <tr id="application-<?= (int) $a['id'] ?>">
                            <?php if (! empty($a['purged_at'])): ?>
                                <td colspan="4" class="text-sm text-slate-500 dark:text-slate-400">
                                    Application <?= (int) $a['id'] ?> for <?= esc($a['role_title'] ?? 'a general role') ?>, deleted <?= esc(date('j M Y', strtotime((string) $a['purged_at']))) ?> under the retention rule.
                                </td>
                            <?php else: ?>
                                <?php $link2 = safe_external_url($a['linkedin_url'] ?? ''); ?>
                                <td>
                                    <strong><?= esc($a['name']) ?></strong>
                                    <div class="text-xs"><?= esc($a['role_title'] ?? 'General application') ?></div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">
                                        <a href="mailto:<?= esc($a['email'], 'attr') ?>"><?= esc($a['email']) ?></a><?= ! empty($a['phone']) ? ' · ' . esc($a['phone']) : '' ?>
                                    </div>
                                    <?php if ($link2 !== ''): ?>
                                        <div class="text-xs"><a href="<?= esc($link2, 'attr') ?>" target="_blank" rel="noopener nofollow"><?= esc(parse_url($link2, PHP_URL_HOST) ?: $link2) ?></a></div>
                                    <?php endif; ?>
                                    <?php if (! empty($a['cv_path'])): ?>
                                        <a class="btn btn-ghost btn-xs mt-1" href="<?= base_url('admin/careers/applications/' . (int) $a['id'] . '/cv') ?>" target="_blank" rel="noopener">Open CV</a>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm"><?= nl2br(esc((string) $a['message'])) ?></td>
                                <td><?= esc(date('j M Y', strtotime((string) $a['created_at']))) ?></td>
                                <td>
                                    <form method="post" action="<?= base_url('admin/careers/applications/' . (int) $a['id']) ?>">
                                        <?= csrf_field() ?>
                                        <select name="status" class="input-xs mb-1">
                                            <?php foreach (CareerApplicationModel::STATUSES as $key => $label): ?>
                                                <option value="<?= $key ?>" <?= $a['status'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea name="admin_notes" rows="2" maxlength="2000" placeholder="Notes for us" class="input-xs mb-1 w-full"><?= esc((string) ($a['admin_notes'] ?? '')) ?></textarea>
                                        <button class="btn btn-primary btn-xs">Save</button>
                                    </form>
                                </td>
                            <?php endif; ?>
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
