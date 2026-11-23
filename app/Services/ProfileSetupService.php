<?php

namespace App\Services;

use App\Libraries\Input;
use App\Libraries\Mailer;
use App\Libraries\PaymentReference;
use App\Libraries\Paystack;
use App\Models\ProfileSetupOrderModel;
use Config\Directory as DirectoryConfig;
use Config\Sites;

/**
 * The profile setup service: a business pays a once-off fee through Paystack
 * and we complete its profile for it. /profile-setup sells it,
 * /admin/profile-setup is the queue we work it from.
 *
 * Always Paystack, whatever directory.paymentProvider says: a once-off
 * payment has no subscription to carry over, so there is nothing to keep on
 * PayFast. Its notification endpoint for setup orders (payfast/setup-notify)
 * was retired on 23 Nov 2026: the only PayFast orders left were unpaid tests.
 *
 * An order is saved before payment as awaiting_payment, and only a payment
 * Paystack confirms (PaystackPayments: webhook or verify) moves it to paid.
 * The buyer's return trip proves nothing on its own, so it only makes us ask.
 *
 * Paying for setup buys our time, nothing else: it does not move a profile up
 * the results and does not include the Verified Business badge. The page and
 * the emails say so.
 */
class ProfileSetupService
{
    public const MAX_NOTES = 2000;

    private ProfileSetupOrderModel $orders;
    private DirectoryConfig $site;

    public function __construct(?ProfileSetupOrderModel $orders = null, ?DirectoryConfig $site = null)
    {
        $this->orders = $orders ?? new ProfileSetupOrderModel();
        $this->site   = $site ?? config('Directory');
    }

    /**
     * What a new order is charged, in rand. On local.webscheduler.app the
     * dollar price at the Plus rate (DirectorySettings::profileSetupRandOnApp());
     * on co.za, and on .app before a rate is set, the rand price.
     */
    public function amount(): string
    {
        $settings = new DirectorySettings($this->site);
        if ($this->onApp() && ($rand = $settings->profileSetupRandOnApp()) !== null) {
            return $rand;
        }

        return $settings->profileSetupRand();
    }

    /**
     * The dollar price shown on .app, or null on co.za, and on .app while no
     * rate is set (it is then shown and charged in rand, as amount() does).
     */
    public function usdAmount(): ?string
    {
        $settings = new DirectorySettings();

        return $this->onApp() && $settings->profileSetupRandOnApp() !== null ? $settings->profileSetupUsd() : null;
    }

    private function onApp(): bool
    {
        return service('site')->isApp() && service('site')->scoping();
    }

    public function days(): int
    {
        return max(1, $this->site->profileSetupDays);
    }

    /** Can we take a card payment right now? Paystack, with its key set. */
    public function canTakePayment(?Paystack $paystack = null): bool
    {
        return ($paystack ?? service('paystack'))->isConfigured();
    }

    /**
     * Open a Paystack checkout for an order: the access code for the card
     * window and the hosted page URL as the fallback, or null.
     *
     * Each press takes the order's next reference: WSL-SETUP-000123 the first
     * time, WSL-SETUP-000123-2 after the buyer closes the card window and
     * tries again, because Paystack refuses a reference it has seen. The
     * order id in the metadata finds the order if an older tab pays.
     */
    /** @return array{url:string,access_code:string}|null */
    public function paystackCheckout(array $order, ?Paystack $paystack = null): ?array
    {
        $id = (int) $order['id'];
        $this->orders->builder()->set('payment_attempts', 'payment_attempts + 1', false)->where('id', $id)->update();
        $attempt   = (int) ($this->orders->builder()->select('payment_attempts')->where('id', $id)->get()->getRow()->payment_attempts ?? 1);
        $reference = PaymentReference::make(PaymentReference::SETUP, $id, max(1, $attempt));
        $this->orders->update($id, ['reference' => $reference, 'provider' => 'paystack']);

        return ($paystack ?? service('paystack'))->initialize(
            (string) $order['email'],
            Paystack::cents((string) $order['amount']),
            $reference,
            base_url('profile-setup/paystack'),
            null,
            ['order_id' => (int) $order['id']]
        );
    }

