<?php

namespace App\Services\Bookings;

/**
 * Five minute cache for a business's dashboard figures and analytics
 * (parity.md §3, Bookings' DashboardService::getCachedMetrics()).
 *
 * Every key carries the business's generation number, and any appointment
 * change bumps it (AppointmentService::notify()), so a booking or a status
 * change shows at once instead of after the five minutes. Bookings deleted
 * its keys one by one and missed the staff scopes; a generation cannot miss.
 */
final class BookingMetricsCache
{
    public const TTL = 300;

    /**
     * Bump when a cached array changes shape (a key added or renamed), so a
     * release never reads the old shape from the last five minutes.
     */
    private const SHAPE = 2;

    /**
     * @template T
     *
     * @param callable():T $build
     *
     * @return T
     */
    public static function remember(int $listingId, string $key, callable $build): mixed
    {
        $cache = cache();
        $full  = 'bkm' . self::SHAPE . '_' . $listingId . '_' . self::generation($listingId) . '_' . md5($key);
        $hit   = $cache->get($full);
        if ($hit !== null) {
            return $hit;
        }
        $value = $build();
        $cache->save($full, $value, self::TTL);

        return $value;
    }

    /** Throw away every cached figure of a business. */
    public static function forget(int $listingId): void
    {
        try {
            cache()->save(self::genKey($listingId), self::generation($listingId) + 1, 86400);
        } catch (\Throwable $e) {
            log_message('error', 'Booking metrics cache not cleared: ' . $e->getMessage());
        }
    }

    private static function generation(int $listingId): int
    {
        return (int) (cache()->get(self::genKey($listingId)) ?? 0);
    }

    private static function genKey(int $listingId): string
    {
        return 'bkm_' . $listingId . '_gen';
    }
}
