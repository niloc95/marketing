<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingProviderHoursModel;
use App\Models\Bookings\BookingProviderModel;

/**
 * The one place a person's weekly rows are read (the engine, the calendar,
 * the team pages). A person on `profile` hours has no rows of their own in
 * use: their week is made here from the profile's opening hours as they are
 * now, one period a day at the profile's address for every service. So a
 * change to the profile's hours reaches booking at once, with nothing to
 * copy or keep in step. A person on `own` hours gets their saved rows.
 *
 * Provider hours are always read with a provider filter (Bookings' rule).
 */
class ProviderWeek
{
    public const PROFILE = 'profile';
    public const OWN     = 'own';

    /**
     * Weekly rows per person, in the shape of a BookingProviderHoursModel row,
     * in weekday and start order.
     *
     * @param list<int>                 $providerIds
     * @param array<string,mixed>|string|null $tradingHours the profile's trading_hours (JSON, or decoded)
     *
     * @return array<int,list<array<string,mixed>>> provider id => rows
     */
    public static function rows(int $listingId, array $providerIds, array|string|null $tradingHours): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $providerIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $out = array_fill_keys($ids, []);
        $own = [];
        foreach (BookingProviderModel::forBusiness($listingId)->select('id, hours_mode')->whereIn('id', $ids)->findAll() as $p) {
            if (($p['hours_mode'] ?? self::OWN) === self::PROFILE) {
                $out[(int) $p['id']] = self::fromProfile((int) $p['id'], $listingId, $tradingHours);
            } else {
                $own[] = (int) $p['id'];
            }
        }
        if ($own !== []) {
            foreach (BookingProviderHoursModel::forBusiness($listingId)->whereIn('provider_id', $own)
                ->orderBy('weekday', 'ASC')->orderBy('start_time', 'ASC')->findAll() as $row) {
                $out[(int) $row['provider_id']][] = $row;
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function forProvider(int $listingId, int $providerId, array|string|null $tradingHours): array
    {
        return self::rows($listingId, [$providerId], $tradingHours)[$providerId] ?? [];
    }

    /**
     * The profile's week as rows: each open day with both times given.
     *
     * @param array<string,mixed>|string|null $tradingHours
     *
     * @return list<array<string,mixed>>
     */
    public static function fromProfile(int $providerId, int $listingId, array|string|null $tradingHours): array
    {
        helper('directory_hours');
        $week = is_array($tradingHours) ? $tradingHours : (hours_decode($tradingHours) ?? []);
        $rows = [];
        foreach (TeamService::PROFILE_DAY_KEYS as $day => $key) {
            $d = $week[$key] ?? null;
            if (! is_array($d) || ! empty($d['closed'])) {
                continue;
            }
            $open  = self::time($d['open'] ?? '');
            $close = self::time($d['close'] ?? '');
            if ($open === null || $close === null || $open >= $close) {
                continue;
            }
            $rows[] = [
                'id' => null, 'listing_id' => $listingId, 'provider_id' => $providerId, 'weekday' => $day,
                'start_time' => $open . ':00', 'end_time' => $close . ':00', 'breaks' => '[]',
                'location_id' => null, 'service_ids' => null,
            ];
        }

        return $rows;
    }

    /** HH:MM, or null (the same reading as TeamService's hours form). */
    private static function time(mixed $value): ?string
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:\d\d)?$/', trim((string) $value), $m)) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
}
