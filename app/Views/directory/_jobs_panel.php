<?php
/**
 * The owner dashboard's Jobs board panel: post a job or ask for a service,
 * and close or renew what is already up.
 *
 * Below the edit form, not above it: editing the profile is what an owner
 * comes here for, and this panel must not push those fields down the page.
 *
 * @var array<string,mixed>          $listing
 * @var list<array<string,mixed>>    $posts
 * @var App\Services\JobBoardService $svc
 */
use App\Models\JobPostModel;

$labels = [
    JobPostModel::STATUS_PENDING   => ['Waiting for review', 'pill-pending'],
    JobPostModel::STATUS_PUBLISHED => ['Live', 'pill-published'],
    JobPostModel::STATUS_REJECTED  => ['Not published', 'pill-rejected'],
    JobPostModel::STATUS_CLOSED    => ['Closed', 'pill-unpublished'],
    JobPostModel::STATUS_EXPIRED   => ['Expired', 'pill-unpublished'],
];
$published = ($listing['status'] ?? '') === 'published';
?>
<div class="panel mt-6" id="jobs">
    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Jobs &amp; services</h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        Hiring, or need another business's help? Post it free on the
        <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('jobs') ?>">Jobs board</a>.
        Your posts link to this profile. You can also reply to people asking for a service like yours.
    </p>

    <?php if ($published): ?>
        <div class="mt-3 flex flex-wrap gap-2">
            <a class="btn btn-accent btn-xs" href="<?= base_url('manage/jobs/new?kind=job') ?>"><?= lucide('briefcase', 'h-3.5 w-3.5 shrink-0') ?>Post a job</a>
            <a class="btn btn-ghost btn-xs" href="<?= base_url('manage/jobs/new?kind=service') ?>"><?= lucide('wrench', 'h-3.5 w-3.5 shrink-0') ?>Request a service</a>
            <a class="btn btn-ghost btn-xs" href="<?= base_url('jobs?kind=service') ?>">See requests you could answer</a>
        </div>
        <?php // Its own small form, not a field of the profile form above:
              // job_alerts is deliberately outside OWNER_EDITABLE. The marker
              // lets an unticked box mean "off". ?>
        <form method="post" action="<?= base_url('manage/jobs/alerts') ?>" class="mt-4 flex flex-wrap items-center gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="alerts_present" value="1">
            <label class="text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="job_alerts" value="1" <?= ! empty($listing['job_alerts']) ? 'checked' : '' ?>>
                Email me when someone in <?= esc($listing['province'] ?: 'my province') ?> posts a request in my category
            </label>
            <button type="submit" class="btn btn-ghost btn-xs">Save</button>
        </form>
    <?php else: ?>
        <p class="hint mt-3">You can post once your profile is published.</p>
    <?php endif; ?>

    <?php if ($posts !== []): ?>
        <div class="tablewrap mt-4">
            <table class="table">
                <tbody>
                <?php foreach ($posts as $p): ?>
                    <?php [$label, $pill] = $labels[$p['status']] ?? [$p['status'], '']; ?>
                    <tr>
                        <td>
                            <?php if ($p['status'] === JobPostModel::STATUS_PUBLISHED): ?>
                                <a class="font-medium hover:underline" href="<?= esc($svc->url($p)) ?>"><?= esc($p['title']) ?></a>
                            <?php else: ?>
                                <?= esc($p['title']) ?>
                            <?php endif; ?>
                            <div class="text-xs text-slate-500 dark:text-slate-400">
                                <?= $p['kind'] === JobPostModel::KIND_JOB ? 'Job' : 'Service request' ?>
                                &middot; closes <?= esc(date('j M Y', strtotime((string) $p['valid_through']))) ?>
                                <?php if ($p['kind'] === JobPostModel::KIND_SERVICE): ?>
                                    &middot; <?= (int) $p['response_count'] ?> repl<?= (int) $p['response_count'] === 1 ? 'y' : 'ies' ?>
                                <?php endif; ?>
                            </div>
                            <?php if (! empty($p['reject_reason'])): ?>
                                <div class="text-xs text-brand-crimson"><?= esc($p['reject_reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="pill <?= esc($pill, 'attr') ?>"><?= esc($label) ?></span></td>
                        <td>
                            <div class="actions">
                                <?php if (in_array($p['status'], [JobPostModel::STATUS_PENDING, JobPostModel::STATUS_PUBLISHED], true)): ?>
                                    <a class="btn btn-ghost btn-xs" href="<?= base_url('manage/jobs/' . (int) $p['id'] . '/edit') ?>">Edit</a>
                                <?php endif; ?>
                                <?php if ($svc->canRenew($p)): ?>
                                    <form method="post" action="<?= base_url('manage/jobs/' . (int) $p['id'] . '/renew') ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-accent btn-xs">Renew</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($p['status'], [JobPostModel::STATUS_PENDING, JobPostModel::STATUS_PUBLISHED], true)): ?>
                                    <form method="post" action="<?= base_url('manage/jobs/' . (int) $p['id'] . '/close') ?>" data-confirm="Close this post now?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-ghost btn-xs">Close</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
