<?php

namespace App\Services\Bookings\Plans;

use App\Libraries\Mailer;
use App\Libraries\PaymentReference;
use App\Libraries\Paystack;
use App\Models\Bookings\BookingPlanModel as Plan;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\BookingAudit;
use App\Services\VerificationService;
use Config\Sites;
use DateTimeImmutable;

/**
 * The Bookings plan (Phase 10, decision D1): what a business pays us for
 * online bookings, a monthly Paystack subscription after a free trial. The
 * only writer of xs_booking_plans and xs_booking_plan_payments.
 *
 * - Off (`bookings.planRequired` false) nothing here applies: allows() says
 *   yes, as before the plan existed.
 * - On: turning bookings on starts the trial once per business (a second
 *   turn on never restarts it). While the trial runs, or the plan is paid
 *   up (plus a few days' grace while Paystack retries a card), customers can
 *   book and the API answers. After that the business keeps its calendar and
 *   data in /business, and the booking page and API stop until it pays.
 * - The plan never changes discovery ranking or the quality score.
 * - Paystack's charges come in through PaystackPayments (webhook and the
 *   browser's return), which hands a charge of ours to recordPayment();
 *   each charge counts once (unique Paystack id).
 */
class BookingPlanService
{
    private Plan $plans;

    private DateTimeImmutable $now;

    public function __construct(private ?Paystack $paystack = null, ?DateTimeImmutable $now = null)
    {
        $this->plans = new Plan();
        $this->now   = $now ?? new DateTimeImmutable('now');
    }

    public static function required(): bool
    {
        return config('Bookings')->planRequired();
    }

    /** @return array<string,mixed>|null */
    public function forListing(int $listingId): ?array
    {
        $row = $this->plans->where('listing_id', $listingId)->first();

        return is_array($row) ? $row : null;
    }

    /** May this business take bookings online and use the API under the plan? */
    public function allows(array $listing): bool
    {
        return ! self::required() || $this->status((int) $listing['id'])['allows'];
    }

    /**
     * Where the business stands, for the Plan tab, the banner and the admin.
     *
     * @return array{required:bool,state:string,allows:bool,trial_ends:?string,paid_until:?string,days_left:?int,price:string,can_subscribe:bool,renews:bool}
     *   state: none, trial, trial_ended, active, past_due, cancelled (paid up, not renewing), ended
     */
    public function status(int $listingId): array
    {
        $config = config('Bookings');
        $row    = $this->forListing($listingId);
        $now    = $this->nowUtc();
        $state  = 'none';
        $allows = false;
        $days   = null;
        if ($row !== null) {
            $paid  = $row['paid_until'] !== null ? (string) $row['paid_until'] : null;
            $grace = $paid !== null ? gmdate('Y-m-d H:i:s', strtotime($paid . ' UTC') + $config->planGraceDays * 86400) : null;
            if ($row['state'] === Plan::TRIAL) {
                $allows = (string) $row['trial_ends_at'] > $now;
                $state  = $allows ? 'trial' : 'trial_ended';
                $days   = $allows ? (int) ceil((strtotime((string) $row['trial_ends_at'] . ' UTC') - $this->now->getTimestamp()) / 86400) : 0;
            } elseif ($row['state'] === Plan::ACTIVE) {
                $state  = $paid !== null && $paid > $now ? 'active' : ($grace !== null && $grace > $now ? 'past_due' : 'ended');
                $allows = $state !== 'ended';
            } else {
                $allows = $paid !== null && $paid > $now;
                $state  = $allows ? 'cancelled' : 'ended';
            }
        }

        return [
            'required'      => self::required(),
            'state'         => $state,
            'allows'        => $allows,
            'trial_ends'    => $row['trial_ends_at'] ?? null,
            'paid_until'    => $row['paid_until'] ?? null,
            'days_left'     => $days,
            'price'         => $config->planPrice(),
            'can_subscribe' => $config->planPrice() !== '' && in_array($state, ['none', 'trial', 'trial_ended', 'cancelled', 'ended'], true),
            'renews'        => $row !== null && $row['state'] === Plan::ACTIVE && $row['cancelled_at'] === null,
        ];
    }

