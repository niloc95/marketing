<?php
/**
 * "Take bookings online", for the owner signed in through /manage
 * (ManageBookings). Phase 1 of online bookings: turning on makes the owner an
 * admin of the business; customers see nothing until a later phase.
 *
 * @var array<string,mixed> $listing
 * @var bool                $isOn
 * @var string|null         $whyNot
 */
?>
<?= $this->extend('layouts/public') ?>
<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Online bookings | ' . config('Directory')->siteName(), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <h1 class="mb-1.5 text-xl font-bold text-slate-900 dark:text-white">Online bookings</h1>
            <p class="mb-5 text-sm text-slate-500 dark:text-slate-400"><?= esc((string) $listing['display_name']) ?></p>
            <?php if ($isOn): ?>
                <p class="mb-5 text-sm">Online bookings are on for this business.</p>
                <a class="btn btn-primary btn-block" href="<?= base_url('business') ?>">Open your bookings</a>
            <?php elseif ($whyNot !== null): ?>
                <div class="alert alert-info"><?= esc($whyNot) ?></div>
            <?php else: ?>
                <p class="mb-3 text-sm">Let customers book a time with you, keep a calendar for your team and send reminders. You set it up first; customers see nothing until you are ready.</p>
<?php if (App\Services\Bookings\Plans\BookingPlanService::required()): helper('money'); $tiers = config('Bookings')->planTiers(); ?>
                    <p class="mb-3 text-sm font-semibold">Free for <?= (int) config('Bookings')->trialDays ?> days, then from <?= esc(App\Services\Bookings\Plans\BookingPlanService::display($tiers[0], site()->forRow($listing) === Config\Sites::APP)) ?> a month, by the size of your team. No card needed to start.</p>
                <?php endif ?>
                <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">You become the admin of the business, signed in with <?= esc((string) $listing['email']) ?>. You choose a password on the next page.</p>
                <form method="post" action="<?= base_url('manage/bookings') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-block">Turn on online bookings</button>
                </form>
            <?php endif ?>
            <p class="mt-5 text-sm"><a class="underline" href="<?= base_url('manage/edit') ?>">Back to your business profile</a></p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
