<?php

namespace App\Libraries;

use App\Models\DirectoryVerificationModel;
use Config\Directory as DirectoryConfig;

/**
 * PayFast, for the Verified Business monthly subscription.
 *
 * Two halves. Outbound: build the signed field set that redirects an owner to
 * PayFast's checkout. Inbound: decide whether an ITN — the server-to-server
 * notification that says a payment happened — is really from PayFast and really
 * describes the payment we asked for.
 *
 * The inbound half is the one that matters. That endpoint is unauthenticated
 * and public, it is the only thing standing between a POST and a free badge,
 * and it must be safe to receive the same notification twice. Nothing here
 * decides that on its own: PayFastNotify runs four independent checks and this
 * class supplies three of them, on the principle that a forger has to defeat
 * all of them rather than find the weakest.
 *
 * @see \App\Controllers\PayFastNotify
 */
class PayFast
{
    /**
     * Hostnames PayFast sends notifications from. Resolved at check time
     * rather than pinned to addresses, because PayFast changes them and a
     * hard-coded list would fail closed on a day nobody was deploying.
     */
    private const NOTIFY_HOSTS = [
        'www.payfast.co.za',
        'sandbox.payfast.co.za',
        'w1w.payfast.co.za',
        'w2w.payfast.co.za',
    ];

    /** PayFast's own code for a monthly recurring cycle. */
    public const FREQUENCY_MONTHLY = 3;

    private DirectoryConfig $config;

    public function __construct(?DirectoryConfig $config = null)
    {
        $this->config = $config ?? config('Directory');
    }

    public function isConfigured(): bool
    {
        return $this->config->payfastMerchantId() !== ''
            && $this->config->payfastMerchantKey() !== '';
    }

    public function isSandbox(): bool
    {
        return $this->config->payfastSandbox();
    }

    public function processUrl(): string
    {
        return $this->isSandbox()
            ? 'https://sandbox.payfast.co.za/eng/process'
            : 'https://www.payfast.co.za/eng/process';
    }

    private function validateUrl(): string
    {
        return $this->isSandbox()
            ? 'https://sandbox.payfast.co.za/eng/query/validate'
            : 'https://www.payfast.co.za/eng/query/validate';
    }

    /**
     * The complete, ordered, signed field set for the checkout form.
     *
     * Ordered is not a stylistic note. PayFast signs the fields in the order
     * they are submitted, so the array this returns and the hidden inputs the
     * view renders must be the same sequence — which is why the view iterates
     * this array rather than listing fields itself. Hand-writing the inputs in
     * a different order produces a signature mismatch and nothing else: no
     * useful error, just a rejected payment.
     *
     * subscription_type 1 with cycles 0 means PayFast bills the card every month
     * indefinitely and notifies us each time, rather than us storing a token and
     * running our own billing loop. Less to get wrong, and the owner can cancel
     * from PayFast's side without needing us.
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $verification
     *
     * @return array<string,string>
     */
    public function subscriptionFields(array $listing, array $verification): array
    {
        $amount = number_format((float) $verification['amount'], 2, '.', '');
        $name   = (string) ($listing['display_name'] ?? '');

        // Read off the row rather than passed in, so the wording can never
        // disagree with the subscription it is signing for. Absent means badge:
        // that is what every row written before the plan column looks like.
        $isInternational = ($verification['plan'] ?? DirectoryVerificationModel::PLAN_BADGE)
            === DirectoryVerificationModel::PLAN_INTERNATIONAL;

        $fields = [
            'merchant_id'  => $this->config->payfastMerchantId(),
            'merchant_key' => $this->config->payfastMerchantKey(),
            'return_url'   => base_url('manage/verification/done'),
            'cancel_url'   => base_url('manage/edit'),
            'notify_url'   => base_url('payfast/notify'),

            'email_address' => (string) ($listing['email'] ?? ''),

            'm_payment_id' => (string) $verification['pf_m_payment_id'],
            'amount'       => $amount,
            // What the buyer sees on the PayFast page and on their card
            // statement, so it has to name the thing they are actually buying.
            // An international subscriber charged for a "Verified Business
            // badge" they never asked for is a chargeback waiting to happen.
            'item_name'        => $isInternational ? 'International Listing' : 'Verified Business badge',
            // Truncated because PayFast caps this field and silently rejects
            // the whole request rather than trimming it for us.
            'item_description' => mb_substr(
                ($isInternational ? 'Monthly International Listing for ' : 'Monthly Verified Business badge for ') . $name,
                0,
                200
            ),

            // Correlation of last resort. m_payment_id is what we look up on,
            // but if a notification ever arrives without it these two are enough
            // to find the right row by hand.
            'custom_str1' => (string) $verification['listing_id'],
            'custom_str2' => (string) $verification['id'],

            'subscription_type' => '1',
            // No billing_date on purpose. It is optional and PayFast defaults it
            // to the day the payment arrives, which is exactly what we want and
            // is not what sending one guarantees: the field would be stamped
            // when the checkout page rendered, so an owner who opened it at
            // 23:58 and paid at 00:01 would submit a billing_date of yesterday
            // and have the payment refused. Refused for a reason nothing in the
            // response names, which is the expensive part — the shape of the
            // error points at the signature, and the signature is fine.
            'recurring_amount' => $amount,
            'frequency'        => (string) self::FREQUENCY_MONTHLY,
            'cycles'           => '0',
        ];

        $fields['signature'] = $this->signature($fields);

        return $fields;
    }

