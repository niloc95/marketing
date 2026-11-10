<?php
/**
 * Settings, Plan tab (PlanSettings::viewData()).
 *
 * @var array<string,mixed> $plan
 */
helper('money');
$tz   = new DateTimeZone(service('businessContext')->timezone());
$date = static fn (?string $utc): string => $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('j F Y') : '';
$price = $plan['display'];
?>
<div class="max-w-2xl space-y-6">
    <section class="form-card">
        <h2 class="mb-2 font-bold">Your online bookings plan</h2>
        <p class="text-sm">
            <?= esc(match ($plan['state']) {
                'trial'       => 'Free trial until ' . $date($plan['trial_ends']) . ($plan['days_left'] !== null ? ' (' . $plan['days_left'] . ($plan['days_left'] === 1 ? ' day' : ' days') . ' left)' : '') . '.',
                'trial_ended' => 'Your free trial ended on ' . $date($plan['trial_ends']) . '. Customers cannot book online until you choose the plan.',
                'active'      => 'Paid until ' . $date($plan['paid_until']) . '. It renews each month by card through Paystack.',
                'past_due'    => 'Your last payment did not go through. Paystack tries your card again; please check it so customers can keep booking.',
                'cancelled'   => 'Not renewing. Online bookings stay on until ' . $date($plan['paid_until']) . '.',
                'ended'       => 'Your plan ended on ' . $date($plan['paid_until']) . '. Customers cannot book online until you choose it again.',
                default       => 'You have no plan yet.',
            }) ?>
        </p>
        <p class="mt-2 text-sm">
            You have <?= (int) $plan['people'] ?> bookable <?= (int) $plan['people'] === 1 ? 'person' : 'people' ?>, so your plan is <strong><?= esc($plan['tier']) ?></strong> at <?= esc($price) ?> a month.
        </p>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Your calendar, team, customers and settings always stay here, whatever the plan. Your place in discovery results never depends on it.</p>
        <?php if ($plan['can_subscribe']): ?>
            <form method="post" action="<?= base_url('bookings/settings/plan/subscribe') ?>" class="mt-4">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Pay <?= esc($price) ?> a month</button>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Paid by card through Paystack, renewing monthly until you stop it.<?= $plan['abroad'] ? ' Billed in South African rand by WebScheduler, a South African company, so your bank converts the amount and it may vary a little from month to month.' : '' ?> <?= $plan['state'] === 'trial' ? 'Paying now adds the month after your trial, so you lose no free days.' : '' ?></p>
            </form>
        <?php elseif ($plan['can_move']): ?>
            <div class="alert alert-info mt-4 text-sm">
                You are on <?= esc((string) $plan['paid_tier']) ?>, but with <?= (int) $plan['people'] ?> bookable <?= (int) $plan['people'] === 1 ? 'person' : 'people' ?> your plan is now <?= esc($plan['tier']) ?> at <?= esc($price) ?> a month.
                Moving charges <?= esc($price) ?> now, adds a month to your paid time, and stops the old payments.
            </div>
            <form method="post" action="<?= base_url('bookings/settings/plan/subscribe') ?>" class="mt-3">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Move to <?= esc($plan['tier']) ?></button>
            </form>
        <?php elseif ($plan['price'] === null): ?>
            <p class="mt-4 text-sm">The paid plan is not on sale here yet. Your calendar keeps working.</p>
        <?php endif ?>
        <details class="mt-4 text-sm">
            <summary class="cursor-pointer">The plans</summary>
            <ul class="mt-2 space-y-1">
                <?php foreach (config('Bookings')->planTiers() as $tier): ?>
                    <li><?= esc($tier['name']) ?>: <?= $tier['people'] === 1 ? 'one person' : ($tier['people'] === 0 ? 'any number of people' : 'up to ' . $tier['people'] . ' people') ?>, <?= esc(\App\Services\Bookings\Plans\BookingPlanService::display($tier, $plan['abroad'])) ?> a month</li>
                <?php endforeach ?>
            </ul>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">People are the team members customers can book.</p>
        </details>
        <?php if ($plan['renews']): ?>
            <form method="post" action="<?= base_url('bookings/settings/plan/cancel') ?>" class="mt-4">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost">Stop renewing</button>
            </form>
        <?php endif ?>
    </section>

    <?php if ($plan['payments'] !== []): ?>
        <section class="form-card">
            <h2 class="mb-2 font-bold">Payments</h2>
            <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <?php foreach ($plan['payments'] as $p): ?>
                    <li class="flex justify-between py-1"><span><?= esc($date((string) $p['paid_at'])) ?> <code class="text-xs text-slate-500"><?= esc((string) $p['reference']) ?></code></span><span><?= $plan['abroad'] ? 'Paid' : esc(money((int) $p['amount_cents'] / 100, 'ZAR')) ?></span></li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endif ?>
</div>
