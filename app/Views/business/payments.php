<?php
/**
 * Payments (Business\Payments::index): deposits to refund, then every try at
 * paying a deposit, newest first.
 *
 * @var list<array<string,mixed>> $rows     up to 51: the 51st means there is a next page
 * @var list<array<string,mixed>> $refund   appointments whose deposit to refund
 * @var int                       $received real deposits this month, cents
 * @var bool                      $ready    a payment account is on
 * @var bool                      $canSetUp may open the Payments settings
 * @var string                    $status
 * @var int                       $page
 * @var string                    $tz
 */
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Payments\DepositService;

$zone  = new DateTimeZone($tz);
$more  = count($rows) > 50;
$rows  = array_slice($rows, 0, 50);
$query = static fn (array $extra): string => http_build_query(array_filter($extra + ['status' => $status]));
$local = static fn (?string $utc, string $format): string => $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone)->format($format) : '';
$open  = static fn (string $startUtc, string $hash): string => base_url('bookings/calendar?view=day&date=' . (new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d') . '&open=' . $hash);
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<h1 class="text-2xl font-bold text-slate-900 dark:text-white">Payments</h1>
<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Deposits customers paid into your own PayFast or Paystack account when they booked. <?= esc(CatalogueService::priceText($received)) ?> received this month.</p>
<?php if (! $ready): ?>
    <p class="alert alert-info mt-4">No payment account is on, so customers book without a deposit.<?php if ($canSetUp): ?> <a class="underline" href="<?= base_url('bookings/settings/payments') ?>">Connect PayFast or Paystack</a>.<?php endif ?></p>
<?php endif ?>

<?php if ($refund !== []): ?>
    <section class="panel mt-6">
        <h2 class="strength-panel-title">To refund</h2>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Paid on a booking that was cancelled, or after its time was freed. Refund in your PayFast or Paystack account if your terms say so, then mark it refunded in the booking.</p>
        <ul class="stats-list dash-list mt-3">
            <?php foreach ($refund as $a): ?>
                <li>
                    <a class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-300" href="<?= $open((string) $a['start_at'], (string) $a['hash']) ?>"><?= esc((string) $a['reference']) ?>, <?= esc((string) $a['service_name']) ?>, <?= esc($local((string) $a['start_at'], 'D j M H:i')) ?></a>
                    <span class="stats-list-n"><?= esc(CatalogueService::priceText((int) $a['payment_amount_cents'])) ?>, <?= esc(DepositService::LABELS[$a['payment_status']] ?? '') ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    </section>
<?php endif ?>

<form method="get" action="<?= base_url('bookings/payments') ?>" class="mt-6 mb-4 flex flex-wrap items-end gap-3">
    <div class="field mb-0">
        <label for="f-status">Result</label>
        <select id="f-status" name="status" data-autosubmit>
            <option value="">All</option>
            <?php foreach (DepositService::PAYMENT_LABELS as $k => $label): ?>
                <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= esc(ucfirst($label)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button type="submit" class="btn btn-ghost" data-autosubmit-button>Show</button>
</form>

<?php if ($rows === []): ?>
    <p class="text-sm text-slate-600 dark:text-slate-300">No payments yet.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Time</th><th>Customer</th><th>Booking</th><th>Amount</th><th>Result</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="whitespace-nowrap"><?= esc($local((string) ($r['paid_at'] ?? $r['created_at']), 'j M H:i')) ?></td>
                        <td><?= esc(trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')) ?: 'No customer') ?></td>
                        <td>
                            <?php if (! empty($r['appointment_hash'])): ?>
                                <a class="underline" href="<?= $open((string) $r['start_at'], (string) $r['appointment_hash']) ?>"><?= esc((string) $r['service_name']) ?>, <?= esc($local((string) $r['start_at'], 'D j M H:i')) ?></a>
                            <?php endif ?>
                            <span class="block text-xs text-slate-500 dark:text-slate-400"><?= esc((string) $r['reference']) ?></span>
                        </td>
                        <td class="whitespace-nowrap"><?= esc(CatalogueService::priceText((int) $r['amount_cents'], (string) $r['currency'])) ?></td>
                        <td>
                            <?= esc(ucfirst(DepositService::PAYMENT_LABELS[$r['status']] ?? $r['status'])) ?>, <?= esc(DepositService::GATEWAY_LABELS[$r['gateway']] ?? $r['gateway']) ?>
                            <?php if ((int) $r['test'] === 1): ?><span class="block text-xs text-amber-700 dark:text-amber-300">Test, no real money</span><?php endif ?>
                        </td>
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
