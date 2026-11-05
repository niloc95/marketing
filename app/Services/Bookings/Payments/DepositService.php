<?php

namespace App\Services\Bookings\Payments;

use App\Libraries\PayFast;
use App\Libraries\PaymentReference;
use App\Models\Bookings\BookingAppointmentModel as A;
use App\Models\Bookings\BookingPaymentModel as P;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\AppointmentStatus;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\BookingMetricsCache;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Notifications\BookingIntegrations;
use App\Services\Bookings\Notifications\NotificationQueue;
use Config\Services;
use Config\Sites;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Deposits for online bookings (Phase 8, decision D6): the customer pays the
 * business's own PayFast or Paystack account. We never hold the money, take
 * no fee, and refunds are done in the business's own gateway (D9).
 * Ported in spirit from Bookings' PayFastPaymentService, rebuilt on Local's
 * PayFast and Paystack clients (GatewayConfig hands them the business's keys).
 *
 * The only writer of xs_booking_payments, and of the payment columns of an
 * appointment. Payment also moves two statuses, and both stay here so the
 * rules sit together: a paid deposit takes a waiting booking to the
 * business's usual status (and only then are the booking messages queued),
 * and a deposit not paid in time cancels it quietly (no message was ever sent
 * about it).
 *
 * How a payment is known to be real:
 * - PayFast: its notification (book/payfast/notify), checked with the
 *   business's passphrase, PayFast's source address, PayFast's own answer
 *   and the amount (PayFast::checkNotification()).
 * - Paystack: we ask Paystack with the business's key (verify) when the
 *   customer comes back, and `bookings:payments` asks again for anything
 *   left open. No webhook: a Paystack account has one webhook address, which
 *   the business may already use for its own shop.
 */
class DepositService
{
    /** PayFast's smallest payment is R5; a smaller deposit is not asked for. */
    public const MIN_CENTS = 500;

    /** What the business sees for an appointment's payment_status. */
    public const LABELS = [
        A::PAYMENT_DUE        => 'Deposit due',
        A::PAYMENT_PAID       => 'Deposit paid',
        A::PAYMENT_REFUNDED   => 'Deposit refunded',
        A::PAYMENT_EXPIRED    => 'Deposit not paid in time',
        A::PAYMENT_REFUND_DUE => 'Deposit to refund',
    ];

    public const GATEWAY_LABELS = ['payfast' => 'PayFast', 'paystack' => 'Paystack', 'manual' => 'Marked paid'];

    /** One try at paying, as the business reads it. */
    public const PAYMENT_LABELS = [P::STARTED => 'not finished', P::PAID => 'paid', P::FAILED => 'not paid', P::REFUNDED => 'refunded'];

    private int $listingId;

    private DateTimeImmutable $now;

    private BookingAudit $audit;

    /** @param array<string,mixed> $listing */
    public function __construct(private array $listing, ?DateTimeImmutable $now = null)
    {
        $this->listingId = (int) $listing['id'];
        $this->now       = $now ?? new DateTimeImmutable('now');
        $this->audit     = new BookingAudit();
    }

    // What is asked

    /** The business's payment account, when it is on and complete. */
    public static function gatewayFor(int $listingId): ?GatewayConfig
    {
        $integrations = new BookingIntegrations($listingId);
        if (! $integrations->active('payment')) {
            return null;
        }
        $account = $integrations->account('payment');
        if ($account === null || ! in_array($account['provider'], ['payfast', 'paystack'], true)) {
            return null;
        }
        $config   = new GatewayConfig($account['provider'], $account['config']);
        $complete = $account['provider'] === 'paystack'
            ? $config->paystackSecretKey() !== ''
            : $config->payfastMerchantId() !== '' && $config->payfastMerchantKey() !== '' && $config->payfastPassphrase() !== '';

        return $complete ? $config : null;
    }

    public function gateway(): ?GatewayConfig
    {
        return self::gatewayFor($this->listingId);
    }

    /**
     * The deposit a service asks for, in cents, whether or not the business
     * can take it yet: the percentage of the price, in rand, at least R5.
     *
     * @param array<string,mixed> $service
     */
    public static function amountFor(array $service): ?int
    {
        $pct   = (int) ($service['deposit_pct'] ?? 0);
        $price = $service['price_cents'] ?? null;
        if ($pct <= 0 || $price === null || strtoupper((string) ($service['currency'] ?? 'ZAR')) !== 'ZAR') {
            return null;
        }
        $cents = (int) round((int) $price * min(100, $pct) / 100);

        return $cents >= self::MIN_CENTS ? $cents : null;
    }

    /**
     * The deposit a customer booking online pays now: the service asks for
     * one and the business has an account to take it. Without an account the
     * booking goes through without a deposit (SetupCheck says so).
     *
     * @param array<string,mixed> $service
     */
    public function depositFor(array $service): ?int
    {
        $cents = self::amountFor($service);

        return $cents !== null && $this->gateway() !== null ? $cents : null;
    }

    public function holdMinutes(): int
    {
        return max(15, BookingSettings::for($this->listingId)->int('payments.hold_minutes'));
    }

    /** A new online booking waits for its deposit until the hold runs out. */
    public function ask(int $appointmentId, int $cents): void
    {
        A::forBusiness($this->listingId)->update($appointmentId, [
            'payment_status'       => A::PAYMENT_DUE,
            'payment_amount_cents' => $cents,
            'payment_due_by'       => gmdate('Y-m-d H:i:s', $this->now->getTimestamp() + $this->holdMinutes() * MINUTE),
        ]);
    }

    /** @param array<string,mixed> $a */
    public function isWaiting(array $a): bool
    {
        return ($a['payment_status'] ?? '') === A::PAYMENT_DUE
            && in_array($a['status'], AppointmentStatus::UPCOMING, true)
            && (string) $a['payment_due_by'] > gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
    }

    // Paying

    /**
     * Start paying the deposit: Paystack's checkout address, or the signed
     * form that goes to PayFast.
     *
     * @param array<string,mixed> $a the appointment
     *
     * @return array{redirect:?string,form:?array{action:string,fields:array<string,string>},gateway:?string,error:?string}
     */
    public function start(array $a): array
    {
        $out = ['redirect' => null, 'form' => null, 'gateway' => null, 'error' => null];
        if (! $this->isWaiting($a)) {
            return ['error' => ($a['payment_status'] ?? '') === A::PAYMENT_DUE
                ? 'The time we held for you has run out. Please book again.'
                : 'There is no deposit waiting to be paid on this booking.'] + $out;
        }
        $gateway = $this->gateway();
        if ($gateway === null) {
            return ['error' => 'Paying online is not available right now. Please contact the business.'] + $out;
        }
        $customer = db_connect()->table('xs_booking_customers')->where('id', (int) $a['customer_id'])->where('listing_id', $this->listingId)->get()->getRowArray() ?? [];
        $email    = (string) ($customer['email'] ?? '');
        if ($gateway->gateway() === 'paystack' && $email === '') {
            return ['error' => 'We need your email address to take the deposit. Please contact the business.'] + $out;
        }

        $attempt = P::forBusiness($this->listingId)->where('appointment_id', (int) $a['id'])->countAllResults() + 1;
        $hash    = bin2hex(random_bytes(16));
        $payment = [
            'appointment_id' => (int) $a['id'],
            'hash'           => $hash,
            'reference'      => PaymentReference::forDeposit((int) $a['id'], $attempt),
            'gateway'        => $gateway->gateway(),
            'amount_cents'   => (int) $a['payment_amount_cents'],
            'currency'       => 'ZAR',
            'status'         => P::STARTED,
            'test'           => $gateway->isTest() ? 1 : 0,
        ];
        $id     = (int) P::forBusiness($this->listingId)->insert($payment);
        $return = listing_site_url($this->listing, 'book/paid/' . $hash);
        $what   = mb_substr('Deposit: ' . $a['service_name'], 0, 100);

        if ($gateway->gateway() === 'paystack') {
            $checkout = Services::bookingPaystack($gateway)->initialize($email, $payment['amount_cents'], $payment['reference'], $return, null, [
                'appointment' => (string) $a['reference'],
                'business'    => (string) $this->listing['display_name'],
            ]);
            if ($checkout === null) {
                P::forBusiness($this->listingId)->update($id, ['status' => P::FAILED, 'detail' => 'Paystack did not open a checkout']);

                return ['error' => 'We could not reach Paystack. Please try again in a minute.'] + $out;
            }

            return ['redirect' => $checkout['url'], 'gateway' => 'paystack'] + $out;
        }

        $payfast = Services::bookingPayFast($gateway);
        $when    = (new DateTimeImmutable((string) $a['start_at'], new DateTimeZone('UTC')))->setTimezone($this->timezone());
        $fields  = [
            'merchant_id'      => $gateway->payfastMerchantId(),
            'merchant_key'     => $gateway->payfastMerchantKey(),
            'return_url'       => $return,
            'cancel_url'       => $return,
            // The notification always comes to co.za: bookings are co.za only (D2).
            'notify_url'       => site_url_for(Sites::ZA, 'book/payfast/notify'),
            'name_first'       => mb_substr((string) ($customer['first_name'] ?? ''), 0, 100),
            'name_last'        => mb_substr((string) ($customer['last_name'] ?? ''), 0, 100),
            'email_address'    => $email,
            'm_payment_id'     => $payment['reference'],
            'amount'           => number_format($payment['amount_cents'] / 100, 2, '.', ''),
            'item_name'        => $what,
            'item_description' => mb_substr($this->listing['display_name'] . ', ' . $when->format('D j M Y H:i'), 0, 255),
            'custom_str1'      => $hash,
        ];
        $fields['signature'] = $payfast->signature($fields);

        return ['form' => ['action' => $payfast->processUrl(), 'fields' => $fields], 'gateway' => 'payfast'] + $out;
    }

    /**
     * The customer is back from the gateway (book/paid/{hash}): ask Paystack
     * whether it went through. PayFast tells us on its own (notify).
     *
     * @return array{listing:array<string,mixed>,appointment:array<string,mixed>,payment:array<string,mixed>}|null
     */
    public static function returned(string $hash, ?DateTimeImmutable $now = null): ?array
    {
        if (! preg_match('/^[0-9a-f]{32}$/', $hash)) {
            return null;
        }
        $payment = db_connect()->table('xs_booking_payments')->where('hash', $hash)->get()->getRowArray();
        $listing = $payment !== null ? (new DirectoryListingModel())->find((int) $payment['listing_id']) : null;
        if ($listing === null || $payment['appointment_id'] === null) {
            return null;
        }
        $svc = new self($listing, $now);
        if ($payment['gateway'] === 'paystack' && $payment['status'] === P::STARTED) {
            $svc->checkPaystack($payment);
            $payment = P::forBusiness((int) $listing['id'])->find((int) $payment['id']);
        }
        $appointment = A::forBusiness((int) $listing['id'])->find((int) $payment['appointment_id']);

        return is_array($appointment) ? ['listing' => $listing, 'appointment' => $appointment, 'payment' => $payment] : null;
    }

    /**
     * A PayFast notification for a deposit. Answers what happened, for the log.
     * Unknown references, other merchants and wrong amounts change nothing.
     */
    public static function payfastNotified(string $raw, string $ip, ?DateTimeImmutable $now = null): string
    {
        $fields    = (new PayFast())->parseNotification($raw);
        $reference = (string) ($fields['m_payment_id'] ?? '');
        $payment   = $reference !== '' ? db_connect()->table('xs_booking_payments')->where('reference', $reference)->where('gateway', 'payfast')->get()->getRowArray() : null;
        $listing   = $payment !== null ? (new DirectoryListingModel())->find((int) $payment['listing_id']) : null;
        if ($listing === null) {
            return 'unknown payment';
        }
        $svc     = new self($listing, $now);
        $gateway = $svc->gateway();
        if ($gateway === null || $gateway->gateway() !== 'payfast') {
            return 'the business has no PayFast account on';
        }
        $payfast = Services::bookingPayFast($gateway);
        if (($why = $payfast->checkNotification($fields, $raw, $ip, 'Booking deposit')) !== null) {
            return $why;
        }
        if (($fields['merchant_id'] ?? '') !== $gateway->payfastMerchantId()) {
            return 'another merchant';
        }
        $gross = isset($fields['amount_gross']) && is_numeric($fields['amount_gross']) ? (float) $fields['amount_gross'] : null;
        if (! $payfast->amountMatches($gross, number_format((int) $payment['amount_cents'] / 100, 2, '.', ''))) {
            return 'wrong amount';
        }
        $status = strtoupper((string) ($fields['payment_status'] ?? ''));
        if ($status === 'COMPLETE') {
            $svc->paid($payment, mb_substr((string) ($fields['pf_payment_id'] ?? ''), 0, 64), 'payfast');

            return 'paid';
        }
        if ($status === 'CANCELLED' && $payment['status'] === P::STARTED) {
            P::forBusiness((int) $listing['id'])->update((int) $payment['id'], ['status' => P::FAILED, 'detail' => 'PayFast: cancelled']);
        }

        return 'status ' . $status;
    }

    /**
     * Ask Paystack about a payment still open; a success with the right
     * amount and currency is paid, a failure is failed.
     *
     * @param array<string,mixed> $payment
     */
    public function checkPaystack(array $payment): void
    {
        $gateway = $this->gateway();
        if ($gateway === null || $gateway->gateway() !== 'paystack' || $payment['status'] !== P::STARTED) {
            return;
        }
        $tx = Services::bookingPaystack($gateway)->verify((string) $payment['reference']);
        if (! is_array($tx)) {
            return;
        }
        $status = (string) ($tx['status'] ?? '');
        if ($status === 'success'
            && (int) ($tx['amount'] ?? 0) === (int) $payment['amount_cents']
            && strtoupper((string) ($tx['currency'] ?? '')) === 'ZAR'
            && (string) ($tx['reference'] ?? '') === (string) $payment['reference']) {
            $this->paid($payment, mb_substr((string) ($tx['id'] ?? ''), 0, 64), 'paystack');
        } elseif ($status === 'failed' || $status === 'reversed') {
            P::forBusiness($this->listingId)->update((int) $payment['id'], ['status' => P::FAILED, 'detail' => 'Paystack: ' . $status]);
        }
    }

    /**
     * A deposit came in. Safe to call twice for the same payment: only the
     * first call changes anything.
     *
     * - Waiting: the booking goes to the business's usual status and the
     *   booking messages go out now (none went at booking time).
     * - Already let go (not paid in time): the booking comes back if its time
     *   is still free; otherwise the deposit is marked to refund.
     *
     * @param array<string,mixed> $payment
     */
    public function paid(array $payment, string $gatewayId, string $via, ?int $accountId = null): void
    {
        $nowUtc  = gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
        $changed = db_connect()->table('xs_booking_payments')
            ->where('id', (int) $payment['id'])->where('listing_id', $this->listingId)->where('status !=', P::PAID)
            ->update(['status' => P::PAID, 'gateway_id' => $gatewayId !== '' ? $gatewayId : null, 'paid_at' => $nowUtc, 'updated_at' => $nowUtc]);
        if (! $changed || db_connect()->affectedRows() === 0) {
            return;
        }
        $a = A::forBusiness($this->listingId)->find((int) $payment['appointment_id']);
        if (! is_array($a)) {
            return;
        }
        $id      = (int) $a['id'];
        $usual   = BookingSettings::for($this->listingId)->get('booking.default_appointment_status');
        $service = new AppointmentService($this->listing, null, null, $accountId, $this->now);

        if ($a['payment_status'] === A::PAYMENT_DUE && in_array($a['status'], AppointmentStatus::UPCOMING, true)) {
            $status = $a['status'] === A::STATUS_PENDING ? $usual : $a['status'];
            A::forBusiness($this->listingId)->update($id, ['payment_status' => A::PAYMENT_PAID, 'payment_due_by' => null, 'status' => $status]);
            $this->audit->record($this->listingId, $accountId, 'appointment.deposit_paid', 'appointment', $id, null, ['reference' => $payment['reference'], 'via' => $via]);
            $service->notify($id, NotificationQueue::statusEvent($status), $accountId === null);

            return;
        }

        if ($a['payment_status'] === A::PAYMENT_EXPIRED && $a['status'] === A::STATUS_CANCELLED) {
            $busy = (new AvailabilityService($this->listing, $this->now))->problem((int) $a['provider_id'], (int) $a['service_id'], (string) $a['start_at'], false, $id) === AvailabilityService::BUSY;
            if (! $busy && (string) $a['start_at'] > $nowUtc) {
                A::forBusiness($this->listingId)->update($id, ['payment_status' => A::PAYMENT_PAID, 'status' => $usual, 'cancelled_at' => null]);
                $this->audit->record($this->listingId, $accountId, 'appointment.deposit_paid_late', 'appointment', $id, null, ['reference' => $payment['reference'], 'via' => $via, 'kept' => true]);
                $service->notify($id, NotificationQueue::statusEvent($usual), true);

                return;
            }
        }

        if ($a['payment_status'] !== A::PAYMENT_PAID) {
            A::forBusiness($this->listingId)->update($id, ['payment_status' => A::PAYMENT_REFUND_DUE, 'payment_due_by' => null]);
        }
        // Paid for a booking that is gone, or paid twice: the business refunds it.
        $this->audit->record($this->listingId, $accountId, 'appointment.deposit_to_refund', 'appointment', $id, null, ['reference' => $payment['reference'], 'via' => $via]);
        BookingMetricsCache::forget($this->listingId);
    }

    // The business's side

    /**
     * The customer paid some other way (cash, a transfer): the deposit
     * waiting on a booking is marked paid.
     *
     * @return array{ok:bool,error:?string}
     */
    public function markPaid(int $appointmentId, ?int $accountId, ?int $memberId): array
    {
        $a = A::forBusiness($this->listingId)->find($appointmentId);
        if (! is_array($a) || ! in_array($a['payment_status'], [A::PAYMENT_DUE, A::PAYMENT_EXPIRED], true)) {
            return ['ok' => false, 'error' => 'There is no deposit waiting on this booking.'];
        }
        $id = (int) P::forBusiness($this->listingId)->insert([
            'appointment_id'        => $appointmentId,
            'hash'                  => bin2hex(random_bytes(16)),
            'reference'             => PaymentReference::forDeposit($appointmentId, P::forBusiness($this->listingId)->where('appointment_id', $appointmentId)->countAllResults() + 1),
            'gateway'               => 'manual',
            'amount_cents'          => (int) $a['payment_amount_cents'],
            'currency'              => 'ZAR',
            'status'                => P::STARTED,
            'recorded_by_member_id' => $memberId,
        ]);
        $this->paid(P::forBusiness($this->listingId)->find($id), '', 'manual', $accountId);

        return ['ok' => true, 'error' => null];
    }

    /**
     * The business refunded the deposit in its own gateway (we cannot: D9)
     * and says so here.
     *
     * @return array{ok:bool,error:?string}
     */
    public function markRefunded(int $appointmentId, ?int $accountId): array
    {
        $a = A::forBusiness($this->listingId)->find($appointmentId);
        if (! is_array($a) || ! in_array($a['payment_status'], [A::PAYMENT_PAID, A::PAYMENT_REFUND_DUE], true)) {
            return ['ok' => false, 'error' => 'There is no paid deposit on this booking.'];
        }
        $nowUtc = gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
        A::forBusiness($this->listingId)->update($appointmentId, ['payment_status' => A::PAYMENT_REFUNDED]);
        P::forBusiness($this->listingId)->where('appointment_id', $appointmentId)->where('status', P::PAID)
            ->set(['status' => P::REFUNDED, 'refunded_at' => $nowUtc])->update();
        $this->audit->record($this->listingId, $accountId, 'appointment.deposit_refunded', 'appointment', $appointmentId);
        BookingMetricsCache::forget($this->listingId);

        return ['ok' => true, 'error' => null];
    }

    /**
     * For the appointment details: the deposit's state and every try at
     * paying it. Null when the booking never had a deposit.
     *
     * @param array<string,mixed> $a
     *
     * @return array<string,mixed>|null
     */
    public function summary(array $a): ?array
    {
        $rows = P::forBusiness($this->listingId)->where('appointment_id', (int) $a['id'])->orderBy('id', 'DESC')->findAll(20);
        if (($a['payment_status'] ?? A::PAYMENT_NONE) === A::PAYMENT_NONE && $rows === []) {
            return null;
        }
        $tz = $this->timezone();

        return [
            'status'        => (string) $a['payment_status'],
            'label'         => self::LABELS[$a['payment_status']] ?? 'No deposit',
            'amount'        => CatalogueService::priceText($a['payment_amount_cents'] !== null ? (int) $a['payment_amount_cents'] : null),
            'due_by'        => $a['payment_due_by'] ? (new DateTimeImmutable((string) $a['payment_due_by'], new DateTimeZone('UTC')))->setTimezone($tz)->format('H:i') : null,
            'can_mark_paid' => in_array($a['payment_status'], [A::PAYMENT_DUE, A::PAYMENT_EXPIRED], true),
            'can_refund'    => in_array($a['payment_status'], [A::PAYMENT_PAID, A::PAYMENT_REFUND_DUE], true),
            'payments'      => array_map(static fn (array $p): array => [
                'reference' => $p['reference'],
                'gateway'   => self::GATEWAY_LABELS[$p['gateway']] ?? $p['gateway'],
                'amount'    => CatalogueService::priceText((int) $p['amount_cents'], (string) $p['currency']),
                'status'    => self::PAYMENT_LABELS[$p['status']] ?? $p['status'],
                'test'      => (bool) $p['test'],
                'at'        => (new DateTimeImmutable((string) ($p['paid_at'] ?? $p['created_at']), new DateTimeZone('UTC')))->setTimezone($tz)->format('j M H:i'),
            ], $rows),
        ];
    }

    /**
     * What the customer's booking page says about the deposit, or null when
     * there is none.
     *
     * @param array<string,mixed> $a
     *
     * @return array{status:string,waiting:bool,amount:string,due_by:string,gateway:string,checking:bool,test:bool,text:string}|null
     */
    public function forCustomer(array $a): ?array
    {
        $status = (string) ($a['payment_status'] ?? A::PAYMENT_NONE);
        if ($status === A::PAYMENT_NONE) {
            return null;
        }
        $gateway = $this->gateway();
        $amount  = CatalogueService::priceText($a['payment_amount_cents'] !== null ? (int) $a['payment_amount_cents'] : 0);
        $waiting = $this->isWaiting($a) && $gateway !== null;
        $name    = (string) $this->listing['display_name'];

        return [
            'status'   => $status,
            'waiting'  => $waiting,
            'amount'   => $amount,
            'due_by'   => $a['payment_due_by'] ? (new DateTimeImmutable((string) $a['payment_due_by'], new DateTimeZone('UTC')))->setTimezone($this->timezone())->format('H:i') : '',
            'gateway'  => $gateway !== null ? self::GATEWAY_LABELS[$gateway->gateway()] : '',
            'checking' => $waiting && P::forBusiness($this->listingId)->where('appointment_id', (int) $a['id'])->where('status', P::STARTED)->countAllResults() > 0,
            'test'     => $gateway !== null && $gateway->isTest(),
            'text'     => match ($status) {
                A::PAYMENT_PAID       => 'Deposit of ' . $amount . ' paid. Thank you.',
                A::PAYMENT_REFUNDED   => 'Your deposit of ' . $amount . ' was refunded by ' . $name . '.',
                A::PAYMENT_REFUND_DUE => 'Your deposit of ' . $amount . ' came in after the time was freed. ' . $name . ' will refund it.',
                A::PAYMENT_EXPIRED    => 'The deposit was not paid in time, so the time was freed for others. You are welcome to book again.',
                default               => $gateway === null
                    ? 'Paying online is not available right now. Please contact ' . $name . '.'
                    : 'The time we held for you has run out. You are welcome to book again.',
            },
        ];
    }

    // The Payments page

    /**
     * Tries at paying, newest first, with the appointment and customer, for
     * the people in scope (null = everyone).
     *
     * @param list<int>|null $scope
     *
     * @return list<array<string,mixed>>
     */
    public function payments(?array $scope, string $status, int $limit, int $offset = 0): array
    {
        if ($scope === []) {
            return [];
        }
        $q = db_connect()->table('xs_booking_payments p')
            ->select('p.*, a.hash AS appointment_hash, a.start_at, a.service_name, a.provider_name, a.payment_status, c.first_name, c.last_name')
            ->join('xs_booking_appointments a', 'a.id = p.appointment_id AND a.listing_id = p.listing_id', 'left')
            ->join('xs_booking_customers c', 'c.id = a.customer_id AND c.listing_id = a.listing_id', 'left')
            ->where('p.listing_id', $this->listingId);
        if ($scope !== null) {
            $q->whereIn('a.provider_id', $scope);
        }
        if (isset(self::PAYMENT_LABELS[$status])) {
            $q->where('p.status', $status);
        }

        return $q->orderBy('p.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
    }

    /**
     * Bookings whose deposit the business should refund: paid after the
     * time was freed, or paid on a booking since cancelled.
     *
     * @param list<int>|null $scope
     *
     * @return list<array<string,mixed>>
     */
    public function toRefund(?array $scope): array
    {
        if ($scope === []) {
            return [];
        }
        $q = A::forBusiness($this->listingId)
            ->groupStart()
                ->where('payment_status', A::PAYMENT_REFUND_DUE)
                ->orGroupStart()->where('payment_status', A::PAYMENT_PAID)->where('status', A::STATUS_CANCELLED)->groupEnd()
            ->groupEnd();
        if ($scope !== null) {
            $q->whereIn('provider_id', $scope);
        }

        return $q->orderBy('start_at', 'DESC')->findAll(100);
    }

    /**
     * Real deposits received this month (test payments left out), for the
     * people in scope.
     *
     * @param list<int>|null $scope
     */
    public function receivedThisMonth(?array $scope): int
    {
        if ($scope === []) {
            return 0;
        }
        // The first of the month in the business's own time.
        $from = (new DateTimeImmutable('@' . $this->now->getTimestamp()))->setTimezone($this->timezone())->modify('first day of this month')->setTime(0, 0);
        $q = db_connect()->table('xs_booking_payments p')->selectSum('p.amount_cents', 'cents')
            ->join('xs_booking_appointments a', 'a.id = p.appointment_id AND a.listing_id = p.listing_id', 'left')
            ->where('p.listing_id', $this->listingId)->whereIn('p.status', [P::PAID, P::REFUNDED])->where('p.test', 0)
            ->where('p.paid_at >=', gmdate('Y-m-d H:i:s', $from->getTimestamp()));
        if ($scope !== null) {
            $q->whereIn('a.provider_id', $scope);
        }

        return (int) ($q->get()->getRowArray()['cents'] ?? 0);
    }

    // The sweep (bookings:payments)

    /**
     * Ask Paystack about payments left open, then let go of every time whose
     * deposit was not paid in time. Runs every five minutes.
     *
     * @return array{checked:int,released:int}
     */
    public static function sweep(?DateTimeImmutable $now = null): array
    {
        $now    = $now ?? new DateTimeImmutable('now');
        $nowUtc = gmdate('Y-m-d H:i:s', $now->getTimestamp());
        $db     = db_connect();
        $out    = ['checked' => 0, 'released' => 0];

        // Open Paystack payments from the last day, older than a minute (the
        // customer may still be on the checkout).
        $open = $db->table('xs_booking_payments')->where('gateway', 'paystack')->where('status', P::STARTED)
            ->where('created_at >=', gmdate('Y-m-d H:i:s', $now->getTimestamp() - DAY))
            ->where('created_at <=', gmdate('Y-m-d H:i:s', $now->getTimestamp() - MINUTE))
            ->orderBy('id')->limit(200)->get()->getResultArray();
        foreach (self::byListing($open) as [$listing, $rows]) {
            $svc = new self($listing, $now);
            foreach ($rows as $p) {
                $svc->checkPaystack($p);
                $out['checked']++;
            }
        }

        $late = $db->table('xs_booking_appointments')->where('payment_status', A::PAYMENT_DUE)->where('payment_due_by <', $nowUtc)
            ->orderBy('id')->limit(500)->get()->getResultArray();
        foreach (self::byListing($late) as [$listing, $rows]) {
            $svc = new self($listing, $now);
            foreach ($rows as $a) {
                $out['released'] += $svc->release((int) $a['id']) ? 1 : 0;
            }
        }

        return $out;
    }

    /** Let go of a booking whose deposit did not come in time. */
    private function release(int $id): bool
    {
        $a = A::forBusiness($this->listingId)->find($id);
        if (! is_array($a) || $a['payment_status'] !== A::PAYMENT_DUE) {
            return false;
        }
        $data = ['payment_status' => A::PAYMENT_EXPIRED, 'payment_due_by' => null];
        if (in_array($a['status'], AppointmentStatus::UPCOMING, true)) {
            $data += ['status' => A::STATUS_CANCELLED, 'cancelled_at' => gmdate('Y-m-d H:i:s', $this->now->getTimestamp())];
        }
        A::forBusiness($this->listingId)->update($id, $data);
        $this->audit->record($this->listingId, null, 'appointment.deposit_expired', 'appointment', $id, $a['status'], $data['status'] ?? $a['status']);
        BookingMetricsCache::forget($this->listingId);

        return true;
    }

    /**
     * Rows grouped by business, with the business, skipping businesses that
     * are gone.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{0:array<string,mixed>,1:list<array<string,mixed>>}>
     */
    private static function byListing(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row['listing_id']][] = $row;
        }
        $out = [];
        foreach ($groups as $listingId => $list) {
            $listing = (new DirectoryListingModel())->find($listingId);
            if ($listing !== null) {
                $out[] = [$listing, $list];
            }
        }

        return $out;
    }

    private function timezone(): DateTimeZone
    {
        $tz = (string) ($this->listing['timezone'] ?? '');

        return new DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Johannesburg');
    }
}
