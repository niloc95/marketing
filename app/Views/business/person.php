<?php
/**
 * Someone who signs in but takes no bookings (reception, a manager): their
 * sign in, who they look after and whose notices they get (T36).
 *
 * @var array<string,mixed>  $access     Team::access()
 * @var array<string,string> $roleLabels
 */
$m = $access['member'];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<?php if (! empty($profileTabs)): ?>
    <?= view('layouts/_profile_tabs', ['tabs' => $profileTabs, 'label' => 'Profile sections'], ['saveData' => false]) ?>
<?php endif ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('business/team#people') ?>">Team</a></p>
<p class="mb-4 text-sm text-slate-500 dark:text-slate-400"><?= esc($roleLabels[$m['role']] ?? '') ?>. Customers do not book <?= esc((string) ($m['account_name'] ?: $m['account_email'])) ?>.</p>
<div class="grid gap-6 lg:grid-cols-2">
    <?= view('business/_person_access', ['access' => $access, 'roleLabels' => $roleLabels], ['saveData' => false]) ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?><?= esc((string) ($m['account_name'] ?: $m['account_email'])) ?><?= $this->endSection() ?>
