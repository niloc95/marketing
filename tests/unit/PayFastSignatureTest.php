<?php

use App\Libraries\PayFast;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Directory as DirectoryConfig;

/**
 * The PayFast signature, which is the whole of our outbound authentication and
 * half of our inbound authentication.
 *
 * Worth testing precisely because it fails silently. A wrong signature does not
 * throw, log, or produce a useful message — PayFast simply refuses the payment,
 * and the visible symptom is an error page on someone else's site. The rules it
 * has to follow (field order, empty values, encoding, passphrase placement) are
 * all invisible to a reader of the calling code.
 *
 * @internal
 */
final class PayFastSignatureTest extends CIUnitTestCase
{
    /**
     * Note what this does NOT try to do: control the credentials through the
     * config object. Config\Directory's getters read .env first and fall back to
     * the property, which is the right precedence for the application and means
     * a developer with PayFast keys in their .env would see different values
     * here than CI does. Tests that depend on which one wins are testing the
     * developer's machine.
     *
     * So everything below either passes the passphrase to signature()
     * explicitly, or skips itself when an env override is in play.
     */
    private function payfast(string $passphrase = ''): PayFast
    {
        $config                     = new DirectoryConfig();
        $config->payfastMerchantId  = '10000100';
        $config->payfastMerchantKey = '46f0cd694581a';
        $config->payfastPassphrase  = $passphrase;
        $config->payfastSandbox     = true;

        return new PayFast($config);
    }

