<?php
/**
 * A part of the business area this business cannot use yet: what it is, why
 * not, and what opens it (the Verified Business badge on co.za, Plus on
 * .app, or turning online bookings on). Rendered by BusinessAuth for the
 * bookings pages and by the profile pages for Team, Branches and Jobs.
 * Nothing is deleted while a part is locked.
 *
 * @var string $feature bookings, team, branches, jobs
 * @var string $reason  why it is locked, from the service that decides
 */
$context = service('businessContext');
$listing = $context->listing();
$za      = site()->forRow($listing) === Config\Sites::ZA;
$premium = listing_has_premium($listing);
$setup   = new App\Services\Bookings\BookingSetupService();

$about = [
    'bookings' => ['Online bookings', 'Customers book a time on your profile. You get a calendar for your team, reminders and a list of your customers.'],
    'team'     => ['Team', 'Show the people who work with you on your profile, with their photo, role and qualifications.'],
    'branches' => ['Branches', 'Add your other places of business, each with its own address, hours and map pin.'],
    'jobs'     => ['Jobs', 'Post vacancies on the jobs board and link them from your profile.'],
][$feature] ?? ['This part', ''];

// What opens it: the upgrade, or (with the badge or Plus) turning bookings on.
$canTurnOn = $feature === 'bookings' && $premium && $setup->whyNot($listing) === null && ! $setup->isOn($listing)
    && $context->can('profile.billing');
$upgrade = ! $premium && ($feature !== 'bookings' || $setup->unavailable($listing) === null);
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-xl panel" data-locked="<?= esc($feature, 'attr') ?>">
    <?php if ($upgrade): ?>
        <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            <?= lucide('lock', 'h-3.5 w-3.5') ?>
            <?= $za ? 'Comes with the Verified Business badge' : 'Comes with Plus' ?>
        </p>
    <?php endif ?>
    <h2 class="mt-2 section-title"><?= esc($about[0]) ?></h2>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><?= esc($about[1]) ?></p>
    <p class="mt-3 text-sm text-slate-600 dark:text-slate-300"><?= esc($reason) ?></p>

    <?php if ($canTurnOn): ?>
        <form method="post" action="<?= base_url('business/bookings/turn-on') ?>" class="mt-4">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Turn on online bookings</button>
        </form>
    <?php elseif ($upgrade && $context->can('profile.billing')): ?>
        <a class="btn btn-primary mt-4" href="<?= base_url('business/badge') ?>"><?= $za ? 'Get the Verified Business badge' : 'Get Plus' ?></a>
        <a class="btn btn-ghost mt-4" href="<?= base_url($za ? 'verified' : 'plus') ?>">What you get</a>
    <?php else: ?>
        <a class="btn btn-ghost mt-4" href="<?= base_url('business') ?>">Back</a>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
