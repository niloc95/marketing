<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\PaymentReference;
use App\Libraries\Paystack;
use App\Models\DirectoryVerificationItnRejectionModel;
use App\Models\DirectoryVerificationModel;
use App\Models\ProfileSetupOrderModel;
use App\Services\Bookings\Plans\BookingPlanService;

/**
 * What Paystack tells us, turned into payments: the webhook and the browser's
 * return from checkout, for the badge, Plus and the profile setup service.
 *
 * The webhook is unauthenticated and public, like PayFast's ITN, so a charge
 * is believed only when all of these hold:
 *   1. the HMAC SHA512 signature over the raw body is ours (webhook only),
 *   2. it came from one of Paystack's published addresses (webhook only),
 *   3. Paystack's own `verify` answer, over a connection we opened, says the
 *      transaction succeeded (both paths; this is what the callback rests on),
 *   4. the amount and currency are the ones we asked for.
 * The browser's return trip carries only a reference, so it can at most make
 * us ask Paystack sooner than the webhook would.
 *
 * Both paths end in VerificationService::recordPayment(),
 * ProfileSetupService::recordPayment() or, for the online bookings plan,
 * BookingPlanService::recordPayment(), which are idempotent: whichever
 * arrives second changes nothing.
 *
 * Outcomes are short strings for the log. One starting 'retry' asks the
 * controller to answer 500, so Paystack sends the webhook again later: that is
 * only for "we could not reach Paystack to check", never for a refusal.
 */
class PaystackPayments
{
    /** How long after a term ends a late renewal is still looked for. */
    private const LATE_RENEWAL_DAYS = 14;

    private Paystack $paystack;
    private VerificationService $billing;
    private DirectoryVerificationModel $verifications;
    private ProfileSetupOrderModel $orders;

    public function __construct(?Paystack $paystack = null, ?VerificationService $billing = null)
    {
        $this->paystack      = $paystack ?? service('paystack');
        $this->billing       = $billing ?? new VerificationService(null, $this->paystack);
        $this->verifications = new DirectoryVerificationModel();
        $this->orders        = new ProfileSetupOrderModel();
    }

    public function handleWebhook(string $raw, string $signature, string $ip): string
    {
        if (! $this->paystack->isConfigured()) {
            return 'ignored: Paystack is not configured';
        }
        if (trim($raw) === '') {
            return 'ignored: empty body';
        }
        if (! $this->paystack->validWebhookSignature($raw, $signature)) {
            return $this->reject('signature does not match', [], $ip);
        }
        if (! $this->paystack->isWebhookIp($ip)) {
            return $this->reject('not from a Paystack address', [], $ip);
        }

        $body  = json_decode($raw, true);
        $event = is_array($body) ? (string) ($body['event'] ?? '') : '';
        $data  = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];

