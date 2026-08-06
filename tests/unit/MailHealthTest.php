<?php

use App\Libraries\MailHealth;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The record of whether outbound mail is working.
 *
 * This exists because of a real bug that shipped: DirectoryListingMutationService
 * called `$email->send(false)` under a comment saying the argument suppressed
 * exceptions. It does not — that parameter is $autoClear, and Email::send()
 * reports SMTP failure by *returning false*. The return value was discarded, so
 * the catch block never ran and a total mail outage produced no log line, no
 * counter, and no visible symptom anywhere. Signups kept saying "check your
 * email" while nothing was sent.
 *
 * These tests pin the state machine that now makes that visible.
 *
 * @internal
 */
final class MailHealthTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Cache is shared process state; start every test from a known place.
        cache()->delete('mail_last_error');
        cache()->delete('mail_consecutive_failures');
        cache()->delete('mail_last_ok');
    }

    public function testStartsClean(): void
    {
        $this->assertSame(0, MailHealth::consecutiveFailures());
        $this->assertNull(MailHealth::lastError());
        $this->assertFalse(MailHealth::isFailing());
    }

    public function testRecordsAFailureWithItsReason(): void
    {
        MailHealth::recordFailure('SMTP connect failed');

        $last = MailHealth::lastError();
        $this->assertIsArray($last);
        $this->assertSame('SMTP connect failed', $last['reason']);
        $this->assertGreaterThan(0, $last['at']);
        $this->assertSame(1, MailHealth::consecutiveFailures());
    }

    /**
     * One failure is a blip — a flaky connection, a greylist. Alerting on it
     * would train the operator to ignore the alert.
     */
    public function testASingleFailureIsNotYetAnOutage(): void
    {
        MailHealth::recordFailure('transient');

        $this->assertFalse(MailHealth::isFailing());
    }

    public function testTwoConsecutiveFailuresIsAnOutage(): void
    {
        MailHealth::recordFailure('one');
        MailHealth::recordFailure('two');

        $this->assertSame(2, MailHealth::consecutiveFailures());
        $this->assertTrue(MailHealth::isFailing());
    }

    public function testSuccessClearsTheFailureState(): void
    {
        MailHealth::recordFailure('one');
        MailHealth::recordFailure('two');
        $this->assertTrue(MailHealth::isFailing());

        MailHealth::recordSuccess();

        $this->assertFalse(MailHealth::isFailing(), 'health must recover on its own once mail works again');
        $this->assertSame(0, MailHealth::consecutiveFailures());
        $this->assertNull(MailHealth::lastError());
        $this->assertNotNull(MailHealth::lastSuccessAt());
    }

    /** A long SMTP transcript must not become an unbounded cache entry. */
    public function testTruncatesAVeryLongReason(): void
    {
        MailHealth::recordFailure(str_repeat('x', 2000));

        $this->assertLessThanOrEqual(500, strlen(MailHealth::lastError()['reason']));
    }
}
