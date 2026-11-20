<?php

namespace App\Services\Bookings;

/**
 * Per process memory for a business's booking settings and catalogue
 * (load test, 20 Nov 2026): one online booking read the settings 14 times
 * and the services 4 times, each through a new BookingSettings or
 * CatalogueService. Reads go through remember(), and every write to a
 * remembered table forgets that business (BookingScopedModel, $memo), so a
 * save is seen at once by the same process.
 *
 * Entries live MAX_SECONDS at most: long running commands (bookings:dispatch
 * runs 50 s) still see a change another process made, and a web request is
 * far shorter. Never a cache across requests; use BookingMetricsCache for that.
 */
final class BookingMemo
{
    public const MAX_SECONDS = 2.0;

    /** @var array<int,array<string,array{0:float,1:mixed}>> listing id => key => [stored at, value] */
    private static array $entries = [];

    /**
     * @template T
     *
     * @param callable():T $read
     *
     * @return T
     */
    public static function remember(int $listingId, string $key, callable $read): mixed
    {
        $now = microtime(true);
        $hit = self::$entries[$listingId][$key] ?? null;
        if ($hit !== null && $now - $hit[0] < self::MAX_SECONDS) {
            return $hit[1];
        }
        $value                              = $read();
        self::$entries[$listingId][$key]    = [$now, $value];

        return $value;
    }

    /** Forget one business, after a write or a rolled back transaction. */
    public static function forget(int $listingId): void
    {
        unset(self::$entries[$listingId]);
    }

    /** Forget everything (tests, between DB resets). */
    public static function flush(): void
    {
        self::$entries = [];
    }
}
