<?php
/**
 * @var list<array{member_id:int,listing_id:int,role:string,display_name:string,slug:string}> $businesses
 * @var int $current
 */
$roles = config('BookingPermissions')->roleLabels;
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-lg">
    <h1 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Choose a business</h1>
    <?php if ($businesses === []): ?>
        <div class="panel">
            <p class="text-sm text-slate-600 dark:text-slate-300">Your account is not part of a business with online bookings turned on.</p>
            <p class="mt-3 text-sm">Own a business on WebScheduler Local? <a class="underline" href="<?= base_url('manage') ?>">Sign in to your business profile</a> and turn on online bookings there.</p>
        </div>
    <?php else: ?>
        <ul class="space-y-2">
            <?php foreach ($businesses as $b): ?>
                <li>
                    <form method="post" action="<?= base_url('business/choose') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="listing_id" value="<?= (int) $b['listing_id'] ?>">
                        <button type="submit" class="panel flex w-full items-center justify-between text-left hover:border-brand-ocean">
                            <span>
                                <span class="block font-semibold"><?= esc($b['display_name']) ?></span>
                                <span class="text-sm text-slate-500 dark:text-slate-400"><?= esc($roles[$b['role']] ?? $b['role']) ?></span>
                            </span>
                            <?php if ($b['listing_id'] === $current): ?>
                                <span class="pill pill-published">Current</span>
                            <?php endif ?>
                        </button>
                    </form>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
