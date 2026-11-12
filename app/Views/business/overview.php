<?php
/**
 * /business without online bookings: every Free profile, and a paid one with
 * bookings off or lapsed. The profile at a glance, what to fill in next, and
 * online bookings (turn on, or what opens them). The bookings dashboard
 * (business/home) takes over once bookings are usable.
 *
 * @var array<string,mixed> $listing
 * @var bool                $owner     may edit the profile (admins)
 * @var array<string,mixed> $strength  ListingQualityService::strength()
 * @var bool                $premium   the badge (co.za) or Plus (.app)
 * @var string|null         $bookingsWhyNot
 * @var string|null         $bookingsUnavailable
 * @var bool                $bookingsWereOn
 */
$za     = site()->forRow($listing) === Config\Sites::ZA;
$status = (string) ($listing['status'] ?? '');
$live   = $status === 'published';
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="panel">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="section-title mb-0"><?= esc((string) $listing['display_name']) ?></h2>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        <?php if ($live): ?>
                            Your business profile is live and can be discovered on the platform.
                        <?php elseif ($status === 'pending'): ?>
                            Your business profile is waiting to go live.
                        <?php else: ?>
                            Your business profile is not live.
                        <?php endif ?>
                    </p>
                </div>
                <?php if ($premium): ?>
                    <span class="pill pill-verified"><?= $za ? 'Verified' : 'Plus' ?></span>
                <?php else: ?>
                    <span class="pill pill-unpublished">Free</span>
                <?php endif ?>
            </div>
            <p class="mt-4 flex flex-wrap gap-2">
                <?php if ($live): ?>
                    <a class="btn btn-ghost btn-xs" href="<?= esc(listing_url($listing), 'attr') ?>">View profile</a>
                <?php endif ?>
                <?php if ($owner): ?>
                    <a class="btn btn-ghost btn-xs" href="<?= base_url('business/profile/details') ?>">Edit profile</a>
                <?php endif ?>
            </p>
        </div>

        <?php if ($owner): ?>
            <?= view('directory/_strength_panel', [
                'strength'   => $strength,
                'floor'      => (int) config('Directory')->recentMinQuality,
                'target'     => (int) config('Directory')->qualityTarget,
                'anchorBase' => 'business/profile',
            ]) ?>
        <?php endif ?>
    </div>

    <div class="space-y-6">
        <div class="panel" id="bookings">
            <h2 class="section-title">Online bookings</h2>
            <?php if ($bookingsUnavailable !== null): ?>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300"><?= esc($bookingsUnavailable) ?></p>
            <?php elseif ($bookingsWhyNot !== null): ?>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Let customers book a time with you, with a calendar for your team and reminders. <?= esc($bookingsWhyNot) ?></p>
                <?php if ($bookingsWereOn): ?>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Your calendar, customers and settings are kept and come back when you renew.</p>
                <?php endif ?>
                <?php if ($owner): ?>
                    <a class="btn btn-primary mt-3" href="<?= base_url('business/badge') ?>"><?= $za ? 'Get the Verified Business badge' : 'Get Plus' ?></a>
                <?php endif ?>
            <?php elseif ($owner): ?>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Let customers book a time with you, with a calendar for your team and reminders.<?php if (App\Services\Bookings\Plans\BookingPlanService::required()): ?> Free for <?= (int) config('Bookings')->trialDays ?> days to try.<?php endif ?></p>
                <form method="post" action="<?= base_url('business/bookings/turn-on') ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary">Turn on online bookings</button>
                </form>
            <?php else: ?>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Online bookings are off for this business. An admin of the business can turn them on.</p>
            <?php endif ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
