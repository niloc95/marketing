<?php

namespace App\Services\Bookings\Api;

use App\Models\Bookings\BookingCustomerModel;
use App\Services\Bookings\CatalogueService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The shape of each resource in /api/v1 and in webhook bodies
 * (public/developers/bookings/openapi.json). One place, so the API and a
 * webhook never describe the same appointment differently.
 *
 * - Times are ISO 8601 in UTC (`2030-01-07T08:00:00Z`), plus the business's
 *   local time and zone, so a caller never has to guess.
 * - Money is in cents with its currency.
 * - Answers to the business's questions are left out: some are marked
 *   sensitive, and an API key or webhook endpoint should not carry them.
 */
final class Resources
{
    /**
     * @param array<string,mixed>      $a        appointment row
     * @param array<string,mixed>|null $customer customer row, null to look it up
     *
     * @return array<string,mixed>
     */
    public static function appointment(array $a, ?array $customer = null): array
    {
        $listingId = (int) $a['listing_id'];
        if ($customer === null && ! empty($a['customer_id'])) {
            $row      = BookingCustomerModel::forBusiness($listingId)->where('purged_at', null)->find((int) $a['customer_id']);
            $customer = is_array($row) ? $row : null;
        }
        $tz = self::zone((string) ($a['stored_timezone'] ?? ''));

        return [
            'id'            => (int) $a['id'],
            'reference'     => (string) $a['reference'],
            'status'        => (string) $a['status'],
            'start'         => self::utc((string) $a['start_at']),
            'end'           => self::utc((string) $a['end_at']),
            'local_start'   => self::local((string) $a['start_at'], $tz),
            'local_end'     => self::local((string) $a['end_at'], $tz),
            'timezone'      => $tz->getName(),
            'service'       => ['id' => (int) $a['service_id'], 'name' => (string) $a['service_name'], 'duration_min' => (int) $a['service_duration_min']],
            'provider'      => ['id' => (int) $a['provider_id'], 'name' => (string) $a['provider_name']],
            'customer'      => $customer !== null ? self::customerSummary($customer) : null,
            'location'      => [
                'id'      => $a['location_id'] !== null ? (int) $a['location_id'] : null,
                'name'    => (string) ($a['location_name'] ?? ''),
                'address' => (string) ($a['location_address'] ?? ''),
            ],
            'delivery_mode' => (string) $a['delivery_mode'],
            'video_link'    => ($a['video_link'] ?? '') !== '' ? (string) $a['video_link'] : null,
            'price'         => $a['price_cents'] !== null ? ['amount_cents' => (int) $a['price_cents'], 'currency' => (string) $a['currency']] : null,
            'deposit'       => ($a['payment_status'] ?? 'none') !== 'none'
                ? ['status' => (string) $a['payment_status'], 'amount_cents' => (int) $a['payment_amount_cents'], 'currency' => (string) $a['currency']]
                : null,
            'notes'         => $a['notes'] !== null ? (string) $a['notes'] : null,
            'source'        => (string) $a['source'],
            'created_at'    => self::utc((string) $a['created_at']),
            'updated_at'    => self::utc((string) $a['updated_at']),
        ];
    }

    /**
     * @param array<string,mixed> $c
     *
     * @return array<string,mixed>
     */
    public static function customerSummary(array $c): array
    {
        return [
            'id'         => (int) $c['id'],
            'first_name' => (string) $c['first_name'],
            'last_name'  => (string) ($c['last_name'] ?? ''),
            'email'      => ($c['email'] ?? '') !== '' ? (string) $c['email'] : null,
            'phone'      => ($c['phone'] ?? '') !== '' ? (string) $c['phone'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $c
     *
     * @return array<string,mixed>
     */
    public static function customer(array $c): array
    {
        return self::customerSummary($c) + [
            'address'    => ($c['address'] ?? '') !== '' ? (string) $c['address'] : null,
            'notes'      => ($c['notes'] ?? '') !== '' ? (string) $c['notes'] : null,
            'created_at' => self::utc((string) $c['created_at']),
            'updated_at' => self::utc((string) $c['updated_at']),
        ];
    }

    /**
     * @param array<string,mixed> $s a service as CatalogueService returns it
     * @param list<int>           $providerIds
     *
     * @return array<string,mixed>
     */
    public static function service(array $s, array $providerIds = []): array
    {
        $modes = $s['delivery_modes'];
        if (is_string($modes)) {
            $modes = CatalogueService::decodeModes($modes);
        }

        return [
            'id'             => (int) $s['id'],
            'name'           => (string) $s['name'],
            'description'    => ($s['description'] ?? '') !== '' ? (string) $s['description'] : null,
            'duration_min'   => (int) $s['duration_min'],
            'price'          => $s['price_cents'] !== null ? ['amount_cents' => (int) $s['price_cents'], 'currency' => (string) $s['currency']] : null,
            'deposit_pct'    => (int) $s['deposit_pct'],
            'category_id'    => $s['category_id'] !== null ? (int) $s['category_id'] : null,
            'delivery_modes' => array_values((array) $modes),
            'provider_ids'   => array_values(array_map('intval', $providerIds)),
        ];
    }

    /**
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    public static function provider(array $p): array
    {
        return [
            'id'       => (int) $p['id'],
            'name'     => (string) $p['name'],
            'title'    => ($p['title'] ?? '') !== '' ? (string) $p['title'] : null,
            'bookable' => (bool) $p['bookable'],
        ];
    }

    public static function utc(string $mysql): ?string
    {
        if ($mysql === '') {
            return null;
        }

        return (new DateTimeImmutable($mysql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private static function local(string $mysql, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable($mysql, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d\TH:i:sP');
    }

    private static function zone(string $name): DateTimeZone
    {
        return new DateTimeZone(in_array($name, timezone_identifiers_list(), true) ? $name : 'Africa/Johannesburg');
    }
}