    /**
     * Save an order from the /profile-setup form, awaiting payment.
     *
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,errors:array<string,string>,order?:array<string,mixed>}
     */
    public function create(array $in): array
    {
        $d = [
            'name'          => Input::line($in['name'] ?? '', 120),
            'business_name' => Input::line($in['business_name'] ?? '', 150),
            'email'         => strtolower(Input::line($in['email'] ?? '', 190)),
            'phone'         => Input::line($in['phone'] ?? '', 40),
            'profile_url'   => Input::line($in['profile_url'] ?? '', 255),
            'notes'         => Input::text($in['notes'] ?? '', self::MAX_NOTES),
        ];

        $errors = [];
        if ($d['name'] === '') {
            $errors['name'] = 'Please tell us your name.';
        }
        if ($d['business_name'] === '') {
            $errors['business_name'] = 'Please tell us the name of your business.';
        }
        if (! filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $d['email'] === '' ? 'We need your email to send your receipt.' : 'That email address does not look right.';
        }
        if (strlen((string) preg_replace('/\D+/', '', $d['phone'])) < 9) {
            $errors['phone'] = 'Please give a phone number we can reach you on.';
        }
        if ($d['profile_url'] !== '') {
            $url = (new DirectoryListingMutationService())->normaliseUrl($d['profile_url']);
            if ($url === null || safe_external_url($url) === '') {
                $errors['profile_url'] = 'That link does not look right. Leave it empty if you do not have a profile yet.';
            } else {
                $d['profile_url'] = $url;
            }
        }
        if (empty($in['terms'])) {
            $errors['terms'] = 'Please agree to the terms of the service.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row = $d + [
            // A placeholder for the UNIQUE column: the real reference is the
            // order's own number, WSL-SETUP-000123 (PaymentReference), set as
            // soon as the insert gives us the id.
            'reference' => 'tmp-' . bin2hex(random_bytes(8)),
            'amount'    => $this->amount(),
            'status'    => ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT,
            'terms_at'  => date('Y-m-d H:i:s'),
        ];
        foreach (['profile_url', 'notes'] as $nullable) {
            if ($row[$nullable] === '') {
                $row[$nullable] = null;
            }
        }

        // No transaction around the two writes, deliberately: CI4's strict
        // transactions roll back silently after any earlier failed query on the
        // same connection. If the second write ever failed, the order would
        // keep its unique placeholder, which still pays and still matches.
        $id = (int) $this->orders->insert($row);
        $this->orders->update($id, ['reference' => PaymentReference::make(PaymentReference::SETUP, $id)]);

        return ['ok' => true, 'errors' => [], 'order' => $this->orders->find($id)];
    }

    /** An order that can still be paid for, or null. */
    public function payable(int $id): ?array
    {
        $order = $id > 0 ? $this->orders->find($id) : null;

        return is_array($order) && $order['status'] === ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT ? $order : null;
    }

    /**
     * Mark an order paid and send both emails. Safe to call twice: Paystack's
     * webhook and the buyer's return can both land, and a second call for a
     * paid order changes nothing and sends nothing.
     */
    public function recordPayment(array $order, string $pfPaymentId): string
    {
        if ($order['status'] !== ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT) {
            return 'duplicate: order ' . (int) $order['id'] . ' is already ' . $order['status'];
        }

        $this->orders->update((int) $order['id'], [
            'status'        => ProfileSetupOrderModel::STATUS_PAID,
            'pf_payment_id' => $pfPaymentId !== '' ? mb_substr($pfPaymentId, 0, 40) : null,
            'paid_at'       => date('Y-m-d H:i:s'),
        ]);
        $order = (array) $this->orders->find((int) $order['id']);

        $this->notifyInbox($order);
        $this->receiptToBuyer($order);

        return 'paid: order ' . (int) $order['id'];
    }

