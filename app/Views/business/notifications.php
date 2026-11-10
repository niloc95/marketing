<?php
/**
 * Notices (Business\Notifications::index): bookings, moves and cancellations
 * customers made online, for the people who work with that team member.
 *
 * @var list<array<string,mixed>> $items
 * @var int                       $unread
 * @var bool                      $notify
 * @var string                    $tz
 * @var bool                      $canLog
 */
$zone  = new DateTimeZone($tz);
$local = static fn (string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone)->format('D j M, H:i');
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mb-4 flex flex-wrap items-center gap-3">
    <h1 class="text-xl font-bold text-slate-900 dark:text-white">Notices</h1>
    <?php if ($unread > 0): ?>
        <form method="post" action="<?= base_url('business/notifications/read') ?>" class="ml-auto">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-xs">Mark all as read</button>
        </form>
    <?php endif ?>
</div>

<?php if ($items === []): ?>
    <p class="text-sm text-slate-600 dark:text-slate-300">Nothing yet. When a customer books, moves or cancels online, you see it here.</p>
<?php else: ?>
    <ul class="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
        <?php foreach ($items as $n): ?>
            <li class="flex flex-wrap items-start gap-3 p-3<?= $n['read_at'] === null ? ' bg-primary-50 dark:bg-slate-800' : '' ?>">
                <div class="min-w-0 flex-1">
                    <p class="font-medium"><?= esc((string) $n['title']) ?></p>
                    <?php if (! empty($n['body'])): ?>
                        <p class="text-sm text-slate-600 dark:text-slate-300"><?= esc((string) $n['body']) ?></p>
                    <?php endif ?>
                    <p class="text-xs text-slate-500 dark:text-slate-400"><?= esc($local((string) $n['created_at'])) ?></p>
                </div>
                <?php if (! empty($n['appointment_hash'])): ?>
                    <a class="btn btn-ghost btn-xs" href="<?= base_url('business/calendar?view=day&date=' . (new DateTimeImmutable((string) $n['start_at'], new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d') . '&open=' . $n['appointment_hash']) ?>">Open</a>
                <?php endif ?>
                <?php if ($n['read_at'] === null): ?>
                    <form method="post" action="<?= base_url('business/notifications/read') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-xs">Read</button>
                    </form>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

<form method="post" action="<?= base_url('business/notifications/preferences') ?>" class="form-card form-card-narrow mt-8">
    <?= csrf_field() ?>
    <h2 class="mb-2 font-bold">Email me too</h2>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify" value="1"<?= $notify ? ' checked' : '' ?>> Email me when a customer books, moves or cancels online</label>
    <button type="submit" class="btn btn-primary mt-3">Save</button>
</form>
<?php if ($canLog): ?>
    <p class="mt-6 text-sm"><a class="underline" href="<?= base_url('business/notifications/sent') ?>">See messages sent to customers and the team</a></p>
<?php endif ?>
<?= $this->endSection() ?>
