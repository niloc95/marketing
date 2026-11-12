<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Online bookings | Admin']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * /admin/bookings (AdminBookings::index): counts and setup per business,
 * never appointment or customer contents (D12).
 *
 * @var list<array<string,mixed>> $rows
 * @var bool                      $planRequired
 */
$date = static fn (?string $utc): string => $utc ? date('j M Y', strtotime($utc . ' UTC')) : '';
$planText = static fn (array $p): string => match ($p['state']) {
    'trial'       => 'Trial to ' . $date($p['trial_ends']),
    'trial_ended' => 'Trial ended ' . $date($p['trial_ends']),
    'active'      => 'Paid to ' . $date($p['paid_until']),
    'past_due'    => 'Payment failed',
    'cancelled'   => 'Not renewing, to ' . $date($p['paid_until']),
    'ended'       => 'Ended ' . $date($p['paid_until']),
    default       => 'No plan',
};
?>
<div class="shell-page">
    <p class="text-sm text-slate-600 dark:text-slate-300">Counts and setup only. A business's appointments and customers are its own: opening them for support needs a reason, and the business sees it in its audit log. <?= $planRequired ? 'The Bookings plan is required.' : 'The Bookings plan is not required yet (bookings.planRequired).' ?></p>

    <?php if ($rows === []): ?>
        <div class="empty mt-4">No business has turned on online bookings yet.</div>
    <?php else: ?>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-500 dark:text-slate-400">
                        <th class="py-2 pr-3">Business</th><th class="py-2 pr-3">Plan</th><th class="py-2 pr-3 text-right">People</th>
                        <th class="py-2 pr-3 text-right">Upcoming</th><th class="py-2 pr-3 text-right">Booked 30 days</th><th class="py-2 pr-3 text-right">Online 30 days</th>
                        <th class="py-2 pr-3 text-right">Customers</th><th class="py-2 pr-3">Setup</th><th class="py-2 pr-3">Connected</th><th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): $l = $r['listing']; ?>
                        <tr class="border-t border-slate-100 align-top dark:border-slate-800">
                            <td class="py-2 pr-3">
                                <a class="font-semibold underline" href="<?= base_url('admin/edit/' . (int) $l['id']) ?>"><?= esc((string) $l['display_name']) ?></a>
                                <span class="block text-xs text-slate-500"><?= (int) $l['bookings_enabled'] === 1 ? 'On since ' . esc($date($l['bookings_enabled_at'])) : 'Off' ?></span>
                            </td>
                            <td class="py-2 pr-3"><?= esc($planText($r['plan'])) ?></td>
                            <td class="py-2 pr-3 text-right"><?= $r['members'] ?></td>
                            <td class="py-2 pr-3 text-right"><?= $r['upcoming'] ?></td>
                            <td class="py-2 pr-3 text-right"><?= $r['last30'] ?></td>
                            <td class="py-2 pr-3 text-right"><?= $r['online'] ?></td>
                            <td class="py-2 pr-3 text-right"><?= $r['customers'] ?></td>
                            <td class="py-2 pr-3"><?= $r['problems'] === 0 ? 'Ready' : $r['problems'] . ' to fix' ?></td>
                            <td class="py-2 pr-3"><?= esc(implode(', ', $r['channels'])) ?></td>
                            <td class="py-2">
                                <details>
                                    <summary class="cursor-pointer text-sm">Actions</summary>
                                    <form method="post" action="<?= base_url('admin/bookings/' . (int) $l['id'] . '/extend') ?>" class="mt-2 flex gap-2">
                                        <?= csrf_field() ?>
                                        <input type="number" name="days" min="1" max="365" value="30" class="w-20" aria-label="Days">
                                        <button type="submit" class="btn btn-ghost">Add free days</button>
                                    </form>
                                    <form method="post" action="<?= base_url('admin/bookings/' . (int) $l['id'] . '/support') ?>" class="mt-2">
                                        <?= csrf_field() ?>
                                        <label class="text-xs" for="reason-<?= (int) $l['id'] ?>">Why open their bookings (they see this)</label>
                                        <input id="reason-<?= (int) $l['id'] ?>" type="text" name="reason" minlength="10" maxlength="300" required>
                                        <button type="submit" class="btn btn-ghost mt-1">Open for support</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Online bookings<?= $this->endSection() ?>