    /**
     * MD5 over `key=value` pairs joined by `&`, in the given order, with empty
     * values skipped and the passphrase appended last.
     *
     * MD5 is PayFast's choice, not ours, and it is not doing collision-resistant
     * work here — it authenticates a short message under a shared secret that
     * the caller does not know. Worth stating plainly so nobody "fixes" it to
     * SHA-256 and breaks every payment.
     *
     * On encoding: this follows PayFast's own published sample, which uses
     * PHP's urlencode() — spaces become `+`, hex escapes come out uppercase.
     * Some third-party write-ups insist on `%20` instead. If the sandbox ever
     * rejects a signature that looks otherwise correct, this line is the first
     * thing to try flipping, and it is deliberately the only place the encoding
     * is decided.
     *
     * On empty values, $skipEmpty, and why the two directions differ. Outbound
     * we build the field list ourselves and omit anything empty, which is what
     * PayFast's checkout sample does. Inbound is NOT the same rule: PayFast
     * signs the notification over every field it sent, empty ones included, so
     * dropping them here produces a hash over a shorter string and refuses a
     * perfectly good notification.
     *
     * This is not hypothetical. A real ITN arrived with 25 fields, 10 of them
     * empty; skipping those gave 8cc89a8b… against a claimed 615fe322…, and
     * keeping them matched exactly. Because checkout uses the same method and
     * checkout was working, the natural conclusion was a wrong passphrase — the
     * passphrase was right the whole time.
     *
     * @param array<string,mixed> $fields    ordered; `signature` is ignored if present
     * @param bool                $skipEmpty true for outbound (checkout), false
     *                                       for verifying an inbound notification
     */
    public function signature(array $fields, ?string $passphrase = null, bool $skipEmpty = true): string
    {
        $passphrase ??= $this->config->payfastPassphrase();

        $parts = [];
        foreach ($fields as $key => $value) {
            if ($key === 'signature') {
                continue;
            }
            $value = trim((string) $value);
            if ($skipEmpty && $value === '') {
                continue;
            }
            $parts[] = $key . '=' . urlencode($value);
        }

        $string = implode('&', $parts);

        if (trim($passphrase) !== '') {
            $string .= '&passphrase=' . urlencode(trim($passphrase));
        }

        return md5($string);
    }

    /**
     * Check 1 of 4: does the notification carry a signature we can reproduce?
     *
     * $fields must be in the order PayFast sent them, which is why the caller
     * parses the raw request body rather than using the framework's POST array
     * — that one is a hash, and a hash's order is an implementation detail.
     *
     * hash_equals() rather than === because the comparison is against a value
     * an attacker supplies and can vary freely; the timing leak is small but
     * the fix costs nothing.
     *
     * @param array<string,string> $fields
     */
    public function verifySignature(array $fields): bool
    {
        $claimed = (string) ($fields['signature'] ?? '');
        if ($claimed === '') {
            return false;
        }

        // skipEmpty: false — an inbound notification is signed over every field
        // PayFast sent, including the empty ones. See signature().
        return hash_equals($this->signature($fields, null, false), $claimed);
    }

    /**
     * Check 2 of 4: did it come from a PayFast machine?
     *
     * Weak on its own — addresses can be spoofed on a network that allows it,
     * and a proxy in front of us could get this wrong — which is exactly why it
     * is one of four rather than the gate. Fails closed: if DNS is unavailable
     * we cannot say yes, so we say no and PayFast retries.
     */
    public function isValidSourceIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        $valid = [];
        foreach (self::NOTIFY_HOSTS as $host) {
            $resolved = gethostbynamel($host);
            if (is_array($resolved)) {
                $valid = array_merge($valid, $resolved);
            }
        }

        if ($valid === []) {
            log_message('error', 'PayFast: could not resolve any notification host; rejecting notification.');

            return false;
        }

