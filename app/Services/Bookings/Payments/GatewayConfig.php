<?php

namespace App\Services\Bookings\Payments;

use Config\Directory;

/**
 * One business's PayFast or Paystack keys, shaped as the config that
 * App\Libraries\PayFast and Paystack read. They were written for our own
 * merchant account (Config\Directory, .env); handing them this instead lets
 * a business's deposits use the same signature, notification and verify code
 * as our own payments, on the business's own account (decision D6).
 *
 * Every key the libraries read is overridden, so nothing from our own .env
 * can leak into a business's payment, nor the other way round.
 */
final class GatewayConfig extends Directory
{
    /** @param array<string,string> $keys the decrypted account (BookingIntegrations, channel 'payment') */
    public function __construct(private string $gateway, private array $keys)
    {
        parent::__construct();
    }

    public function gateway(): string
    {
        return $this->gateway;
    }

    /** Sandbox or a test key: no real money moves. */
    public function isTest(): bool
    {
        return $this->gateway === 'paystack'
            ? str_starts_with($this->paystackSecretKey(), 'sk_test_')
            : $this->payfastSandbox();
    }

    public function payfastMerchantId(): string
    {
        return $this->gateway === 'payfast' ? trim((string) ($this->keys['merchant_id'] ?? '')) : '';
    }

    public function payfastMerchantKey(): string
    {
        return $this->gateway === 'payfast' ? trim((string) ($this->keys['merchant_key'] ?? '')) : '';
    }

    public function payfastPassphrase(): string
    {
        return $this->gateway === 'payfast' ? trim((string) ($this->keys['passphrase'] ?? '')) : '';
    }

    public function payfastSandbox(): bool
    {
        return ($this->keys['mode'] ?? 'live') === 'test';
    }

    public function paystackSecretKey(): string
    {
        return $this->gateway === 'paystack' ? trim((string) ($this->keys['secret_key'] ?? '')) : '';
    }

    public function paymentProvider(): string
    {
        return $this->gateway;
    }
}
