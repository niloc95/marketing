<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingAppointmentModel as A;
use DateTimeImmutable;
use DateTimeZone;

/**
 * /bookings/analytics: how bookings went over a period, compared with the
 * period before. Ported from Bookings' AnalyticsService and
 * BookingMetricsService (parity.md §3), with these changes:
 *
 * - One business, and the people from the member's provider scope, never
 *   from the request. A provider filter can only narrow that scope.
 * - Periods are the business's local days, and a booking counts on the day
 *   it starts.
 * - Value is the booked value: the price saved on each appointment that was
 *   not cancelled or missed. It is not money received; payments arrive with
 *   deposits (Phase 8).
 * - Only figures the data can carry. Bookings' staff utilisation (a fixed
 *   160 bookings a month per person), retention by month (the overall rate
 *   times 0.9, 0.8, 0.7) and its revenue forecast are left out.
 * - One query per period, summed in PHP: a business has hundreds or a few
 *   thousand appointments a year, not millions. Cached five minutes
 *   (BookingMetricsCache).
 */
class AnalyticsService
{
    /** Period key => [days, label]. 12m groups by month, the rest by day. */
    public const PERIODS = [
        '7d'  => [7, 'Last 7 days'],
        '30d' => [30, 'Last 30 days'],
        '90d' => [90, 'Last 90 days'],
        '12m' => [365, 'Last 12 months'],
    ];

    /** Statuses whose price counts as booked value. */
    private const VALUED = [A::STATUS_PENDING, A::STATUS_CONFIRMED, A::STATUS_COMPLETED];

    private int $listingId;

    private DateTimeZone $tz;

    private DateTimeImmutable $now;

    /**
     * @param array<string,mixed> $listing
     * @param list<int>|null      $scope   provider ids the member may see, null for all
     */
    public function __construct(array $listing, private ?array $scope, ?DateTimeImmutable $now = null)
    {
        $this->listingId = (int) $listing['id'];
        $this->tz        = \App\Services\Bookings\BusinessLocale::zone($listing);
        $this->now       = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
    }

    public static function period(?string $key): string
    {
        return isset(self::PERIODS[(string) $key]) ? (string) $key : '30d';
    }

    /**
     * The whole report for a period, narrowed to one person when asked (and
     * only inside the member's scope; anything else is ignored).
     *
     * @return array<string,mixed>
     */
    public function report(string $period, ?int $providerId = null): array
    {
        $period = self::period($period);
        $scope  = $this->scope;
        if ($providerId !== null && $providerId > 0 && ($scope === null || in_array($providerId, $scope, true))) {
            $scope = [$providerId];
        }
        $key = 'analytics:' . $period . ':' . $this->now->format('Y-m-d H') . ':' . json_encode($scope);

        return BookingMetricsCache::remember($this->listingId, $key, fn (): array => $this->build($period, $scope));
    }

    /**
     * @param list<int>|null $scope
     *
     * @return array<string,mixed>
     */
    private function build(string $period, ?array $scope): array
    {
        [$days, $label] = self::PERIODS[$period];
        $end       = $this->now->setTime(0, 0)->modify('+1 day');
        $start     = $end->modify('-' . $days . ' days');
        $prevStart = $start->modify('-' . $days . ' days');

        $rows     = $this->rows($start, $end, $scope);
        $previous = $this->totals($this->rows($prevStart, $start, $scope));
        $totals   = $this->totals($rows);
        $firsts   = $this->firstVisits(array_values(array_unique(array_filter(array_column($rows, 'customer_id')))), $scope);
        $startUtc = $this->utc($start);

        $new = 0;
        foreach (array_unique(array_filter(array_column($this->booked($rows), 'customer_id'))) as $customerId) {
            if (($firsts[(int) $customerId] ?? '') >= $startUtc) {
                $new++;
            }
        }
        $totals['new_customers']       = $new;
        $totals['returning_customers'] = $totals['customers'] - $new;

        return [
            'period'   => $period,
            'label'    => $label,
            'from'     => $start->format('Y-m-d'),
            'to'       => $end->modify('-1 day')->format('Y-m-d'),
            'currency' => $this->currency($rows),
            'totals'   => $totals,
            'previous' => $previous,
            'change'   => [
                'booked'    => self::change($totals['booked'], $previous['booked']),
                'value'     => self::change($totals['value_cents'], $previous['value_cents']),
                'customers' => self::change($totals['customers'], $previous['customers']),
            ],
            'series'   => $this->series($rows, $start, $end, $period === '12m'),
            'statuses' => $this->byStatus($rows),
            'services' => $this->byKey($rows, 'service_id', 'service_name'),
            'team'     => $this->byKey($rows, 'provider_id', 'provider_name'),
            'weekdays' => $this->byWeekday($rows),
            'hours'    => $this->byHour($rows),
            'top'      => $this->topCustomers($rows),
        ];
    }

