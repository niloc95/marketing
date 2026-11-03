<?php

use App\Libraries\PaymentReference;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The payment reference formats the owner set on 3 Nov 2026.
 *
 * @internal
 */
final class PaymentReferenceTest extends CIUnitTestCase
{
    public function testEveryFormatPadsTheNumberToSixDigits(): void
    {
        $this->assertSame('WSL-SETUP-000123', PaymentReference::make(PaymentReference::SETUP, 123));
        $this->assertSame('WSL-VER-M-000123', PaymentReference::forSubscription('badge', 'monthly', 123));
        $this->assertSame('WSL-VER-Y-000123', PaymentReference::forSubscription('badge', 'yearly', 123));
        $this->assertSame('WSL-PLUS-M-000123', PaymentReference::forSubscription('international', 'monthly', 123));
        $this->assertSame('WSL-PLUS-Y-000123', PaymentReference::forSubscription('international', 'yearly', 123));
        $this->assertSame('WSB-BOOK-000123', PaymentReference::make(PaymentReference::BOOKING, 123), 'reserved for Bookings');
    }

    public function testASecondTryAddsTheAttemptAndALargeNumberPrintsWider(): void
    {
        $this->assertSame('WSL-SETUP-000123', PaymentReference::make(PaymentReference::SETUP, 123, 1));
        $this->assertSame('WSL-SETUP-000123-2', PaymentReference::make(PaymentReference::SETUP, 123, 2));
        $this->assertSame('WSL-VER-M-1234567', PaymentReference::forSubscription('badge', 'monthly', 1234567));
    }

    public function testSetupReferencesAreKnownOldAndNew(): void
    {
        $this->assertTrue(PaymentReference::isSetup('WSL-SETUP-000123-2'));
        $this->assertTrue(PaymentReference::isSetup('ps-0123456789ab'), 'orders from before 3 Nov 2026');
        $this->assertFalse(PaymentReference::isSetup('WSL-VER-M-000123'));
        $this->assertFalse(PaymentReference::isSetup('T_paystack_made'));
    }
}
