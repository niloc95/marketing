<?php
/**
 * Sent messages (Business\Notifications::sent): the delivery log, newest
 * first, with addresses masked. Admins only.
 *
 * @var list<array<string,mixed>>                 $rows  up to 51: the 51st means there is a next page
 * @var array{channel:string,outcome:string}      $filters
 * @var int                                       $page
 * @var string                                    $tz
 */
use App\Services\Bookings\Notifications\DeliveryLog;
use Config\BookingNotifications as Catalog;

$zone  = new DateTimeZone($tz);
$more  = count($rows) > 50;
$rows  = array_slice($rows, 0, 50);
$query = static fn (array $extra): string => http_build_query(array_filter($extra + $filters));
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('bookings/settings/notifications') ?>">Settings, Messages</a></p>
<div class="mb-4 flex flex-wrap items-center gap-3">
    <h1 class="text-xl font-bold text-slate-900 dark:text-white">Sent messages</h1>
    <a class="btn btn-ghost btn-xs ml-auto" href="<?= base_url('bookings/notifications/sent.csv') ?>">Download</a>
</div>
<form method="get" action="<?= base_url('bookings/notifications/sent') ?>" class="mb-4 flex flex-wrap items-end gap-3">
    <div class="field mb-0">
        <label for="f-channel">Channel</label>
        <select id="f-channel" name="channel">
            <option value="">All</option>
            <?php foreach (Catalog::CHANNEL_LABELS as $k => $label): ?>
                <option value="<?= $k ?>"<?= $filters['channel'] === $k ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="field mb-0">
        <label for="f-outcome">Result</label>
        <select id="f-outcome" name="outcome">
            <option value="">All</option>
            <?php foreach (DeliveryLog::LABELS as $k => $label): ?>
                <option value="<?= $k ?>"<?= $filters['outcome'] === $k ? ' selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button type="submit" class="btn btn-ghost">Show</button>
</form>

<?php if ($rows === []): ?>
    <p class="text-sm text-slate-600 dark:text-slate-300">No messages yet.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Time</th><th>Message</th><th>To</th><th>Result</th><th>Booking</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="whitespace-nowrap"><?= esc((new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->setTimezone($zone)->format('j M H:i')) ?></td>
                        <td><?= esc(Catalog::EVENTS[$r['event']] ?? $r['event']) ?>, <?= esc(Catalog::CHANNEL_LABELS[$r['channel']] ?? $r['channel']) ?></td>
                        <td><?= $r['recipient_type'] === 'team' ? 'Team: ' : '' ?><?= esc((string) $r['recipient']) ?></td>
                        <td>
                            <?= esc(DeliveryLog::LABELS[$r['outcome']] ?? $r['outcome']) ?>
                            <?php if (! empty($r['error'])): ?><span class="block text-xs text-slate-500 dark:text-slate-400"><?= esc((string) $r['error']) ?></span><?php endif ?>
                        </td>
                        <td class="whitespace-nowrap"><?= esc((string) ($r['reference'] ?? '')) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <nav class="mt-4 flex gap-2" aria-label="Pages">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-xs" href="?<?= esc($query(['page' => $page - 1]), 'attr') ?>">Newer</a><?php endif ?>
        <?php if ($more): ?><a class="btn btn-ghost btn-xs" href="?<?= esc($query(['page' => $page + 1]), 'attr') ?>">Older</a><?php endif ?>
    </nav>
<?php endif ?>
<?= $this->endSection() ?>