    /** Start the free trial, once per business. Returns the row. */
    public function startTrial(int $listingId, ?int $accountId): array
    {
        $row = $this->forListing($listingId);
        if ($row !== null) {
            return $row;
        }
        $this->plans->insert([
            'listing_id'    => $listingId,
            'state'         => Plan::TRIAL,
            'trial_ends_at' => gmdate('Y-m-d H:i:s', $this->now->getTimestamp() + config('Bookings')->trialDays * 86400),
        ]);
        (new BookingAudit())->record($listingId, $accountId, 'plan.trial_started', 'plan');

        return (array) $this->forListing($listingId);
    }

    /**
     * Open a Paystack checkout for the plan. Null when it cannot be opened
     * (no price set, Paystack not configured or not answering).
     *
     * @return array{url:string,access_code:string}|null
     */
    public function checkout(array $listing, string $email, string $returnUrl): ?array
    {
        $price    = config('Bookings')->planPrice();
        $paystack = $this->paystack();
        if ($price === '' || ! $paystack->isConfigured() || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $row      = $this->startTrial((int) $listing['id'], null);
        $planCode = (new VerificationService(null, $paystack))->paystackPlanCode('bookings', 'monthly', $price);
        if ($planCode === null) {
            return null;
        }

        $this->plans->builder()->set('payment_attempts', 'payment_attempts + 1', false)->where('id', (int) $row['id'])->update();
        $attempts  = (int) ($this->plans->find((int) $row['id'])['payment_attempts'] ?? 1);
        $reference = PaymentReference::make(PaymentReference::BOOKINGS_PLAN, (int) $row['id'], max(1, $attempts));
        $this->plans->update((int) $row['id'], ['payment_reference' => $reference, 'amount_cents' => Paystack::cents($price)]);

        return $paystack->initialize($email, Paystack::cents($price), $reference, $returnUrl, $planCode, [
            'booking_plan_id' => (int) $row['id'],
            'listing_id'      => (int) $listing['id'],
        ]);
    }

    /**
     * The plan a Paystack charge pays for: our reference, the plan id in the
     * metadata, or (a renewal) the customer and Paystack plan on the row.
     *
     * @param array<string,mixed> $metadata
     *
     * @return array<string,mixed>|null
     */
    public function rowForCharge(string $reference, array $metadata, string $customer, string $planCode): ?array
    {
        if ($reference !== '' && ($row = $this->plans->where('payment_reference', $reference)->first()) !== null) {
            return $row;
        }
        if (! empty($metadata['booking_plan_id']) && ($row = $this->plans->find((int) $metadata['booking_plan_id'])) !== null) {
            return $row;
        }
        if ($customer !== '' && $planCode !== '') {
            $row = $this->plans->where('paystack_customer_code', $customer)->where('paystack_plan_code', $planCode)->first();

            return is_array($row) ? $row : null;
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function rowForSubscription(string $code): ?array
    {
        if (trim($code) === '') {
            return null;
        }
        $row = $this->plans->where('paystack_subscription_code', $code)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * A successful charge for a plan (already verified with Paystack and
     * checked for amount by PaystackPayments). Adds a month from whichever is
     * later: now, the end of the trial, or the paid date.
     *
     * @param array<string,mixed>  $row
     * @param array<string,string> $mandate customer and plan code of a first charge
     *
     * @return string applied | duplicate
     */
    public function recordPayment(array $row, string $gatewayId, int $cents, string $reference, array $mandate, int $paidAt): string
    {
        $db = db_connect();
        $db->resetTransStatus()->transBegin();

        try {
            $exists = $db->table('xs_booking_plan_payments')->where('gateway_id', $gatewayId)->countAllResults();
            if ($exists > 0) {
                $db->transRollback();

                return 'duplicate';
            }
            $fresh = $this->plans->find((int) $row['id']);
            $base  = max($this->now->getTimestamp(), strtotime((string) ($fresh['paid_until'] ?? '') . ' UTC') ?: 0, $fresh['state'] === Plan::TRIAL ? (strtotime((string) $fresh['trial_ends_at'] . ' UTC') ?: 0) : 0);
            $until = (new DateTimeImmutable('@' . $base))->modify('+1 month')->format('Y-m-d H:i:s');
            $db->table('xs_booking_plan_payments')->insert([
                'listing_id' => (int) $row['listing_id'], 'plan_id' => (int) $row['id'], 'gateway_id' => $gatewayId,
                'reference' => $reference, 'amount_cents' => $cents, 'paid_at' => gmdate('Y-m-d H:i:s', $paidAt),
                'created_at' => $this->nowUtc(), 'updated_at' => $this->nowUtc(),
            ]);
            $this->plans->update((int) $row['id'], ['state' => Plan::ACTIVE, 'paid_until' => $until, 'cancelled_at' => null] + ($mandate !== [] ? [
                'paystack_customer_code' => $mandate['customer'], 'paystack_plan_code' => $mandate['plan'],
                'paystack_subscription_code' => null, 'paystack_email_token' => null,
            ] : []));
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        (new BookingAudit())->record((int) $row['listing_id'], null, 'plan.paid', 'plan', (int) $row['id'], null, ['reference' => $reference, 'until' => $until]);
        if ($mandate !== []) {
            $this->tellUs((int) $row['listing_id'], 'subscribed to the Bookings plan');
            $this->storeSubscription((int) $row['id']);
        }

        return 'applied';
    }

    /** Paystack opened the subscription (subscription.create): keep its code for cancelling. */
    public function subscriptionCreated(array $row, string $code, string $token): void
    {
        $this->plans->update((int) $row['id'], ['paystack_subscription_code' => $code, 'paystack_email_token' => $token]);
    }

    /** Stopped at Paystack: stays on to its paid date, then ends. */
    public function subscriptionStopped(array $row): string
    {
        if ($row['state'] !== Plan::ACTIVE || $row['cancelled_at'] !== null) {
            return 'ignored: booking plan ' . (int) $row['id'] . ' not renewing already';
        }
        $this->plans->update((int) $row['id'], ['state' => Plan::CANCELLED, 'cancelled_at' => $this->nowUtc()]);
        $this->tellUs((int) $row['listing_id'], 'stopped the Bookings plan at Paystack; it stays on until its paid date');

        return 'cancelled: booking plan ' . (int) $row['id'];
    }

    /** A renewal failed: the admins get Paystack's page for a new card. */
    public function paymentFailed(array $row, ?string $cardLink): string
    {
        $this->mailAdmins((int) $row['listing_id'], 'Your online bookings payment did not go through', 'emails/booking-plan', [
            'kind' => 'failed', 'link' => $cardLink,
        ]);

        return 'mailed: booking plan ' . (int) $row['id'] . ' payment failed';
    }

    /**
     * The business stops renewing (Plan tab). Paystack must confirm, or
     * nothing is recorded.
     */
    public function cancel(int $listingId, ?int $accountId): ?string
    {
        $row = $this->forListing($listingId);
        if ($row === null || $row['state'] !== Plan::ACTIVE || $row['cancelled_at'] !== null) {
            return 'There is no plan renewing.';
        }
        if ($row['paystack_subscription_code'] === null) {
            $row = $this->storeSubscription((int) $row['id']) ?? $row;
        }
        if (! $this->paystack()->disableSubscription((string) $row['paystack_subscription_code'], (string) $row['paystack_email_token'])) {
            return 'We could not reach Paystack to stop the payments. Please try again in a few minutes.';
        }
        $this->plans->update((int) $row['id'], ['state' => Plan::CANCELLED, 'cancelled_at' => $this->nowUtc()]);
        (new BookingAudit())->record($listingId, $accountId, 'plan.cancelled', 'plan', (int) $row['id']);
        $this->tellUs($listingId, 'cancelled the Bookings plan; it stays on until its paid date');

        return null;
    }

    /** Platform admin: give a business more free time (first businesses, goodwill). */
    public function extendTrial(int $listingId, int $days, string $by): void
    {
        $row  = $this->startTrial($listingId, null);
        $from = max($this->now->getTimestamp(), strtotime((string) ($row['state'] === Plan::TRIAL ? $row['trial_ends_at'] : $row['paid_until']) . ' UTC') ?: 0);
        $end  = gmdate('Y-m-d H:i:s', $from + max(1, min(365, $days)) * 86400);
        $this->plans->update((int) $row['id'], $row['state'] === Plan::TRIAL
            ? ['trial_ends_at' => $end, 'note' => mb_substr('Extended by ' . $by, 0, 255)]
            : ['paid_until' => $end, 'note' => mb_substr('Extended by ' . $by, 0, 255)]);
        (new BookingAudit())->record($listingId, null, 'plan.extended', 'plan', (int) $row['id'], null, ['days' => $days, 'by' => $by]);
    }

    /**
     * Daily: remind admins a few days before the trial ends, and look for
     * renewals the webhook missed (as PaystackPayments::catchUpRenewals()
     * does for the badge).
     *
     * @return array{reminded:int,checked:list<string>}
     */
    public function sweep(?\Closure $confirm = null): array
    {
        $out = ['reminded' => 0, 'checked' => []];
        if (! self::required()) {
            return $out;
        }
        $soon = gmdate('Y-m-d H:i:s', $this->now->getTimestamp() + config('Bookings')->trialReminderDays * 86400);
        foreach ($this->plans->where('state', Plan::TRIAL)->where('reminded_at', null)->where('trial_ends_at <=', $soon)->where('trial_ends_at >', $this->nowUtc())->findAll(200) as $row) {
            $this->mailAdmins((int) $row['listing_id'], 'Your free online bookings trial ends soon', 'emails/booking-plan', ['kind' => 'trial', 'ends' => (string) $row['trial_ends_at']]);
            $this->plans->update((int) $row['id'], ['reminded_at' => $this->nowUtc()]);
            $out['reminded']++;
        }

        if ($confirm !== null && $this->paystack()->isConfigured()) {
            $due = $this->plans->where('state', Plan::ACTIVE)->where('paystack_customer_code IS NOT NULL')
                ->where('paid_until <=', gmdate('Y-m-d H:i:s', $this->now->getTimestamp() + 86400))
                ->where('paid_until >=', gmdate('Y-m-d H:i:s', $this->now->getTimestamp() - 14 * 86400))->findAll(200);
            foreach ($due as $row) {
                $refs = $this->paystack()->successfulCharges((string) $row['paystack_customer_code'], strtotime((string) $row['paid_until'] . ' UTC') - 3 * 86400);
                foreach ($refs ?? [] as $reference) {
                    $out['checked'][] = $confirm($reference);
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null the row with the subscription code, when Paystack has it */
    private function storeSubscription(int $planId): ?array
    {
        $row = $this->plans->find($planId);
        if (! is_array($row) || $row['paystack_customer_code'] === null) {
            return null;
        }
        $sub = $this->paystack()->findSubscription((string) $row['paystack_customer_code'], (string) $row['paystack_plan_code']);
        if ($sub === null) {
            return null;
        }
        $this->plans->update($planId, ['paystack_subscription_code' => $sub['code'], 'paystack_email_token' => $sub['email_token']]);

        return $this->plans->find($planId);
    }

    /** @param array<string,mixed> $data */
    private function mailAdmins(int $listingId, string $subject, string $view, array $data): void
    {
        helper('site');
        $listing = (new DirectoryListingModel())->find($listingId);
        if (! is_array($listing)) {
            return;
        }
        $emails = array_column(db_connect()->table('xs_business_members m')->select('a.email')
            ->join('xs_accounts a', 'a.id = m.account_id')
            ->where('m.listing_id', $listingId)->where('m.role', 'admin')->where('m.status', 'active')->where('a.status', 'active')
            ->get()->getResultArray(), 'email');
        $body = view($view, $data + ['listing' => $listing, 'url' => listing_site_url($listing, 'business/settings/plan'), 'price' => config('Bookings')->planPrice()], ['saveData' => false]);
        foreach (array_unique($emails) as $email) {
            (new Mailer())->send((string) $email, $subject, $body);
        }
    }

    private function tellUs(int $listingId, string $detail): void
    {
        $admin = config('Directory')->adminEmail();
        if ($admin === '') {
            return;
        }
        $listing = (new DirectoryListingModel())->find($listingId);
        $name    = is_array($listing) ? (string) $listing['display_name'] : 'listing ' . $listingId;
        (new Mailer())->send($admin, 'Bookings plan: ' . $name, '<p><strong>' . esc($name) . '</strong> ' . esc($detail) . '.</p><p><a href="' . esc(site_url_for(Sites::ZA, 'admin/bookings'), 'attr') . '">Online bookings in admin</a></p>');
    }

    private function paystack(): Paystack
    {
        return $this->paystack ??= service('paystack');
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
    }
}