        return in_array($ip, array_unique($valid), true);
    }

    /**
     * Check 3 of 4: ask PayFast whether it really sent this.
     *
     * The strongest of the four, because it does not depend on anything the
     * caller controls — we hand the exact bytes we received back to PayFast over
     * a connection we opened, and it answers VALID or INVALID. A forged
     * notification fails here even if the forger somehow had the passphrase.
     *
     * Any transport failure returns false. Treating "we could not ask" as "yes"
     * would turn a PayFast outage into an open door.
     */
    public function validateWithPayFast(string $rawBody): bool
    {
        $ch = curl_init($this->validateUrl());
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'WebScheduler Directory',
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            log_message('error', 'PayFast validation request failed: ' . $error);

            return false;
        }

        return str_starts_with(trim((string) $response), 'VALID');
    }

    /**
     * Check 4 of 4: is this the amount we asked for?
     *
     * Compared against the amount stored on the verification row, not the
     * currently configured price — see the migration for why. One cent of
     * tolerance absorbs the float round trip; anything larger is a different
     * payment than the one we authorised.
     */
    public function amountMatches(?float $received, string $expected): bool
    {
        if ($received === null) {
            return false;
        }

        return abs($received - (float) $expected) <= 0.01;
    }

    /**
     * Cancel a recurring subscription through PayFast's Subscriptions API.
     *
     * A different integration from everything above. The checkout is a signed
     * HTML form the buyer's browser posts; this is a server-to-server REST call
     * we make ourselves, with its own base URL, its own authentication headers
     * and — the trap — its own signature rule.
     *
     * Returns false on any failure, including a failure to reach PayFast. The
     * caller must not record a cancellation it could not confirm: telling
     * someone their subscription is cancelled when it is still billing is how a
     * support email becomes a chargeback.
     *
     * NOT VERIFIED AGAINST PAYFAST. Everything here follows their published
     * Subscriptions API, but unlike the signature and ITN paths it has not been
     * exercised end to end. Confirm in the sandbox before relying on it.
     */
    public function cancelSubscription(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || ! $this->isConfigured()) {
            return false;
        }

        // The API dates its own requests and rejects stale ones, so the format
        // matters: ISO 8601 with an offset, not a bare datetime.
        $headers = [
            'merchant-id' => $this->config->payfastMerchantId(),
            'version'     => 'v1',
            'timestamp'   => date('Y-m-d\TH:i:sP'),
        ];

        $headers['signature'] = $this->apiSignature($headers);

        $url = 'https://api.payfast.co.za/subscriptions/' . rawurlencode($token) . '/cancel'
            . ($this->isSandbox() ? '?testing=true' : '');

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_map(
                static fn ($k, $v) => $k . ': ' . $v,
                array_keys($headers),
                $headers
            ),
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            // The subscription token is a credential — log that it failed and
            // the status, never the token or the response body.
            log_message('error', sprintf(
                'PayFast subscription cancel failed (HTTP %d)%s',
                $status,
                $error !== '' ? ': ' . $error : ''
            ));

            return false;
        }

        return true;
    }

    /**
     * Signature for the Subscriptions API.
     *
     * Separate from signature() above and it must stay separate, because the
     * ordering rule is the opposite: the payment form is signed in *submission*
     * order, and the API is signed in **alphabetical** order of parameter name.
     * The two produce different digests for identical input, and the wrong one
     * fails with nothing more informative than a rejected request — so merging
     * them "to remove duplication" would be a silent, expensive bug. There is a
     * test asserting they differ, for exactly that reason.
     *
     * @param array<string,string> $params headers and any body parameters
     */
    public function apiSignature(array $params, ?string $passphrase = null): string
    {
        $passphrase ??= $this->config->payfastPassphrase();

        unset($params['signature']);

        if (trim($passphrase) !== '') {
            $params['passphrase'] = trim($passphrase);
        }

        ksort($params, SORT_STRING);

        $parts = [];
        foreach ($params as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $parts[] = $key . '=' . urlencode($value);
            }
        }

        return md5(implode('&', $parts));
    }

    /**
     * Parse an ITN body into an ordered array.
     *
     * Order-preserving and duplicate-tolerant in the way the signature check
     * needs: PHP's own parse_str() would reorder nothing but would happily
     * collapse `a=1&a=2` into one key, and a signature computed over the
     * collapsed version of a body PayFast signed uncollapsed will not match.
     * Doing the split by hand keeps what arrived.
     *
     * @return array<string,string>
     */
    public function parseNotification(string $rawBody): array
    {
        $fields = [];

        foreach (explode('&', $rawBody) as $pair) {
            if ($pair === '') {
                continue;
            }
            $bits  = explode('=', $pair, 2);
            $key   = urldecode($bits[0]);
            $value = isset($bits[1]) ? urldecode($bits[1]) : '';

            if ($key !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }
}