        return match ($event) {
            'charge.success'                                 => $this->confirmReference((string) ($data['reference'] ?? ''), $ip),
            'subscription.create'                            => $this->subscriptionCreated($data),
            'subscription.disable', 'subscription.not_renew' => $this->subscriptionStopped($data, $event),
            'invoice.payment_failed'                         => $this->paymentFailed($data),
            default                                          => 'ignored: ' . ($event === '' ? 'no event' : $event),
        };
    }

    /**
     * Ask Paystack about a reference and bank it if it is a payment of ours.
     * The callback calls this with the reference in its query string; the
     * webhook with the one in the charge.
     */
    public function confirmReference(string $reference, string $ip = ''): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return 'ignored: no reference';
        }

        $tx = $this->paystack->verify($reference);
        if ($tx === null) {
            return 'retry: Paystack did not confirm ' . $reference;
        }

        $status = (string) ($tx['status'] ?? '');
        if ($status !== 'success') {
            // Abandoned or failed: nothing was paid, the row waits as it was.
            return 'noted: ' . ($status === '' ? 'no status' : $status) . ' for ' . $reference;
        }

        $fields = $this->fieldsOf($tx);
        if (strtoupper((string) ($tx['currency'] ?? '')) !== 'ZAR') {
            return $this->reject('currency ' . (string) ($tx['currency'] ?? 'missing') . ' is not ZAR', $fields, $ip);
        }

        $metadata = $this->metadataOf($tx);
        if (PaymentReference::isSetup((string) ($tx['reference'] ?? '')) || isset($metadata['order_id'])) {
            return $this->profileSetupCharge($tx, $metadata, $fields, $ip);
        }
        // Online bookings' own plan (Phase 10): our reference, its id in the
        // metadata, or a renewal on a booking plan's mandate.
        $customer = (string) ($tx['customer']['customer_code'] ?? '');
        $planCode = Paystack::planCodeOf($tx['plan'] ?? null) ?: Paystack::planCodeOf($tx['plan_object'] ?? null);
        if (PaymentReference::isBookingsPlan((string) ($tx['reference'] ?? '')) || isset($metadata['booking_plan_id'])
            || $this->bookingPlans()->rowForCharge('', [], $customer, $planCode) !== null) {
            return $this->bookingPlanCharge($tx, $metadata, $fields, $ip);
        }

        return $this->subscriptionCharge($tx, $metadata, $fields, $ip);
    }

    /**
     * Renewals the webhook should have told us about, asked for instead. A
     * Paystack renewal has no browser return, so without this a missed
     * webhook means a paid badge lapses on the next sweep.
     *
     * Rows on a Paystack mandate whose term ends by tomorrow, or ended in the
     * last LATE_RENEWAL_DAYS (Paystack retries a declined card for days), not
     * cancelled. Each charge since a few days before the term ended goes
     * through confirmReference(), so every check the webhook makes still
     * applies, and a charge already banked answers 'duplicate'.
     *
     * Runs in directory:verifications:sweep before lapseExpired(), and on its
     * own as directory:paystack:catch-up.
     *
     * @return list<string> one outcome per charge, or per row Paystack would not answer for
     */
    public function catchUpRenewals(): array
    {
        if (! $this->paystack->isConfigured()) {
            return [];
        }

        $rows = $this->verifications
            ->where('provider', DirectoryVerificationModel::PROVIDER_PAYSTACK)
            ->whereIn('state', [DirectoryVerificationModel::STATE_ACTIVE, DirectoryVerificationModel::STATE_LAPSED])
            ->where('paystack_customer_code IS NOT NULL')
            ->where('cancelled_at', null)
            ->where('paid_until <=', date('Y-m-d', strtotime('+1 day')))
            ->where('paid_until >=', date('Y-m-d', strtotime('-' . self::LATE_RENEWAL_DAYS . ' days')))
            ->findAll();

        $outcomes = [];
        foreach ($rows as $row) {
            $since      = strtotime((string) $row['paid_until'] . ' -3 days');
            $references = $this->paystack->successfulCharges((string) $row['paystack_customer_code'], (int) $since);
            if ($references === null) {
                $outcomes[] = 'retry: Paystack did not list the charges for verification ' . (int) $row['id'];

                continue;
            }
            foreach ($references as $reference) {
                $outcomes[] = $this->confirmReference($reference);
            }
        }

        return $outcomes;
    }

    // ------------------------------------------------------------ the charges

    /**
     * @param array<string,mixed>  $tx
     * @param array<string,mixed>  $metadata
     * @param array<string,string> $fields
     */
    private function subscriptionCharge(array $tx, array $metadata, array $fields, string $ip): string
    {
        $reference = (string) ($tx['reference'] ?? '');
        $customer  = (string) ($tx['customer']['customer_code'] ?? '');
        $planCode  = Paystack::planCodeOf($tx['plan'] ?? null) ?: Paystack::planCodeOf($tx['plan_object'] ?? null);

        [$row, $how] = $this->rowForCharge($reference, $metadata, $customer, $planCode);
        if ($row === null) {
            return $this->reject('no matching subscription', $fields, $ip);
        }

        $isSwitch = $how === 'switch';
        $expected = (string) ($isSwitch ? $row['switch_amount'] : $row['amount']);
        $cents    = (int) ($tx['amount'] ?? -1);
        if ($cents !== Paystack::cents($expected)) {
            return $this->reject(sprintf('amount %s does not match the expected %s', number_format($cents / 100, 2, '.', ''), $expected), $fields, $ip, (int) $row['id']);
        }

        // The first charge of a plan opens a new mandate; a renewal (found by
        // its customer and plan) carries on the one the row already has.
        $mandate = [];
        if ($how !== 'renewal' && $customer !== '' && $planCode !== '') {
            $mandate = [
                'provider'                   => DirectoryVerificationModel::PROVIDER_PAYSTACK,
                'paystack_customer_code'     => $customer,
                'paystack_plan_code'         => $planCode,
                'paystack_subscription_code' => null,
                'paystack_email_token'       => null,
            ];
        }
        $paidAt = strtotime((string) ($tx['paid_at'] ?? $tx['paidAt'] ?? '')) ?: time();

        $outcome = $this->billing->recordPayment(
            $row,
            'paystack:' . (string) ($tx['id'] ?? $reference),
            'COMPLETE',
            $cents / 100,
            $tx,
            [
                'reference'  => $reference,
                'switch'     => $isSwitch,
                'mandate'    => $mandate,
                'anchor_day' => $mandate === [] ? null : (int) date('j', $paidAt),
            ]
        );

        // The subscription opens just after its first charge; if Paystack has
        // it already, keep its code now so cancelling needs no lookup later.
        // subscription.create, or the first cancel, fills it in otherwise.
        if ($outcome === 'applied' && $mandate !== []) {
            $fresh = $this->verifications->find((int) $row['id']);
            if (is_array($fresh)) {
                $this->billing->paystackSubscription($fresh);
            }
        }

        return $outcome . ': verification ' . (int) $row['id'] . ' (' . $how . ')';
    }

    /**
     * Which row a charge pays for, and how we know: our reference ('new'),
     * a pending switch's reference ('switch'), the row id in the metadata
     * (a stale tab, 'new' or 'switch' as the metadata says), or the customer
     * and plan of a Paystack renewal ('renewal').
     *
     * @param array<string,mixed> $metadata
     *
     * @return array{0:array<string,mixed>|null,1:string}
     */
    private function rowForCharge(string $reference, array $metadata, string $customer, string $planCode): array
    {
        if (($row = $this->verifications->findByPaymentId($reference)) !== null) {
            return [$row, 'new'];
        }
        if (($row = $this->verifications->findBySwitchPaymentId($reference)) !== null) {
            return [$row, 'switch'];
        }
        if (! empty($metadata['verification_id'])) {
            $row = $this->verifications->find((int) $metadata['verification_id']);
            if (is_array($row)) {
                $switch = ($metadata['purpose'] ?? '') === 'switch' && trim((string) ($row['switch_m_payment_id'] ?? '')) !== '';

                return [$row, $switch ? 'switch' : 'new'];
            }
        }
        $row = $this->verifications->findByPaystackMandate($customer, $planCode);

        return [$row, 'renewal'];
    }

    /**
     * @param array<string,mixed>  $tx
     * @param array<string,mixed>  $metadata
     * @param array<string,string> $fields
     */
    private function profileSetupCharge(array $tx, array $metadata, array $fields, string $ip): string
    {
        $order = $this->orders->findByReference((string) ($tx['reference'] ?? ''));
        if ($order === null && ! empty($metadata['order_id'])) {
            $found = $this->orders->find((int) $metadata['order_id']);
            $order = is_array($found) ? $found : null;
        }
        if ($order === null) {
            return $this->reject('Profile setup: no matching order', $fields, $ip);
        }

        $cents = (int) ($tx['amount'] ?? -1);
        if ($cents !== Paystack::cents((string) $order['amount'])) {
            return $this->reject(sprintf('Profile setup: amount %s does not match the expected %s', number_format($cents / 100, 2, '.', ''), (string) $order['amount']), $fields, $ip);
        }

        return (new ProfileSetupService())->recordPayment($order, 'paystack:' . (string) ($tx['id'] ?? ''));
    }

    /**
     * @param array<string,mixed>  $tx
     * @param array<string,mixed>  $metadata
     * @param array<string,string> $fields
     */
    private function bookingPlanCharge(array $tx, array $metadata, array $fields, string $ip): string
    {
        $reference = (string) ($tx['reference'] ?? '');
        $customer  = (string) ($tx['customer']['customer_code'] ?? '');
        $planCode  = Paystack::planCodeOf($tx['plan'] ?? null) ?: Paystack::planCodeOf($tx['plan_object'] ?? null);
        $row       = $this->bookingPlans()->rowForCharge($reference, $metadata, $customer, $planCode);
        if ($row === null) {
            return $this->reject('Bookings plan: no matching plan', $fields, $ip);
        }
        $cents = (int) ($tx['amount'] ?? -1);
        if ($row['amount_cents'] === null || $cents !== (int) $row['amount_cents']) {
            return $this->reject(sprintf('Bookings plan: amount %s does not match the expected %s', number_format($cents / 100, 2, '.', ''), number_format((int) $row['amount_cents'] / 100, 2, '.', '')), $fields, $ip);
        }
        $renewal = $row['paystack_customer_code'] === $customer && $row['paystack_plan_code'] === $planCode && $reference !== (string) $row['payment_reference'];
        $mandate = ! $renewal && $customer !== '' && $planCode !== '' ? ['customer' => $customer, 'plan' => $planCode] : [];
        $paidAt  = strtotime((string) ($tx['paid_at'] ?? $tx['paidAt'] ?? '')) ?: time();

        return $this->bookingPlans()->recordPayment($row, 'paystack:' . (string) ($tx['id'] ?? $reference), $cents, $reference, $mandate, $paidAt)
            . ': booking plan ' . (int) $row['id'];
    }

    private function bookingPlans(): BookingPlanService
    {
        return new BookingPlanService($this->paystack);
    }

    // ------------------------------------------------------- subscription news

    /** @param array<string,mixed> $data */
    private function subscriptionCreated(array $data): string
    {
        $row = $this->verifications->findByPaystackMandate(
            (string) ($data['customer']['customer_code'] ?? ''),
            Paystack::planCodeOf($data['plan'] ?? null)
        );
        $code  = (string) ($data['subscription_code'] ?? '');
        $token = (string) ($data['email_token'] ?? '');
        if ($row === null && $code !== '' && $token !== '') {
            $plan = $this->bookingPlans()->rowForCharge('', [], (string) ($data['customer']['customer_code'] ?? ''), Paystack::planCodeOf($data['plan'] ?? null));
            if ($plan !== null) {
                $this->bookingPlans()->subscriptionCreated($plan, $code, $token);

                return 'stored: subscription for booking plan ' . (int) $plan['id'];
            }
        }
        if ($row === null || $code === '' || $token === '') {
            return 'ignored: subscription.create for no known mandate';
        }

        $this->verifications->update((int) $row['id'], [
            'paystack_subscription_code' => $code,
            'paystack_email_token'       => $token,
        ]);

        return 'stored: subscription for verification ' . (int) $row['id'];
    }

    /**
     * The subscriber stopped renewal at Paystack (its manage page, or their
     * bank), or Paystack disabled it. Like an owner cancel from the
     * dashboard: the badge or Plus stays until the paid date, then lapses.
     * Our own disable after a switch names a subscription the row no longer
     * holds, so it matches nothing and changes nothing.
     *
     * @param array<string,mixed> $data
     */
    private function subscriptionStopped(array $data, string $event): string
    {
        $row = $this->verifications->findByPaystackSubscription((string) ($data['subscription_code'] ?? ''));
        if ($row === null && ($plan = $this->bookingPlans()->rowForSubscription((string) ($data['subscription_code'] ?? ''))) !== null) {
            return $this->bookingPlans()->subscriptionStopped($plan);
        }
        if ($row === null || $row['state'] !== DirectoryVerificationModel::STATE_ACTIVE) {
            return 'ignored: ' . $event . ' for no active row';
        }
        if (! empty($row['cancelled_at'])) {
            return 'ignored: ' . $event . ', verification ' . (int) $row['id'] . ' already cancelled';
        }

        $this->verifications->update((int) $row['id'], ['cancelled_at' => date('Y-m-d H:i:s')]);
        $this->billing->notifyAdmin((int) $row['listing_id'], 'stopped at Paystack (' . $event . '); it stays on until its paid date');

        return 'cancelled: verification ' . (int) $row['id'] . ' (' . $event . ')';
    }

    /**
     * A renewal charge failed. Paystack tries again on its own; we tell the
     * owner, with Paystack's page for updating the card, before the badge
     * or Plus lapses on its paid date.
     *
     * @param array<string,mixed> $data
     */
    private function paymentFailed(array $data): string
    {
        $sub  = is_array($data['subscription'] ?? null) ? $data['subscription'] : [];
        $code = (string) ($sub['subscription_code'] ?? '');
        $row  = $this->verifications->findByPaystackSubscription($code)
            ?? $this->verifications->findByPaystackMandate(
                (string) ($data['customer']['customer_code'] ?? ''),
                Paystack::planCodeOf($sub['plan'] ?? ($data['plan'] ?? null))
            );
        if ($row === null) {
            $plan = $this->bookingPlans()->rowForSubscription($code)
                ?? $this->bookingPlans()->rowForCharge('', [], (string) ($data['customer']['customer_code'] ?? ''), Paystack::planCodeOf($sub['plan'] ?? ($data['plan'] ?? null)));
            if ($plan !== null) {
                return $this->bookingPlans()->paymentFailed($plan, $this->paystack->manageLink($code ?: (string) ($plan['paystack_subscription_code'] ?? '')));
            }

            return 'ignored: invoice.payment_failed for no known mandate';
        }

        $this->billing->sendPaymentFailed($row, $this->paystack->manageLink($code ?: (string) ($row['paystack_subscription_code'] ?? '')));

        return 'mailed: payment failed for verification ' . (int) $row['id'];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The handful of fields the rejection table keeps, in its PayFast shaped
     * columns. Never the customer's email or card details.
     *
     * @param array<string,mixed> $tx
     *
     * @return array<string,string>
     */
    private function fieldsOf(array $tx): array
    {
        return [
            'pf_payment_id'  => 'paystack:' . (string) ($tx['id'] ?? ''),
            'm_payment_id'   => (string) ($tx['reference'] ?? ''),
            'payment_status' => (string) ($tx['status'] ?? ''),
            'amount_gross'   => isset($tx['amount']) ? number_format((int) $tx['amount'] / 100, 2, '.', '') : '',
        ];
    }

    /**
     * @param array<string,mixed> $tx
     *
     * @return array<string,mixed>
     */
    private function metadataOf(array $tx): array
    {
        $meta = $tx['metadata'] ?? [];
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) ? $meta : [];
    }

    /** @param array<string,string> $fields */
    private function reject(string $reason, array $fields, string $ip, ?int $verificationId = null): string
    {
        $message = 'Paystack: ' . $reason . '.';
        log_message('warning', $message . (isset($fields['m_payment_id']) ? ' [reference=' . Mailer::oneLine($fields['m_payment_id']) . ']' : ''));
        (new DirectoryVerificationItnRejectionModel())->record($message, $fields, $ip, $verificationId);

        return 'rejected: ' . $reason;
    }
}
