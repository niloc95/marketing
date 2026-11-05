<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\BookingOptOutModel;
use App\Services\Bookings\CustomerService;

/**
 * Customers who asked a business to stop sending them messages on a
 * channel. Bookings had the table and no way in (parity.md §9: New); here the
 * unsubscribe link in every customer email and RFC 8058 one click both land
 * on Book::unsubscribe. The only writer of xs_booking_opt_outs.
 *
 * Stored as a keyed hash of the address or number, so the table holds no
 * contact details and survives the customer record being deleted. Per
 * business: stopping one business's reminders leaves every other alone.
 */
class OptOuts
{
    public function __construct(private int $listingId)
    {
    }

    public function isOptedOut(string $channel, string $recipient): bool
    {
        $hash = self::hash($this->listingId, $channel, $recipient);

        return $hash !== null && BookingOptOutModel::forBusiness($this->listingId)
            ->where(['channel' => $channel, 'recipient_hash' => $hash])->countAllResults() > 0;
    }

    public function optOut(string $channel, string $recipient, string $source = 'link'): void
    {
        $hash = self::hash($this->listingId, $channel, $recipient);
        if ($hash === null || $this->isOptedOut($channel, $recipient)) {
            return;
        }
        try {
            BookingOptOutModel::forBusiness($this->listingId)->insert(['channel' => $channel, 'recipient_hash' => $hash, 'source' => $source]);
        } catch (\Throwable) {
            // Two clicks at once: the unique key kept one, which is the point.
        }
    }

    public function optIn(string $channel, string $recipient): void
    {
        $hash = self::hash($this->listingId, $channel, $recipient);
        if ($hash !== null) {
            BookingOptOutModel::forBusiness($this->listingId)->where(['channel' => $channel, 'recipient_hash' => $hash])->delete();
        }
    }

    public static function hash(int $listingId, string $channel, string $recipient): ?string
    {
        $normal = $channel === 'email' ? CustomerService::normalEmail($recipient) : self::digits($recipient);
        if ($normal === null || $normal === '') {
            return null;
        }
        // SMS and WhatsApp share a number: stopping one is its own choice, so the channel is in the hash.
        return hash('sha256', 'booking-optout|' . $listingId . '|' . $channel . '|' . $normal . '|' . config('Encryption')->key);
    }

    private static function digits(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }
}
