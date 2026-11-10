<?php
/**
 * Online bookings on the owner dashboard (docs/bookings/). Renders nothing
 * while bookings.enabled is off or the profile cannot use them, so the
 * dashboard is unchanged until the owner switches the feature on for all.
 *
 * @var array<string,mixed> $listing
 */
$setup = new App\Services\Bookings\BookingSetupService();
if ($setup->unavailable($listing) !== null) {
    return;
}
$on = $setup->isOn($listing);
// Bookings come with the badge (co.za) or Plus (.app): without it, the
// upgrade is offered, and a business that had bookings keeps its way in.
$premium = listing_has_premium($listing);
$za      = site()->forRow($listing) === Config\Sites::ZA;
?>
<div class="panel mt-6" id="bookings">
    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Online bookings</h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        <?= $on ? 'On. Manage your calendar, team and settings in your business area.' : 'Let customers book a time with you, with a calendar for your team and reminders.' ?>
        <?php if (! $premium): ?><?= esc(App\Services\Bookings\BookingSetupService::premiumNeeded(site()->forRow($listing))) ?><?php elseif (! $on && App\Services\Bookings\Plans\BookingPlanService::required()): ?>Free for <?= (int) config('Bookings')->trialDays ?> days to try.<?php endif ?>
    </p>
    <?php if ($on || $premium): ?>
        <a class="btn btn-ghost mt-3" href="<?= base_url($on ? 'business' : 'business/calendar') ?>"><?= $on ? 'Open your bookings' : 'Set up online bookings' ?></a>
    <?php else: ?>
        <a class="btn btn-ghost mt-3" href="<?= base_url($za ? 'verified' : 'plus') ?>"><?= $za ? 'See the Verified Business badge' : 'See Plus' ?></a>
    <?php endif ?>
</div>
