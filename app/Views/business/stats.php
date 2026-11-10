<?php
/**
 * Stats (Business\Owner::stats): how the profile is doing. Basic for every
 * profile, the full set with the badge or Plus.
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto w-full max-w-3xl">
    <?php if (! empty($stats)): ?>
        <?= view('directory/_stats_panel', [
            'stats'       => $stats,
            'upgradeUrl'  => $stats['full'] ? '' : base_url('business/badge'),
            'upgradeName' => $upgradeName,
        ]) ?>
    <?php else: ?>
        <div class="panel">
            <h1 class="text-xl font-bold text-slate-900 dark:text-white">Stats</h1>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Your stats start once your business profile is live.</p>
        </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
