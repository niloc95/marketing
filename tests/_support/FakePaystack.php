<?php

namespace Tests\Support;

use App\Libraries\Paystack;
use Config\Directory as DirectoryConfig;

/**
 * A Paystack that never leaves the machine: every API call is answered from
 * what the test set up and written down in $calls. The webhook signature
 * check is the real one, under the fake secret key.
 */
class FakePaystack extends Paystack
{
    public const SECRET = 'sk_test_fake_secret';

    /** @var array<string,array<string,mixed>> what verify() answers, by reference */
    public array $transactions = [];

    /** @var list<array<int,mixed>> */
    public array $calls = [];

    /** @var array{code:string,email_token:string}|null what findSubscription() answers */
    public ?array $subscription = ['code' => 'SUB_fake', 'email_token' => 'etok_fake'];

    public bool $disableSucceeds = true;

    public ?string $checkoutUrl = 'https://checkout.paystack.com/fake123';

    public string $accessCode = 'fakeaccess123';

    /** @var list<array{name:string,interval:string,cents:int}> */
    public array $plans = [];

    public function __construct()
    {
        $config                    = new DirectoryConfig();
        $config->paystackSecretKey = self::SECRET;
        parent::__construct($config);
    }

    public function initialize(string $email, int $amountCents, string $reference, string $callbackUrl, ?string $planCode = null, array $metadata = []): ?array
    {
        $this->calls[] = ['initialize', compact('email', 'amountCents', 'reference', 'callbackUrl', 'planCode', 'metadata')];

        return $this->checkoutUrl === null ? null : ['url' => $this->checkoutUrl, 'access_code' => $this->accessCode];
    }

    public function verify(string $reference): ?array
    {
        $this->calls[] = ['verify', $reference];

        return $this->transactions[$reference] ?? null;
    }

    public function createPlan(string $name, string $interval, int $amountCents): ?string
    {
        $this->plans[] = ['name' => $name, 'interval' => $interval, 'cents' => $amountCents];

        return 'PLN_' . $interval . '_' . $amountCents;
    }

    public function findSubscription(string $customerCode, string $planCode): ?array
    {
        $this->calls[] = ['find', $customerCode, $planCode];

        return $this->subscription;
    }

    public function disableSubscription(string $code, string $emailToken): bool
    {
        $this->calls[] = ['disable', $code, $emailToken];

        return $this->disableSucceeds;
    }

    public function manageLink(string $code): ?string
    {
        return 'https://paystack.com/manage/fake';
    }

    /** The calls of one kind, e.g. 'disable'. */
    public function callsOf(string $kind): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $kind));
    }

    protected function request(string $method, string $path, ?array $body = null): ?array
    {
        throw new \LogicException('FakePaystack made a real request: ' . $method . ' ' . $path);
    }
}
