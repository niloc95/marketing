<?php

namespace App\Libraries;

use Config\Directory as DirectoryConfig;

/**
 * Paystack, for every new payment: the badge, Plus and the profile setup
 * service (Config\Directory::paymentProvider()).
 *
 * Unlike PayFast there is no signed form. The server asks Paystack for a
 * checkout (`initialize`), sends the browser to the URL it answers with, and
 * learns the outcome two ways: the browser coming back to our callback, where
 * we ask Paystack (`verify`), and the webhook. Both end in the same idempotent
 * record step, so whichever lands first wins and the other changes nothing.
 *
 * What stands in for PayFast's four ITN checks: the webhook's HMAC SHA512
 * signature under our secret key, Paystack's published source addresses, a
 * `verify` call over a connection we open, and the amount and currency we
 * asked for. PaystackPayments runs them; this class only talks to Paystack.
 *
 * A South African Paystack account charges in rand only. Plus is priced in
 * dollars and charged in rand, exactly as it was through PayFast.
 *
 * request() is the one place a call leaves the server, and tests replace it.
 *
 * @see \App\Services\PaystackPayments
 */
class Paystack
{
    public const API = 'https://api.paystack.co';

    /**
     * Where Paystack sends webhooks from, as published in its docs
     * (paystack.com/docs/payments/webhooks). The signature is the real check;
     * this is the second lock on the door.
     */
    public const WEBHOOK_IPS = ['52.31.139.75', '52.49.173.169', '52.214.14.220'];

    public const INTERVAL_MONTHLY  = 'monthly';
    public const INTERVAL_ANNUALLY = 'annually';

    private DirectoryConfig $config;

    public function __construct(?DirectoryConfig $config = null)
    {
        $this->config = $config ?? config('Directory');
    }

    public function isConfigured(): bool
    {
        return $this->config->paystackSecretKey() !== '';
    }

    /** A test key: Paystack's test mode, where no real money moves. */
    public function isTestMode(): bool
    {
        return str_starts_with($this->config->paystackSecretKey(), 'sk_test_');
    }

