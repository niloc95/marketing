<?php

namespace App\Services\Bookings\Calendar;

use App\Models\Bookings\BookingAppointmentModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The appointments a calendar view shows, already shaped for the screen.
 * Ported from Bookings' AppointmentQueryService::getForRange() and
 * AppointmentFormatterService::formatForCalendar(), with these changes:
 *
 * - One business only (BookingScopedModel), and the people come from the
 *   member's provider scope, passed in by the caller and never from the
 *   request (permissions.md). An empty scope sees nothing.
 * - The range is the business's local days, and an appointment counts when
 *   it overlaps them (Bookings took only those starting inside).
 * - `start` and `end` are local ISO times with the offset
 *   (2030-01-07T09:00:00+02:00); `start_utc` and `end_utc` are the stored
 *   instants. Bookings put UTC in `start` and grouped days by its first ten
 *   characters, which put evening appointments on the wrong day east of UTC.
 * - Names, duration and price are the appointment's own snapshots, so an
 *   edit to a service never rewrites history.
 * - Cancelled appointments are left out unless the status filter asks.
 */
class CalendarQuery
{
    public const DEFAULT_COLOR = '#3B82F6';

    private DateTimeZone $tz;

    private DateTimeZone $utc;

    public function __construct(private int $listingId, DateTimeZone $tz)
    {
        $this->tz  = $tz;
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param array{provider_id?:int|string|null,service_id?:int|string|null,location?:int|string|null,status?:string|list<string>|null} $filters
     * @param list<int>|null $scope provider ids the member may see, null for all
     *
     * @return list<array<string,mixed>> formatted, earliest first
     */
    public function forRange(string $fromDate, string $toDate, array $filters, ?array $scope): array
    {
        if ($scope === []) {
            return [];
        }
        $from = (new DateTimeImmutable($fromDate . ' 00:00', $this->tz))->setTimezone($this->utc)->format('Y-m-d H:i:s');
        $to   = (new DateTimeImmutable($toDate . ' 00:00', $this->tz))->modify('+1 day')->setTimezone($this->utc)->format('Y-m-d H:i:s');

        $a     = 'xs_booking_appointments';
        $model = BookingAppointmentModel::forBusiness($this->listingId)
            ->select($a . '.*, c.first_name AS customer_first_name, c.last_name AS customer_last_name,'
                . ' c.email AS customer_email, c.phone AS customer_phone, c.hash AS customer_hash, p.color AS provider_color')
            ->join('xs_booking_customers c', 'c.id = ' . $a . '.customer_id AND c.listing_id = ' . $a . '.listing_id', 'left')
            ->join('xs_booking_providers p', 'p.id = ' . $a . '.provider_id AND p.listing_id = ' . $a . '.listing_id', 'left')
            ->where($a . '.start_at <', $to)
            ->where($a . '.end_at >', $from);

        if ($scope !== null) {
            $model->whereIn($a . '.provider_id', $scope);
        }
        if (! empty($filters['provider_id'])) {
            $model->where($a . '.provider_id', (int) $filters['provider_id']);
        }
        if (! empty($filters['service_id'])) {
            $model->where($a . '.service_id', (int) $filters['service_id']);
        }
        $location = $filters['location'] ?? null;
        if ($location === 'main') {
            $model->where($a . '.location_id', null);
        } elseif ((int) $location > 0) {
            $model->where($a . '.location_id', (int) $location);
        }
        $model->whereIn($a . '.status', $this->statuses($filters['status'] ?? null));

        $rows = $model->orderBy($a . '.start_at', 'ASC')->orderBy($a . '.id', 'ASC')->findAll();

        return array_map(fn (array $row): array => $this->format($row), $rows);
    }

    /**
     * Group formatted appointments by the local date they start on.
     *
     * @param list<array<string,mixed>> $events
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function byDate(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[substr((string) $event['start'], 0, 10)][] = $event;
        }

        return $out;
    }

    /**
     * One appointment row (with the customer and provider colour joined) as
     * the calendar shows it.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function format(array $row): array
    {
        $customer = trim((string) ($row['customer_first_name'] ?? '') . ' ' . (string) ($row['customer_last_name'] ?? ''));

        return [
            'id'               => (int) $row['id'],
            'hash'             => (string) $row['hash'],
            'reference'        => $row['reference'],
            'title'            => $customer !== '' ? $customer : (string) $row['service_name'],
            'start'            => $this->local((string) $row['start_at']),
            'end'              => $this->local((string) $row['end_at']),
            'start_utc'        => $this->iso((string) $row['start_at']),
            'end_utc'          => $this->iso((string) $row['end_at']),
            'provider_id'      => (int) $row['provider_id'],
            'service_id'       => (int) $row['service_id'],
            'customer_id'      => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            'customer_hash'    => $row['customer_hash'] ?? null,
            'status'           => (string) $row['status'],
            'source'           => (string) $row['source'],
            'customer_name'    => $customer !== '' ? $customer : null,
            'email'            => $row['customer_email'] ?? null,
            'phone'            => $row['customer_phone'] ?? null,
            'service_name'     => (string) $row['service_name'],
            'provider_name'    => (string) $row['provider_name'],
            'provider_color'   => (string) ($row['provider_color'] ?? '') !== '' ? (string) $row['provider_color'] : self::DEFAULT_COLOR,
            'duration_min'     => (int) $row['service_duration_min'],
            'price_cents'      => $row['price_cents'] !== null ? (int) $row['price_cents'] : null,
            'currency'         => (string) $row['currency'],
            'notes'            => $row['notes'],
            'location_id'      => $row['location_id'] !== null ? (int) $row['location_id'] : null,
            'location_name'    => $row['location_name'],
            'location_address' => $row['location_address'],
            'delivery_mode'    => (string) $row['delivery_mode'],
            'video_link'       => (string) ($row['video_link'] ?? ''),
            'payment_status'   => (string) $row['payment_status'],
        ];
    }

    /** @return list<string> */
    private function statuses(mixed $status): array
    {
        $all = [...BookingAppointmentModel::HOLDS_TIME, BookingAppointmentModel::STATUS_CANCELLED];
        if ($status === 'all') {
            return $all;
        }
        $wanted = array_values(array_intersect((array) ($status ?? []), $all));

        return $wanted !== [] ? $wanted : BookingAppointmentModel::HOLDS_TIME;
    }

    private function local(string $utc): string
    {
        return (new DateTimeImmutable($utc, $this->utc))->setTimezone($this->tz)->format('Y-m-d\TH:i:sP');
    }

    private function iso(string $utc): string
    {
        return (new DateTimeImmutable($utc, $this->utc))->format('Y-m-d\TH:i:s\Z');
    }
}
