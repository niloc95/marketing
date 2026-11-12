<?php
/**
 * Sent messages (Business\Notifications::sent): the delivery log, newest
 * first, with addresses masked. Admins only.
 *
 * @var list<array<string,mixed>>                 $rows  one page, 50
 * @var array{channel:string,outcome:string}      $filters
 * @var array{page:int,totalPages:int,perPage:int,total:int} $pager  pager_result()
 * @var string                                    $tz
 */
use App\Services\Bookings\Notifications\DeliveryLog;
use Config\BookingNotifications as Catalog;

$zone  = new DateTimeZone($tz);
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('business/settings/notifications') ?>">Settings, Messages</a></p>
<form method="get" action="<?= base_url('business/notifications/sent') ?>" class="mb-4 flex flex-wrap items-end gap-3">
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
    <?= view('directory/_pager', ['result' => $pager, 'baseUrl' => base_url('business/notifications/sent'), 'query' => $filters, 'noun' => 'messages'], ['saveData' => false]) ?>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Sent messages<?= $this->endSection() ?>

<?= $this->section('shell_actions') ?>
<a class="btn btn-ghost btn-xs" href="<?= base_url('business/notifications/sent.csv') ?>" download>Download</a>
<?= $this->endSection() ?>