    /** Rand to cents, which is what Paystack counts in. */
    public static function cents(string|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Start a checkout. With a plan code Paystack charges the plan's amount
     * and opens a subscription on the card; without one it charges once.
     *
     * Paystack answers two ways in: the hosted page's URL, and the access
     * code our checkout page hands to InlineJS to open the card window over
     * the page (resumeTransaction). The URL is the fallback when the
     * overlay cannot load, and the whole route without JavaScript.
     *
     * @param array<string,mixed> $metadata a fallback to find the row by, like PayFast's custom_str2
     *
     * @return array{url:string,access_code:string}|null
     */
    public function initialize(
        string $email,
        int $amountCents,
        string $reference,
        string $callbackUrl,
        ?string $planCode = null,
        array $metadata = []
    ): ?array {
        $body = [
            'email'        => $email,
            'amount'       => $amountCents,
            'currency'     => 'ZAR',
            'reference'    => $reference,
            'callback_url' => $callbackUrl,
            'metadata'     => $metadata,
        ];
        if ($planCode !== null && $planCode !== '') {
            $body['plan'] = $planCode;
        }

        $data = $this->request('POST', '/transaction/initialize', $body);
        $url  = is_array($data) ? (string) ($data['authorization_url'] ?? '') : '';
        $code = is_array($data) ? (string) ($data['access_code'] ?? '') : '';

        return str_starts_with($url, 'https://') && $code !== '' ? ['url' => $url, 'access_code' => $code] : null;
    }

    /**
     * Paystack's own record of a transaction, or null if it could not be had.
     *
     * @return array<string,mixed>|null
     */
    public function verify(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    /** Make a plan; its code, or null. */
    public function createPlan(string $name, string $interval, int $amountCents): ?string
    {
        $data = $this->request('POST', '/plan', [
            'name'     => mb_substr($name, 0, 100),
            'interval' => $interval,
            'amount'   => $amountCents,
            'currency' => 'ZAR',
        ]);
        $code = is_array($data) ? (string) ($data['plan_code'] ?? '') : '';

        return $code !== '' ? $code : null;
    }

    /**
     * The live subscription a customer holds on a plan: its code and the
     * email token that cancelling needs, or null.
     *
     * Looked up rather than taken from the first charge, because Paystack
     * opens the subscription just after that charge, and its own
     * subscription.create webhook can arrive before or after our callback.
     *
     * @return array{code:string,email_token:string}|null
     */
    public function findSubscription(string $customerCode, string $planCode): ?array
    {
        if (trim($customerCode) === '' || trim($planCode) === '') {
            return null;
        }

        // The customer's list leaves `plan` empty (seen in test mode, 2 Nov
        // 2026), so a subscription whose plan it does not name is fetched on
        // its own, which does name it, with the email token.
        $customer = $this->request('GET', '/customer/' . rawurlencode($customerCode));
        foreach ((array) ($customer['subscriptions'] ?? []) as $sub) {
            if (! is_array($sub) || ! in_array((string) ($sub['status'] ?? ''), ['active', 'attention'], true)) {
                continue;
            }
            $code = (string) ($sub['subscription_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $plan  = self::planCodeOf($sub['plan'] ?? null);
            $token = (string) ($sub['email_token'] ?? '');
            if ($plan === '' || $token === '') {
                $full  = $this->request('GET', '/subscription/' . rawurlencode($code));
                $plan  = $plan !== '' ? $plan : self::planCodeOf($full['plan'] ?? null);
                $token = $token !== '' ? $token : (string) ($full['email_token'] ?? '');
            }
            if ($plan === $planCode && $token !== '') {
                return ['code' => $code, 'email_token' => $token];
            }
        }

        return null;
    }

    /**
     * Stop a subscription. False on any failure: the caller must not record
     * a cancellation Paystack did not confirm.
     */
    public function disableSubscription(string $code, string $emailToken): bool
    {
        if (trim($code) === '' || trim($emailToken) === '') {
            return false;
        }

        return $this->request('POST', '/subscription/disable', ['code' => $code, 'token' => $emailToken]) !== null;
    }

    /** Paystack's page where a subscriber updates their card, or null. */
    public function manageLink(string $code): ?string
    {
        if (trim($code) === '') {
            return null;
        }
        $data = $this->request('GET', '/subscription/' . rawurlencode($code) . '/manage/link');
        $link = is_array($data) ? (string) ($data['link'] ?? '') : '';

        return str_starts_with($link, 'https://') ? $link : null;
    }

    /** HMAC SHA512 of the raw body under the secret key, as Paystack signs it. */
    public function validWebhookSignature(string $raw, string $signature): bool
    {
        $secret = $this->config->paystackSecretKey();
        if ($secret === '' || trim($signature) === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $raw, $secret), strtolower(trim($signature)));
    }

    public function isWebhookIp(string $ip): bool
    {
        return in_array(trim($ip), self::WEBHOOK_IPS, true);
    }

    /**
     * A plan's code from wherever Paystack put it: a string on a verify
     * response, an object on a webhook, an empty object when there is none.
     */
    public static function planCodeOf(mixed $plan): string
    {
        if (is_string($plan)) {
            return str_starts_with($plan, 'PLN_') ? $plan : '';
        }

        return is_array($plan) ? (string) ($plan['plan_code'] ?? '') : '';
    }

    /**
     * One API call: the response's `data` when Paystack answered `status:
     * true` with a 2xx, otherwise null. Logs the path and status only: the
     * bodies carry customer emails and tokens.
     *
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>|null
     */
    protected function request(string $method, string $path, ?array $body = null): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $ch = curl_init(self::API . $path);
        if ($ch === false) {
            return null;
        }

        $headers = [
            'Authorization: Bearer ' . $this->config->paystackSecretKey(),
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $headers[]                   = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || ! is_array($json) || ($json['status'] ?? false) !== true) {
            log_message('error', sprintf(
                'Paystack %s %s failed (HTTP %d)%s%s',
                $method,
                (string) preg_replace('/\/(?:PLN|SUB|CUS)_\w+|\/verify\/.+$/', '/…', $path),
                $status,
                is_array($json) && isset($json['message']) ? ': ' . Mailer::oneLine((string) $json['message']) : '',
                $error !== '' ? ': ' . $error : ''
            ));

            return null;
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