    /**
     * Status and notes from /admin/profile-setup. Paid is Paystack's to set,
     * never ours, and an order nobody has paid for cannot be marked worked on.
     *
     * @return array{ok:bool,message:string}
     */
    public function update(int $id, string $status, string $notes): array
    {
        $order = $this->orders->find($id);
        if (! is_array($order)) {
            return ['ok' => false, 'message' => 'That order no longer exists.'];
        }

        $data = ['admin_notes' => Input::text($notes, 4000) ?: null];

        if ($status !== '' && $status !== $order['status']) {
            if (! in_array($status, ProfileSetupOrderModel::ADMIN_SETTABLE, true)) {
                return ['ok' => false, 'message' => 'That status cannot be set by hand.'];
            }
            $unpaid = $order['status'] === ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT;
            if ($unpaid && $status !== ProfileSetupOrderModel::STATUS_CANCELLED) {
                return ['ok' => false, 'message' => 'This order has not been paid. It can only be cancelled.'];
            }
            $data['status'] = $status;
        }

        $this->orders->update($id, $data);

        return ['ok' => true, 'message' => 'Order ' . $order['reference'] . ' saved.'];
    }

    // ------------------------------------------------------------- helpers

    private function notifyInbox(array $order): void
    {
        $to = $this->site->adminEmail();
        if ($to === '') {
            return;
        }

        $notice = [
            'eyebrow'        => 'Profile setup',
            'heading'        => 'Paid: ' . $order['business_name'],
            'paragraphs'     => array_values(array_filter([
                $order['name'] . ' paid R' . $order['amount'] . ' for profile setup (' . $order['reference'] . ').',
                $order['email'] . ' · ' . $order['phone'],
                $order['profile_url'] ? 'Existing profile: ' . $order['profile_url'] : 'No profile yet.',
                (string) ($order['notes'] ?? ''),
            ])),
            'button'         => ['Open the queue', site_url_for(Sites::ZA, 'admin/profile-setup') . '#order-' . (int) $order['id']],
            'footnote'       => 'Reply to this email to ask ' . strtok((string) $order['name'], ' ') . ' for their logo, photos and details. '
                . 'We promised to finish within ' . $this->days() . ' business days of having them.',
        ];

        (new Mailer())->notice($to, 'Profile setup paid: ' . $order['business_name'], $notice, (string) $order['email']);
    }

    /**
     * The buyer's receipt and what happens next. Fixed wording: nothing typed
     * into the form is echoed back except the amount and a first name that is
     * only letters, so the form cannot be used to mail a stranger a message.
     */
    private function receiptToBuyer(array $order): void
    {
        $first    = (string) strtok((string) $order['name'], ' ');
        $greeting = preg_match('/^\p{L}[\p{L}\']{0,29}$/u', $first) === 1 ? 'Hi ' . $first . ',' : 'Hello,';
        $admin    = $this->site->adminEmail();

        $notice = [
            'eyebrow'        => 'Profile setup',
            'heading'        => 'Thank you, your payment is confirmed',
            'paragraphs'     => [
                $greeting,
                'We have received your payment of R' . $order['amount'] . ' for our profile setup service. Your reference is ' . $order['reference'] . '.',
                'We will be in touch shortly to collect what we need: your logo, a few photos of your work, your opening hours, '
                    . 'the services you offer and the areas you serve. You can also reply to this email with them now.',
                'Once we have your details, we will complete your profile within ' . $this->days() . ' business days and email you when it is live.',
            ],
            'footnote'       => 'You are receiving this because this address was used to order a profile setup on '
                . $this->site->siteName() . '. Keep it as your receipt.',
            'footnoteLink'   => ['Terms of the service', site_url_for(Sites::ZA, 'profile-setup') . '#terms'],
        ];

        (new Mailer())->notice((string) $order['email'], 'Your profile setup payment is confirmed', $notice, $admin);
    }
}
