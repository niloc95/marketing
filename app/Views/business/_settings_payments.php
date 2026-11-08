<?php
/**
 * Settings, Payments tab (PaymentSettings::viewData()): the business's own
 * PayFast or Paystack account for deposits, and the hold.
 *
 * @var array<string,mixed> $payments
 */
use App\Services\Bookings\Notifications\BookingIntegrations;

helper('money');
$acc    = $payments['account'];
$fields = BookingIntegrations::PROVIDERS['payment'];
$input  = static fn (string $provider, string $field, string $label, bool $secret) => '<div class="field"><label for="pay-' . $provider . '-' . $field . '">' . esc($label) . '</label>'
    . '<input id="pay-' . $provider . '-' . $field . '" type="' . ($secret ? 'password' : 'text') . '" name="cfg[' . $provider . '][' . $field . ']" autocomplete="off" value="' . esc($acc['values'][$provider . '.' . $field] ?? '', 'attr') . '"'
    . ($secret && ! empty($acc['saved'][$provider . '.' . $field]) ? ' placeholder="Saved. Leave empty to keep it."' : '') . '></div>';
?>
<div class="max-w-2xl space-y-6">
    <section class="form-card">
        <h2 class="mb-2 font-bold">Deposits</h2>
        <p class="text-sm text-slate-600 dark:text-slate-300">Customers who book online pay the deposit into your own PayFast or Paystack account. The money goes straight to you: WebScheduler Local takes no part of it and never holds it. Refunds are done in your PayFast or Paystack account.</p>
        <?php if ($payments['services'] === []): ?>
            <p class="mt-3 text-sm">None of your services asks for a deposit yet. Set a deposit percentage on a <a class="underline" href="<?= base_url('bookings/services') ?>">service</a>.</p>
        <?php else: ?>
            <ul class="mt-3 space-y-1 text-sm">
                <?php foreach ($payments['services'] as $s): ?>
                    <li><a class="underline" href="<?= base_url('bookings/services/' . $s['id']) ?>"><?= esc($s['name']) ?></a>: <?= (int) $s['pct'] ?>%<?= $s['deposit'] !== null ? ', ' . esc(money($s['deposit'] / 100, 'ZAR')) : ', below the R5 smallest payment, so no deposit is asked' ?></li>
                <?php endforeach ?>
            </ul>
            <p class="mt-3 text-sm font-semibold"><?= $payments['ready'] ? 'Customers pay these deposits when they book.' : 'No account is on, so customers book these without a deposit.' ?></p>
        <?php endif ?>
    </section>

    <section class="form-card" id="account">
        <h2 class="mb-2 font-bold">Your payment account</h2>
        <?php if (! $payments['canEncrypt']): ?>
            <p class="alert alert-info">Connecting a payment account is not available yet. Customers book without a deposit.</p>
        <?php else: ?>
            <?php if ($acc['provider'] !== ''): ?>
                <p class="mb-3 text-sm">
                    <?= $acc['health'] === 'ok' ? 'Checked: the details work.' : ($acc['health'] === 'failed' ? esc((string) $acc['last_error']) : 'Not checked yet.') ?>
                </p>
            <?php endif ?>
            <form method="post" action="<?= base_url('bookings/settings/payments') ?>" data-provider-form>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="pay-provider">Take deposits with</label>
                    <select id="pay-provider" name="provider" data-provider-select>
                        <option value="">No account</option>
                        <?php foreach (array_keys($fields) as $provider): ?>
                            <option value="<?= $provider ?>"<?= $acc['provider'] === $provider ? ' selected' : '' ?>><?= esc(BookingIntegrations::PROVIDER_LABELS[$provider]) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <fieldset class="mb-3" data-provider="payfast"<?= $acc['provider'] !== 'payfast' ? ' hidden' : '' ?>>
                    <legend class="sr-only">PayFast</legend>
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">Find these in your PayFast dashboard under Settings, Developer settings. Set a passphrase there if you have none: it is what proves a payment notice really comes from PayFast.</p>
                    <?= $input('payfast', 'merchant_id', 'Merchant ID', false) ?>
                    <?= $input('payfast', 'merchant_key', 'Merchant key', false) ?>
                    <?= $input('payfast', 'passphrase', 'Passphrase', true) ?>
                    <div class="field">
                        <label for="pay-payfast-mode">Mode</label>
                        <select id="pay-payfast-mode" name="cfg[payfast][mode]">
                            <option value="live"<?= ($acc['values']['payfast.mode'] ?? 'live') !== 'test' ? ' selected' : '' ?>>Live: real payments</option>
                            <option value="test"<?= ($acc['values']['payfast.mode'] ?? '') === 'test' ? ' selected' : '' ?>>Test: PayFast sandbox, no real money</option>
                        </select>
                    </div>
                </fieldset>

                <fieldset class="mb-3" data-provider="paystack"<?= $acc['provider'] !== 'paystack' ? ' hidden' : '' ?>>
                    <legend class="sr-only">Paystack</legend>
                    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">Find it in your Paystack dashboard under Settings, API keys and webhooks. A key starting with sk_test_ takes test payments only. You do not need to set a webhook: we check each payment with Paystack ourselves.</p>
                    <?= $input('paystack', 'secret_key', 'Secret key', true) ?>
                </fieldset>

                <div class="field">
                    <label for="pay-hold">Hold the time while the customer pays, in minutes</label>
                    <input id="pay-hold" type="number" name="hold_minutes" min="15" max="120" step="5" value="<?= (int) $payments['hold'] ?>">
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">If the deposit is not paid by then, the time is freed for others.</p>
                </div>
                <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1"<?= $acc['active'] || $acc['provider'] === '' ? ' checked' : '' ?>> Take deposits</label>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
            <?php if ($acc['provider'] !== ''): ?>
                <form method="post" action="<?= base_url('bookings/settings/payments/check') ?>" class="mt-4">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost">Check the details with <?= esc(BookingIntegrations::PROVIDER_LABELS[$acc['provider']]) ?></button>
                </form>
            <?php endif ?>
            <p class="mt-4 text-sm"><a class="underline" href="<?= base_url('bookings/payments') ?>">See payments</a></p>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Keys are stored encrypted and never shown again. Every admin of the business gets an email when this account changes.</p>
        <?php endif ?>
    </section>
</div>
