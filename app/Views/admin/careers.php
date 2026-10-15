<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Careers | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\CareerRoleModel;

/**
 * Our own roles on /careers. Open first, then drafts, then closed.
 *
 * @var list<array<string,mixed>>    $roles
 * @var array<int,int>               $perRole applications per role id (0 = general)
 * @var App\Services\CareersService  $svc
 */
$chip = [
    CareerRoleModel::STATUS_OPEN   => 'badge badge-category cat-tint-green',
    CareerRoleModel::STATUS_DRAFT  => 'badge badge-category cat-tint-amber',
    CareerRoleModel::STATUS_CLOSED => 'badge badge-category cat-tint-slate',
];
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white">Careers</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Roles at <?= esc(config('Directory')->siteName()) ?> itself. Only open roles show on
                    <a href="<?= base_url('careers') ?>" target="_blank" rel="noopener">/careers</a>.
                    <?= (int) ($perRole[0] ?? 0) ?> general applications.
                </p>
            </div>
            <div class="flex gap-2">
                <a class="btn btn-ghost btn-xs" href="<?= base_url('admin/careers/applications') ?>">Applications</a>
                <a class="btn btn-primary btn-xs" href="<?= base_url('admin/careers/new') ?>">New role</a>
            </div>
        </div>

        <?php if ($roles === []): ?>
            <div class="empty">No roles yet.</div>
        <?php else: ?>
            <div class="tablewrap">
                <table class="table">
                    <thead>
                        <tr><th>Role</th><th>Status</th><th>Opened</th><th>Closes</th><th>Questions</th><th>Applications</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($roles as $r): ?>
                        <?php $live = $svc->isOpen($r); ?>
                        <tr>
                            <td>
                                <a href="<?= base_url('admin/careers/' . $r['id']) ?>"><strong><?= esc($r['title']) ?></strong></a>
                                <div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($svc->teamLabel($r)) ?><?= $svc->placeText($r) !== '' ? ' · ' . esc($svc->placeText($r)) : '' ?></div>
                                <?php if ($live): ?><div class="text-xs"><a href="<?= esc($svc->url($r), 'attr') ?>" target="_blank" rel="noopener">/careers/<?= esc($r['slug']) ?></a></div><?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= $chip[$r['status']] ?? 'badge' ?>"><?= esc(ucfirst((string) $r['status'])) ?></span>
                                <?php if ($r['status'] === CareerRoleModel::STATUS_OPEN && ! $live): ?>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">Past its closing date, so hidden</div>
                                <?php endif; ?>
                            </td>
                            <?php // Opened: first went live (set once, by CareersService::setRoleStatus()). Closes: valid_through, after which the role hides itself. ?>
                            <td class="whitespace-nowrap text-sm">
                                <?= ! empty($r['published_at']) ? esc(date('j M Y', strtotime((string) $r['published_at']))) : '<span class="text-slate-500 dark:text-slate-400">Not yet</span>' ?>
                            </td>
                            <td class="whitespace-nowrap text-sm">
                                <?php if (! empty($r['valid_through'])): ?>
                                    <?= esc(date('j M Y', strtotime((string) $r['valid_through']))) ?>
                                <?php else: ?>
                                    <span class="text-slate-500 dark:text-slate-400">No closing date</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php // Screening questions: the level and its time limit, or None. ?>
                                <?php if ($svc->screens($r)): ?>
                                    <?php $level = (string) $r['screening_level']; ?>
                                    <span class="badge"><?= esc($svc->screening()->levelLabel($level)) ?></span>
                                    <div class="text-xs text-slate-500 dark:text-slate-400"><?= App\Libraries\Screening::COUNT ?> questions, <?= esc($svc->screening()->clock($svc->screening()->limitFor($level))) ?></div>
                                <?php else: ?>
                                    <span class="text-sm text-slate-500 dark:text-slate-400">None</span>
                                    <div class="text-xs"><a href="<?= base_url('admin/careers/' . $r['id']) ?>#s-level">Add questions</a></div>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?= base_url('admin/careers/applications') . '?role=' . (int) $r['id'] ?>"><?= (int) ($perRole[(int) $r['id']] ?? 0) ?></a></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-ghost btn-xs" href="<?= base_url('admin/careers/' . $r['id']) ?>">Edit</a>
                                    <?php foreach ([CareerRoleModel::STATUS_OPEN => 'Open', CareerRoleModel::STATUS_CLOSED => 'Close', CareerRoleModel::STATUS_DRAFT => 'Back to draft'] as $to => $label): ?>
                                        <?php if ($r['status'] !== $to): ?>
                                            <form method="post" action="<?= base_url('admin/careers/' . $r['id'] . '/status') ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="status" value="<?= $to ?>">
                                                <button class="btn <?= $to === CareerRoleModel::STATUS_OPEN ? 'btn-primary' : 'btn-ghost' ?> btn-xs"><?= $label ?></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
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
