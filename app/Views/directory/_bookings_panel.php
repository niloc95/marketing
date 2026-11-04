<?php
/**
 * Online bookings on the owner dashboard (docs/bookings/). Renders nothing
 * while bookings.enabled is off or the profile cannot use them, so the
 * dashboard is unchanged until the owner switches the feature on for all.
 *
 * @var array<string,mixed> $listing
 */
$setup = new App\Services\Bookings\BookingSetupService();
if ($setup->whyNot($listing) !== null) {
    return;
}
$on = $setup->isOn($listing);
?>
<div class="panel mt-6" id="bookings">
    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Online bookings</h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        <?= $on ? 'On. Manage your calendar, team and settings in your business area.' : 'Let customers book a time with you, with a calendar for your team and reminders.' ?>
    </p>
    <a class="btn btn-ghost mt-3" href="<?= base_url($on ? 'business' : 'manage/bookings') ?>"><?= $on ? 'Open your bookings' : 'Set up online bookings' ?></a>
</div>
