<?php

use App\Libraries\Paystack;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Directory as DirectoryConfig;

/**
 * App\Libraries\Paystack against answers shaped like Paystack's own (seen in
 * test mode, 2 Nov 2026), with request() replaced so nothing leaves the machine.
 *
 * @internal
 */
final class PaystackClientTest extends CIUnitTestCase
{
    public function testASubscriptionIsFoundEvenThoughTheCustomerListLeavesThePlanOut(): void
    {
        $client = $this->client([
            '/customer/CUS_a' => ['subscriptions' => [
                ['status' => 'cancelled', 'subscription_code' => 'SUB_old', 'email_token' => 't0', 'plan' => []],
                ['status' => 'active', 'subscription_code' => 'SUB_other', 'email_token' => 't1', 'plan' => []],
                ['status' => 'active', 'subscription_code' => 'SUB_ours', 'email_token' => 't2', 'plan' => []],
            ]],
            '/subscription/SUB_other' => ['email_token' => 't1', 'plan' => ['plan_code' => 'PLN_yearly']],
            '/subscription/SUB_ours'  => ['email_token' => 't2', 'plan' => ['plan_code' => 'PLN_monthly']],
        ]);

        $this->assertSame(['code' => 'SUB_ours', 'email_token' => 't2'], $client->findSubscription('CUS_a', 'PLN_monthly'));
        $this->assertNull($client->findSubscription('CUS_a', 'PLN_none'));
    }

    public function testThePlanCodeIsReadFromAStringOrAnObject(): void
    {
        $this->assertSame('PLN_x', Paystack::planCodeOf('PLN_x'), 'verify answers a string');
        $this->assertSame('PLN_x', Paystack::planCodeOf(['plan_code' => 'PLN_x']), 'webhooks send an object');
        $this->assertSame('', Paystack::planCodeOf([]), 'an empty object when there is no plan');
        $this->assertSame('', Paystack::planCodeOf(null));
    }

    public function testTheWebhookSignatureIsHmacSha512OfTheRawBody(): void
    {
        $client = $this->client([]);
        $raw    = '{"event":"charge.success"}';

        $this->assertTrue($client->validWebhookSignature($raw, hash_hmac('sha512', $raw, 'sk_test_unit')));
        $this->assertFalse($client->validWebhookSignature($raw . ' ', hash_hmac('sha512', $raw, 'sk_test_unit')));
        $this->assertFalse($client->validWebhookSignature($raw, ''));
    }

    public function testRandBecomesCents(): void
    {
        $this->assertSame(2999, Paystack::cents('29.99'));
        $this->assertSame(161820, Paystack::cents('1618.20'));
    }

    /** @param array<string,array<string,mixed>> $answers by path */
    private function client(array $answers): Paystack
    {
        $config                    = new DirectoryConfig();
        $config->paystackSecretKey = 'sk_test_unit';

        return new class ($config, $answers) extends Paystack {
            public function __construct(DirectoryConfig $config, private array $answers)
            {
                parent::__construct($config);
            }

            protected function request(string $method, string $path, ?array $body = null): ?array
            {
                return $this->answers[$path] ?? null;
            }
        };
    }
}
