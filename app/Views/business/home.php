<?php
/**
 * The /business landing page. The full dashboard (today's bookings, team
 * availability, alerts) arrives in Phase 7; until then this is the way in to
 * what exists.
 *
 * @var array<string,mixed> $listing
 * @var array<string,mixed> $member
 */
$roles = config('BookingPermissions')->roleLabels;
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<h1 class="text-2xl font-bold text-slate-900 dark:text-white"><?= esc((string) $listing['display_name']) ?></h1>
<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">You are signed in as <?= esc($roles[$member['role']] ?? (string) $member['role']) ?>.</p>

<div class="mt-6 grid gap-4 sm:grid-cols-2">
    <div class="panel">
        <h2 class="font-bold">Online bookings are being set up</h2>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Your services, team, calendar and the Book online button on your profile come next. Nothing is shown to customers yet.</p>
    </div>
    <div class="panel">
        <h2 class="font-bold">Your business profile</h2>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Your name, address and opening hours come from your business profile.</p>
        <p class="mt-3 flex flex-wrap gap-2">
            <a class="btn btn-ghost btn-xs" href="<?= esc(listing_url($listing), 'attr') ?>">View profile</a>
            <a class="btn btn-ghost btn-xs" href="<?= base_url('manage') ?>">Edit profile</a>
        </p>
    </div>
</div>
<?= $this->endSection() ?>