    /**
     * Appointments starting in [from, to), local times added.
     *
     * @param list<int>|null $scope
     *
     * @return list<array<string,mixed>>
     */
    private function rows(DateTimeImmutable $from, DateTimeImmutable $to, ?array $scope): array
    {
        if ($scope === []) {
            return [];
        }
        $a     = 'xs_booking_appointments';
        $model = A::forBusiness($this->listingId)
            ->select($a . '.id, ' . $a . '.status, ' . $a . '.source, ' . $a . '.provider_id, ' . $a . '.provider_name, '
                . $a . '.service_id, ' . $a . '.service_name, ' . $a . '.price_cents, ' . $a . '.currency, '
                . $a . '.service_duration_min, ' . $a . '.customer_id, ' . $a . '.start_at, c.first_name, c.last_name, c.hash AS customer_hash')
            ->join('xs_booking_customers c', 'c.id = ' . $a . '.customer_id AND c.listing_id = ' . $a . '.listing_id', 'left')
            ->where($a . '.start_at >=', $this->utc($from))
            ->where($a . '.start_at <', $this->utc($to));
        if ($scope !== null) {
            $model->whereIn($a . '.provider_id', $scope);
        }
        $rows = $model->findAll();
        $utc  = new DateTimeZone('UTC');
        foreach ($rows as &$row) {
            $row['local'] = (new DateTimeImmutable((string) $row['start_at'], $utc))->setTimezone($this->tz);
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return array<string,int|float>
     */
    private function totals(array $rows): array
    {
        $count = static fn (string $status): int => count(array_filter($rows, static fn (array $r): bool => $r['status'] === $status));
        $booked    = $this->booked($rows);
        $completed = $count(A::STATUS_COMPLETED);
        $noShow    = $count(A::STATUS_NO_SHOW);
        $cancelled = $count(A::STATUS_CANCELLED);
        $online    = count(array_filter($booked, static fn (array $r): bool => $r['source'] === 'public'));
        $value     = $this->value($rows);
        $priced    = count(array_filter($rows, fn (array $r): bool => $this->valued($r)));

        return [
            'booked'           => count($booked),
            'completed'        => $completed,
            'cancelled'        => $cancelled,
            'no_show'          => $noShow,
            'online'           => $online,
            'online_share'     => self::share($online, count($booked)),
            'cancel_rate'      => self::share($cancelled, count($rows)),
            'no_show_rate'     => self::share($noShow, $completed + $noShow),
            'value_cents'      => $value,
            'average_cents'    => $priced > 0 ? intdiv($value, $priced) : 0,
            'minutes'          => array_sum(array_map(static fn (array $r): int => (int) $r['service_duration_min'], $booked)),
            'customers'        => count(array_unique(array_filter(array_column($booked, 'customer_id')))),
        ];
    }

    /**
     * Everything that kept its time: all but cancelled. A no show still
     * took the time, so it counts as booked, but not as value.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array<string,mixed>>
     */
    private function booked(array $rows): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => $r['status'] !== A::STATUS_CANCELLED));
    }

    /** @param array<string,mixed> $row */
    private function valued(array $row): bool
    {
        return in_array($row['status'], self::VALUED, true) && $row['price_cents'] !== null;
    }

    /** @param list<array<string,mixed>> $rows */
    private function value(array $rows): int
    {
        $sum = 0;
        foreach ($rows as $r) {
            $sum += $this->valued($r) ? (int) $r['price_cents'] : 0;
        }

        return $sum;
    }

    /**
     * The first appointment (not cancelled) of each customer, in scope.
     *
     * @param list<int|string> $customerIds
     * @param list<int>|null   $scope
     *
     * @return array<int,string> customer id => UTC start
     */
    private function firstVisits(array $customerIds, ?array $scope): array
    {
        if ($customerIds === []) {
            return [];
        }
        $model = A::forBusiness($this->listingId)
            ->select('customer_id, MIN(start_at) AS first_at')
            ->whereIn('customer_id', array_map('intval', $customerIds))
            ->where('status !=', A::STATUS_CANCELLED)
            ->groupBy('customer_id');
        if ($scope !== null) {
            $model->whereIn('provider_id', $scope);
        }
        $out = [];
        foreach ($model->findAll() as $r) {
            $out[(int) $r['customer_id']] = (string) $r['first_at'];
        }

        return $out;
    }

    /**
     * Booked per day (or per month), split into online and by the business.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{key:string,label:string,short:string,online:int,business:int,value_cents:int}>
     */
    private function series(array $rows, DateTimeImmutable $start, DateTimeImmutable $end, bool $byMonth): array
    {
        $format  = $byMonth ? 'Y-m' : 'Y-m-d';
        $buckets = [];
        for ($d = $byMonth ? $start->modify('first day of this month') : $start; $d < $end; $d = $d->modify($byMonth ? '+1 month' : '+1 day')) {
            $key           = $d->format($format);
            $buckets[$key] = ['key' => $key, 'label' => $d->format($byMonth ? 'M Y' : 'D j M'), 'short' => $d->format($byMonth ? 'M' : 'j M'), 'online' => 0, 'business' => 0, 'value_cents' => 0];
        }
        foreach ($rows as $r) {
            $key = $r['local']->format($format);
            if (! isset($buckets[$key])) {
                continue;
            }
            if ($r['status'] !== A::STATUS_CANCELLED) {
                $buckets[$key][$r['source'] === 'public' ? 'online' : 'business']++;
            }
            $buckets[$key]['value_cents'] += $this->valued($r) ? (int) $r['price_cents'] : 0;
        }

        return array_values($buckets);
    }

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{status:string,label:string,count:int}>
     */
    private function byStatus(array $rows): array
    {
        $counts = array_count_values(array_column($rows, 'status'));

        return array_map(static fn (string $s): array => ['status' => $s, 'label' => AppointmentStatus::label($s), 'count' => (int) ($counts[$s] ?? 0)], AppointmentStatus::ALL);
    }

    /**
     * Per service or per person, most booked first. Names are the
     * appointment's own snapshots, the latest one winning.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{id:int,name:string,booked:int,completed:int,cancelled:int,no_show:int,value_cents:int,minutes:int,share:float}>
     */
    private function byKey(array $rows, string $idKey, string $nameKey): array
    {
        $out = [];
        foreach ($rows as $r) {
            $id         = (int) $r[$idKey];
            $out[$id] ??= ['id' => $id, 'name' => '', 'booked' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0, 'value_cents' => 0, 'minutes' => 0, 'share' => 0.0];
            $out[$id]['name'] = (string) $r[$nameKey];
            if ($r['status'] === A::STATUS_CANCELLED) {
                $out[$id]['cancelled']++;
                continue;
            }
            $out[$id]['booked']++;
            $out[$id]['minutes'] += (int) $r['service_duration_min'];
            $out[$id]['completed'] += $r['status'] === A::STATUS_COMPLETED ? 1 : 0;
            $out[$id]['no_show'] += $r['status'] === A::STATUS_NO_SHOW ? 1 : 0;
            $out[$id]['value_cents'] += $this->valued($r) ? (int) $r['price_cents'] : 0;
        }
        $total = array_sum(array_column($out, 'booked'));
        foreach ($out as &$o) {
            $o['share'] = self::share($o['booked'], $total);
        }
        unset($o);
        usort($out, static fn (array $a, array $b): int => [$b['booked'], $b['value_cents'], $a['name']] <=> [$a['booked'], $a['value_cents'], $b['name']]);

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{label:string,count:int}> Monday first
     */
    private function byWeekday(array $rows): array
    {
        $out = [];
        foreach (TeamService::WEEKDAYS as $n => $name) {
            $out[$n] = ['label' => $name, 'count' => 0];
        }
        foreach ($this->booked($rows) as $r) {
            $out[(int) $r['local']->format('N')]['count']++;
        }

        return array_values($out);
    }

    /**
     * Booked by the hour they start, from the first busy hour to the last.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{label:string,count:int}>
     */
    private function byHour(array $rows): array
    {
        $counts = [];
        foreach ($this->booked($rows) as $r) {
            $h          = (int) $r['local']->format('G');
            $counts[$h] = ($counts[$h] ?? 0) + 1;
        }
        if ($counts === []) {
            return [];
        }
        $out = [];
        for ($h = min(array_keys($counts)); $h <= max(array_keys($counts)); $h++) {
            $out[] = ['label' => sprintf('%02d:00', $h), 'count' => $counts[$h] ?? 0];
        }

        return $out;
    }

    /**
     * The customers with the most visits in the period.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{hash:string,name:string,booked:int,value_cents:int}>
     */
    private function topCustomers(array $rows, int $limit = 10): array
    {
        $out = [];
        foreach ($this->booked($rows) as $r) {
            if ($r['customer_id'] === null || $r['customer_hash'] === null) {
                continue;
            }
            $id         = (int) $r['customer_id'];
            $out[$id] ??= ['hash' => (string) $r['customer_hash'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'booked' => 0, 'value_cents' => 0];
            $out[$id]['booked']++;
            $out[$id]['value_cents'] += $this->valued($r) ? (int) $r['price_cents'] : 0;
        }
        usort($out, static fn (array $a, array $b): int => [$b['booked'], $b['value_cents'], $a['name']] <=> [$a['booked'], $a['value_cents'], $b['name']]);

        return array_slice($out, 0, $limit);
    }

    /** @param list<array<string,mixed>> $rows */
    private function currency(array $rows): string
    {
        $seen = array_count_values(array_filter(array_column($rows, 'currency')));
        arsort($seen);

        return (string) (array_key_first($seen) ?? 'ZAR');
    }

    /** Percentage change, or null when there is nothing to compare with. */
    public static function change(int|float $now, int|float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    /** A part as a percentage of a whole, one decimal. */
    public static function share(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    private function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
