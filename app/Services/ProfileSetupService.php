<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\PayFast;
use App\Models\DirectoryVerificationItnRejectionModel;
use App\Models\ProfileSetupOrderModel;
use Config\Directory as DirectoryConfig;

/**
 * The profile setup service: a business pays a once-off fee through PayFast
 * and we complete its profile for it. /profile-setup sells it,
 * /admin/profile-setup is the queue we work it from.
 *
 * An order is saved before payment as awaiting_payment, and only a PayFast
 * notification that passes every check moves it to paid. The thank you page
 * PayFast returns the buyer to proves nothing (anyone can open it), so it
 * never changes an order.
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

    public function amount(): string
    {
        return $this->site->profileSetupAmount();
    }

    public function days(): int
    {
        return max(1, $this->site->profileSetupDays);
    }

    /** Can we take a card payment right now? */
    public function canTakePayment(?PayFast $payfast = null): bool
    {
        return ($payfast ?? new PayFast())->isConfigured();
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
            'name'          => $this->clean($in['name'] ?? '', 120),
            'business_name' => $this->clean($in['business_name'] ?? '', 150),
            'email'         => strtolower($this->clean($in['email'] ?? '', 190)),
            'phone'         => $this->clean($in['phone'] ?? '', 40),
            'profile_url'   => $this->clean($in['profile_url'] ?? '', 255),
            'notes'         => $this->cleanText($in['notes'] ?? '', self::MAX_NOTES),
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
            // 'ps' for profile setup, as the subscription ids start 'vb' and
            // 'il': it tells a human reading the PayFast dashboard what it is.
            'reference' => 'ps-' . bin2hex(random_bytes(6)),
            'amount'    => $this->amount(),
            'status'    => ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT,
            'terms_at'  => date('Y-m-d H:i:s'),
        ];
        foreach (['profile_url', 'notes'] as $nullable) {
            if ($row[$nullable] === '') {
                $row[$nullable] = null;
            }
        }

        $id = (int) $this->orders->insert($row);

        return ['ok' => true, 'errors' => [], 'order' => $this->orders->find($id)];
    }

    /** An order that can still be paid for, or null. */
    public function payable(int $id): ?array
    {
        $order = $id > 0 ? $this->orders->find($id) : null;

        return is_array($order) && $order['status'] === ProfileSetupOrderModel::STATUS_AWAITING_PAYMENT ? $order : null;
    }

    /**
     * Handle a PayFast notification for an order. The same four checks as
     * App\Controllers\PayFastNotify, in the same order: signature, source
     * address, PayFast's own confirmation, then the amount. Here rather than
     * in the controller so tests can hand in a PayFast double.
     *
     * Returns a short outcome for the log. Every refusal is also written to
     * the ITN rejection table the verification notifications use.
     */
    public function handleNotification(string $raw, string $ip, PayFast $payfast): string
    {
        if (! $payfast->isConfigured()) {
            return 'ignored: PayFast is not configured';
        }
        if (trim($raw) === '') {
            return 'ignored: empty body';
        }

        $fields = $payfast->parseNotification($raw);

        if (! $payfast->verifySignature($fields)) {
            return $this->reject('bad signature', $fields, $ip);
        }
        if (! $payfast->isValidSourceIp($ip)) {
            return $this->reject('source address is not PayFast', $fields, $ip);
        }
        if (! $payfast->validateWithPayFast($raw)) {
            // As in PayFastNotify: the sandbox answers INVALID for its own
            // notifications, so only the sandbox may carry on.
            if (! $payfast->isSandbox()) {
                return $this->reject('PayFast did not confirm it', $fields, $ip);
            }
            log_message('warning', 'Profile setup ITN: sandbox POST-back validation failed; continuing because directory.payfastSandbox is on.');
        }

        $order = $this->orders->findByReference((string) ($fields['m_payment_id'] ?? ''));
        if ($order === null && ! empty($fields['custom_str1'])) {
            $row   = $this->orders->find((int) $fields['custom_str1']);
            $order = is_array($row) ? $row : null;
        }
        if ($order === null) {
            return $this->reject('no matching order', $fields, $ip);
        }

        $status = strtoupper(trim((string) ($fields['payment_status'] ?? '')));
        if ($status !== 'COMPLETE') {
            // A once-off payment has nothing to cancel on our side: a failed
            // or abandoned payment leaves the order awaiting payment.
            return 'noted: ' . ($status === '' ? 'no status' : $status) . ' for order ' . (int) $order['id'];
        }

        $gross = isset($fields['amount_gross']) && $fields['amount_gross'] !== '' ? (float) $fields['amount_gross'] : null;
        if (! $payfast->amountMatches($gross, (string) $order['amount'])) {
            return $this->reject(sprintf(
                'amount %s does not match the expected %s',
                $gross === null ? 'missing' : (string) $gross,
                (string) $order['amount']
            ), $fields, $ip);
        }

        return $this->recordPayment($order, (string) ($fields['pf_payment_id'] ?? ''));
    }

    /**
     * Mark an order paid and send both emails. Safe to call twice: PayFast
     * retries notifications, and a second one for a paid order changes
     * nothing and sends nothing.
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
     * Status and notes from /admin/profile-setup. Paid is PayFast's to set,
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

        $data = ['admin_notes' => $this->cleanText($notes, 4000) ?: null];

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

    /** @param array<string,string> $fields */
    private function reject(string $reason, array $fields, string $ip): string
    {
        $message = 'Profile setup: ' . $reason . '.';
        log_message('warning', 'PayFast ITN: ' . $message . (isset($fields['m_payment_id']) ? ' [m_payment_id=' . $fields['m_payment_id'] . ']' : ''));
        (new DirectoryVerificationItnRejectionModel())->record($message, $fields, $ip, null);

        return 'rejected: ' . $reason;
    }

    private function notifyInbox(array $order): void
    {
        $to = $this->site->adminEmail();
        if ($to === '') {
            return;
        }

        $body = view('emails/job-notice', [
            'site'           => $this->site->siteName(),
            'eyebrow'        => 'Profile setup',
            'heading'        => 'Paid: ' . $order['business_name'],
            'paragraphs'     => array_values(array_filter([
                $order['name'] . ' paid R' . $order['amount'] . ' for profile setup (' . $order['reference'] . ').',
                $order['email'] . ' · ' . $order['phone'],
                $order['profile_url'] ? 'Existing profile: ' . $order['profile_url'] : 'No profile yet.',
                (string) ($order['notes'] ?? ''),
            ])),
            'button'         => ['Open the queue', base_url('admin/profile-setup') . '#order-' . (int) $order['id']],
            'footnote'       => 'Reply to this email to ask ' . strtok((string) $order['name'], ' ') . ' for their logo, photos and details. '
                . 'We promised to finish within ' . $this->days() . ' business days of having them.',
            'footnoteLink'   => null,
            'bullets'        => null,
            'bulletsHeading' => null,
            'extras'         => null,
            'extrasHeading'  => null,
            'highlight'      => null,
            'closing'        => null,
        ], ['saveData' => false]);

        (new Mailer())->send($to, 'Profile setup paid: ' . $order['business_name'], $body, (string) $order['email']);
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

        $body = view('emails/job-notice', [
            'site'           => $this->site->siteName(),
            'eyebrow'        => 'Profile setup',
            'heading'        => 'Thank you, your payment is confirmed',
            'paragraphs'     => [
                $greeting,
                'We have received your payment of R' . $order['amount'] . ' for our profile setup service. Your reference is ' . $order['reference'] . '.',
                'We will be in touch shortly to collect what we need: your logo, a few photos of your work, your opening hours, '
                    . 'the services you offer and the areas you serve. You can also reply to this email with them now.',
                'Once we have your details, we will complete your profile within ' . $this->days() . ' business days and email you when it is live.',
            ],
            'button'         => null,
            'footnote'       => 'You are receiving this because this address was used to order a profile setup on '
                . $this->site->siteName() . '. Keep it as your receipt.',
            'footnoteLink'   => ['Terms of the service', base_url('profile-setup') . '#terms'],
            'bullets'        => null,
            'bulletsHeading' => null,
            'extras'         => null,
            'extrasHeading'  => null,
            'highlight'      => null,
            'closing'        => null,
        ], ['saveData' => false]);

        (new Mailer())->send((string) $order['email'], 'Your profile setup payment is confirmed', $body, $admin);
    }

    private function clean($v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) $v)), 0, $max);
    }

    private function cleanText($v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, $max);
    }
}