    private function skipIfEnvOverrides(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (env($key) !== null) {
                $this->markTestSkipped($key . ' is set in .env, which takes precedence over the config property this asserts on.');
            }
        }
    }

    public function testSignatureMatchesPayFastsPublishedAlgorithm(): void
    {
        // Computed the way PayFast's own sample code does, independently of the
        // implementation: name=urlencode(trim(value)) joined by &, in order.
        $fields   = ['merchant_id' => '10000100', 'amount' => '149.00', 'item_name' => 'Verified Business badge'];
        $expected = md5('merchant_id=10000100&amount=149.00&item_name=' . urlencode('Verified Business badge'));

        // '' explicitly: the no-argument form falls back to the configured
        // passphrase, which on a machine with PayFast keys in .env is not empty.
        $this->assertSame($expected, $this->payfast()->signature($fields, ''));
    }

    public function testPassphraseIsAppendedLast(): void
    {
        $payfast = $this->payfast();
        $fields  = ['merchant_id' => '10000100', 'amount' => '149.00'];

        // Passed explicitly rather than through config, so the assertion holds
        // whatever the developer's .env says.
        $withOut = $payfast->signature($fields, '');
        $with    = $payfast->signature($fields, 'secret pass');

        $this->assertNotSame($withOut, $with, 'the passphrase must change the signature');
        $this->assertSame(
            md5('merchant_id=10000100&amount=149.00&passphrase=' . urlencode('secret pass')),
            $with
        );
        $this->assertSame(md5('merchant_id=10000100&amount=149.00'), $withOut);
    }

    /**
     * The rule that is easiest to break by "tidying" the calling code: PayFast
     * signs the fields in submission order, so an alphabetical sort, an
     * array_merge that reorders, or a view that writes the inputs by hand in a
     * different sequence all produce a valid-looking signature that PayFast
     * rejects.
     */
    public function testFieldOrderChangesTheSignature(): void
    {
        $payfast = $this->payfast('pp');

        $this->assertNotSame(
            $payfast->signature(['a' => '1', 'b' => '2']),
            $payfast->signature(['b' => '2', 'a' => '1'])
        );
    }

    /** Empty values are omitted entirely, not sent as `key=`. */
    public function testEmptyValuesAreSkipped(): void
    {
        $payfast = $this->payfast();

        $this->assertSame(
            $payfast->signature(['a' => '1', 'c' => '3']),
            $payfast->signature(['a' => '1', 'b' => '', 'c' => '3'])
        );
    }

    /** The signature field itself is never part of what is signed. */
    public function testSignatureFieldIsExcludedFromItsOwnInput(): void
    {
        $payfast = $this->payfast();

        $this->assertSame(
            $payfast->signature(['a' => '1']),
            $payfast->signature(['a' => '1', 'signature' => 'whatever'])
        );
    }

    public function testVerifySignatureAcceptsAGenuineNotificationAndRejectsATamperedOne(): void
    {
        $payfast = $this->payfast('testpassphrase');

        $fields              = ['m_payment_id' => 'vb-1-abcd', 'amount_gross' => '149.00', 'payment_status' => 'COMPLETE'];
        $fields['signature'] = $payfast->signature($fields);

        $this->assertTrue($payfast->verifySignature($fields));

        // The attack this stops: same signature, larger amount.
        $tampered                 = $fields;
        $tampered['amount_gross'] = '1.00';
        $this->assertFalse($payfast->verifySignature($tampered));
    }

    public function testVerifySignatureRejectsAMissingSignature(): void
    {
        $this->assertFalse($this->payfast()->verifySignature(['amount_gross' => '149.00']));
    }

    /**
     * Order must survive parsing, or the signature check on a genuine
     * notification fails.
     */
    public function testParseNotificationPreservesOrderAndDecodesValues(): void
    {
        $parsed = $this->payfast()->parseNotification('b=2&a=1&item_name=Verified%20Business&empty=');

        $this->assertSame(['b', 'a', 'item_name', 'empty'], array_keys($parsed));
        $this->assertSame('Verified Business', $parsed['item_name']);
        $this->assertSame('', $parsed['empty']);
    }

    /** A round trip: what we would send is what we would accept. */
    public function testASignedNotificationSurvivesEncodingAndParsing(): void
    {
        $payfast = $this->payfast('testpassphrase');

        $fields              = ['m_payment_id' => 'vb-1-abcd', 'item_name' => 'Verified Business badge', 'amount_gross' => '149.00'];
        $fields['signature'] = $payfast->signature($fields);

        $body = [];
        foreach ($fields as $key => $value) {
            $body[] = $key . '=' . urlencode((string) $value);
        }

        $this->assertTrue($payfast->verifySignature($payfast->parseNotification(implode('&', $body))));
    }

    public function testAmountMatchingToleratesFloatDriftButNotADifferentAmount(): void
    {
        $payfast = $this->payfast();

        $this->assertTrue($payfast->amountMatches(149.00, '149.00'));
        $this->assertTrue($payfast->amountMatches(149.009, '149.00'), 'a fraction of a cent is float noise');
        $this->assertFalse($payfast->amountMatches(1.00, '149.00'));
        $this->assertFalse($payfast->amountMatches(149.50, '149.00'));
        $this->assertFalse($payfast->amountMatches(null, '149.00'), 'a missing amount is not a match');
    }

    public function testIsConfiguredNeedsBothCredentials(): void
    {
        $this->skipIfEnvOverrides('directory.payfastMerchantId', 'directory.payfastMerchantKey');

        $this->assertTrue($this->payfast()->isConfigured());

        $config                     = new DirectoryConfig();
        $config->payfastMerchantId  = '10000100';
        $config->payfastMerchantKey = '';
        $this->assertFalse((new PayFast($config))->isConfigured(), 'a merchant id alone is not configured');

        $config                     = new DirectoryConfig();
        $config->payfastMerchantId  = '';
        $config->payfastMerchantKey = '46f0cd694581a';
        $this->assertFalse((new PayFast($config))->isConfigured(), 'a key alone is not configured either');
    }

    public function testSandboxFlagSelectsTheProcessUrl(): void
    {
        $this->skipIfEnvOverrides('directory.payfastSandbox');

        $this->assertStringContainsString('sandbox.payfast.co.za', $this->payfast()->processUrl());

        $config                     = new DirectoryConfig();
        $config->payfastMerchantId  = '10000100';
        $config->payfastMerchantKey = '46f0cd694581a';
        $config->payfastSandbox     = false;
        $this->assertSame('https://www.payfast.co.za/eng/process', (new PayFast($config))->processUrl());
    }

    /**
     * The default matters more than it looks: an environment that forgot to
     * configure PayFast should take no money at all, rather than take real
     * money by accident.
     */
    public function testSandboxIsTheDefault(): void
    {
        $this->skipIfEnvOverrides('directory.payfastSandbox');

        $this->assertTrue((new DirectoryConfig())->payfastSandbox());
    }

    /**
     * env() hands back strings, and the string 'false' is truthy — a plain cast
     * would put a typo straight into live payments.
     */
    public function testSandboxEnvStringsAreInterpretedNotCast(): void
    {
        $config = new DirectoryConfig();

        foreach (['false', '0', 'no', 'off', ''] as $off) {
            $this->withEnv('directory.payfastSandbox', $off, function () use ($config, $off): void {
                $this->assertFalse($config->payfastSandbox(), "'{$off}' should switch the sandbox off");
            });
        }

        foreach (['true', '1', 'yes'] as $on) {
            $this->withEnv('directory.payfastSandbox', $on, function () use ($config, $on): void {
                $this->assertTrue($config->payfastSandbox(), "'{$on}' should keep the sandbox on");
            });
        }
    }

    /** PayFast rejects "29" and "29.9"; the getter normalises once, centrally. */
    public function testMonthlyAmountIsAlwaysTwoDecimals(): void
    {
        $config = new DirectoryConfig();

        foreach (['29' => '29.00', '29.9' => '29.90', '99.999' => '100.00'] as $raw => $expected) {
            $this->withEnv('directory.verifiedMonthlyAmount', (string) $raw, function () use ($config, $expected): void {
                $this->assertSame($expected, $config->verifiedMonthlyAmount());
            });
        }
    }

    /**
     * South African prices are written R29,99. PHP casts '29,99' to 29.0
     * without complaint, which would sell the badge for R29 and show up only as
     * a one-cent mismatch nobody investigates — so the comma is interpreted
     * rather than left to the cast.
     */
    public function testMonthlyAmountUnderstandsSouthAfricanDecimalCommas(): void
    {
        $config = new DirectoryConfig();

        $cases = [
            '29,99'      => '29.99',   // comma as decimal separator
            'R29,99'     => '29.99',   // with the currency symbol
            'R 29,99'    => '29.99',   // and a space
            '29.99'      => '29.99',   // the canonical form still works
            '1,299.00'   => '1299.00', // comma as a thousands separator
            '1 299,00'   => '1299.00', // space-grouped, comma decimal
        ];

        foreach ($cases as $raw => $expected) {
            $this->withEnv('directory.verifiedMonthlyAmount', (string) $raw, function () use ($config, $expected, $raw): void {
                $this->assertSame($expected, $config->verifiedMonthlyAmount(), "'{$raw}' should read as {$expected}");
            });
        }
    }

    /**
     * Run a callback with one env key temporarily set, then restore it.
     *
     * $_ENV and $_SERVER are set as well as putenv(), because CI4's env() reads
     * `$_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)` — and .env populates the
     * first two. putenv() alone loses to a key the developer happens to have in
     * their own .env, which is how this test failed the first time it ran.
     */
    private function withEnv(string $key, string $value, callable $body): void
    {
        $hadEnv    = array_key_exists($key, $_ENV);
        $hadServer = array_key_exists($key, $_SERVER);
        $oldEnv    = $_ENV[$key] ?? null;
        $oldServer = $_SERVER[$key] ?? null;
        $oldGet    = getenv($key);

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key . '=' . $value);

        try {
            $body();
        } finally {
            if ($hadEnv) {
                $_ENV[$key] = $oldEnv;
            } else {
                unset($_ENV[$key]);
            }

            if ($hadServer) {
                $_SERVER[$key] = $oldServer;
            } else {
                unset($_SERVER[$key]);
            }

            if ($oldGet === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $oldGet);
            }
        }
    }

    /**
     * The Subscriptions API sorts its parameters alphabetically. The payment
     * form signs them in submission order. Two rules, one provider.
     */
    public function testApiSignatureSortsAlphabetically(): void
    {
        $payfast = $this->payfast();

        // Deliberately supplied out of alphabetical order.
        $params = ['version' => 'v1', 'merchant-id' => '10000100', 'timestamp' => '2026-08-10T09:00:00+02:00'];

        $this->assertSame(
            md5('merchant-id=10000100&timestamp=' . urlencode('2026-08-10T09:00:00+02:00') . '&version=v1'),
            $payfast->apiSignature($params, '')
        );

        // Order of the input array must not matter — sorting is what defines it.
        $this->assertSame(
            $payfast->apiSignature($params, ''),
            $payfast->apiSignature(['merchant-id' => '10000100', 'timestamp' => '2026-08-10T09:00:00+02:00', 'version' => 'v1'], '')
        );
    }

    /**
     * The assertion that stops someone collapsing the two signature methods into
     * one "to remove duplication". They are not the same function, and the wrong
     * one fails with nothing more useful than a rejected request.
     */
    public function testApiSignatureAndFormSignatureDisagreeOnTheSameInput(): void
    {
        $payfast = $this->payfast();

        // Reverse-alphabetical input, so submission order and sorted order differ.
        $fields = ['version' => 'v1', 'merchant-id' => '10000100'];

        $this->assertNotSame(
            $payfast->signature($fields, ''),
            $payfast->apiSignature($fields, ''),
            'the form and API signature rules must not be interchangeable'
        );
    }

    public function testApiSignatureIncludesThePassphraseInSortOrder(): void
    {
        $payfast = $this->payfast();
        $params  = ['merchant-id' => '10000100', 'version' => 'v1'];

        // 'passphrase' sorts between 'merchant-id' and 'version' — it is not
        // simply appended the way the form signature appends it.
        $this->assertSame(
            md5('merchant-id=10000100&passphrase=' . urlencode('secret pass') . '&version=v1'),
            $payfast->apiSignature($params, 'secret pass')
        );
    }

    public function testApiSignatureIgnoresAnyIncomingSignatureField(): void
    {
        $payfast = $this->payfast();

        $this->assertSame(
            $payfast->apiSignature(['merchant-id' => '10000100'], ''),
            $payfast->apiSignature(['merchant-id' => '10000100', 'signature' => 'stale'], '')
        );
    }

    /** No token, or no credentials, means no call attempted. */
    public function testCancelSubscriptionRefusesAnEmptyToken(): void
    {
        $this->assertFalse($this->payfast()->cancelSubscription(''));
        $this->assertFalse($this->payfast()->cancelSubscription('   '));
    }

    public function testAnArbitraryAddressIsNotAValidNotificationSource(): void
    {
        // 192.0.2.0/24 is TEST-NET-1: reserved for documentation, so this can
        // never accidentally be a real PayFast address.
        $this->assertFalse($this->payfast()->isValidSourceIp('192.0.2.42'));
        $this->assertFalse($this->payfast()->isValidSourceIp(''));
    }

    /**
     * The subscription field set has to be ordered, complete, and signed over
     * exactly what it contains — the view renders it by iteration, so this array
     * is the contract.
     */
    public function testSubscriptionFieldsAreOrderedAndSelfConsistent(): void
    {
        $payfast = $this->payfast('testpassphrase');

        $fields = $payfast->subscriptionFields(
            ['display_name' => 'Test Co', 'email' => 'owner@example.test'],
            ['id' => 1, 'listing_id' => 35, 'amount' => '149.00', 'pf_m_payment_id' => 'vb-1-abcd']
        );

        $this->assertSame('merchant_id', array_key_first($fields), 'PayFast expects merchant_id first');
        $this->assertSame('signature', array_key_last($fields), 'the signature is computed over everything before it');

        $this->assertSame('1', $fields['subscription_type'], 'PayFast bills this one monthly');
        $this->assertSame('3', $fields['frequency'], 'frequency 3 is monthly');
        $this->assertSame('0', $fields['cycles'], 'cycles 0 means indefinite');
        $this->assertSame('149.00', $fields['recurring_amount']);
        $this->assertSame('35', $fields['custom_str1'], 'listing id, for correlating a stray notification');
        $this->assertSame('1', $fields['custom_str2'], 'verification id');

        // The signature in the array must be the signature of the array.
        $withoutSignature = $fields;
        unset($withoutSignature['signature']);
        $this->assertSame($payfast->signature($withoutSignature), $fields['signature']);
    }
}
