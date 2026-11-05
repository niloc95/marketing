<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingAppointmentModel as A;
use App\Models\Bookings\BookingCustomerModel;
use App\Models\Bookings\BookingDeliveryLogModel;
use App\Services\Bookings\Calendar\CalendarQuery;
use App\Services\Bookings\Payments\DepositService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The /business landing page: today's figures, today's appointments, the
 * team's free times, and what needs attention. Ported from Bookings'
 * DashboardService, with these changes (parity.md §3):
 *
 * - One business, and the people from the member's provider scope (null for
 *   all), never from the request. An empty scope sees nothing.
 * - "Today" and every window are the business's local days.
 * - Customers are counted in scope: a team member counts their own.
 * - Bookings' "revenue this month" becomes the booked value of this month,
 *   from the price on each appointment; it is not money received. Pending
 *   payments wait for deposits (Phase 8).
 * - Alerts are the ones Bookings documented and never built (missing hours,
 *   closures) plus failed messages; only pending confirmations existed there.
 *   Overbooking is left out: BookingLock refuses it.
 * - A person's state now (working, on a break, off, time off) is read in the
 *   business's time zone; Bookings used the server's clock.
 */
class DashboardService
{
    /** Statuses on today's list: what happens or happened today. */
    private const TODAY = [A::STATUS_PENDING, A::STATUS_CONFIRMED, A::STATUS_COMPLETED, A::STATUS_NO_SHOW];

    /** Free times shown per person on the dashboard. */
    private const FREE_TIMES = 6;

    private int $listingId;

    private DateTimeZone $tz;

    private DateTimeImmutable $now;

    /**
     * @param array<string,mixed> $listing
     * @param list<int>|null      $scope   provider ids the member may see, null for all
     */
    public function __construct(private array $listing, private ?array $scope, ?DateTimeImmutable $now = null)
    {
        $this->listingId = (int) $listing['id'];
        $this->tz        = \App\Services\Bookings\BusinessLocale::zone($listing);
        $this->now       = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
    }

    public function today(): string
    {
        return $this->now->format('Y-m-d');
    }

    /**
     * The figures across the top, cached five minutes per business and scope.
     *
     * @return array{today:int,next_hours:int,pending:int,week:int,customers:int,month_value_cents:int,deposits_cents:int,currency:string}
     */
    public function cards(): array
    {
        $key = 'cards:' . $this->today() . ':' . $this->now->format('H') . ':' . json_encode($this->scope);

        return BookingMetricsCache::remember($this->listingId, $key, function (): array {
            if ($this->scope === []) {
                return ['today' => 0, 'next_hours' => 0, 'pending' => 0, 'week' => 0, 'customers' => 0, 'month_value_cents' => 0, 'deposits_cents' => 0, 'currency' => 'ZAR'];
            }
            [$dayStart, $dayEnd] = $this->dayUtc($this->today());
            $nowUtc   = $this->utc($this->now);
            $soonUtc  = $this->utc($this->now->modify('+4 hours'));
            $weekEnd  = $this->dayUtc($this->now->modify('+6 days')->format('Y-m-d'))[1];
            $monthBeg = $this->dayUtc($this->now->format('Y-m-01'))[0];
            $monthEnd = $this->dayUtc($this->now->format('Y-m-t'))[1];

            $value = $this->appointments()
                ->select('COALESCE(SUM(price_cents), 0) AS cents, MAX(currency) AS currency')
                ->where('start_at >=', $monthBeg)->where('start_at <', $monthEnd)
                ->whereIn('status', [A::STATUS_PENDING, A::STATUS_CONFIRMED, A::STATUS_COMPLETED])
                ->first();

            return [
                'today'             => $this->appointments()->where('start_at >=', $dayStart)->where('start_at <', $dayEnd)->whereIn('status', self::TODAY)->countAllResults(),
                'next_hours'        => $this->appointments()->where('start_at >=', $nowUtc)->where('start_at <', $soonUtc)->whereIn('status', AppointmentStatus::UPCOMING)->countAllResults(),
                'pending'           => $this->pendingCount(),
                'week'              => $this->appointments()->where('start_at >=', $dayStart)->where('start_at <', $weekEnd)->whereIn('status', self::TODAY)->countAllResults(),
                'customers'         => $this->customerCount(),
                'month_value_cents' => (int) ($value['cents'] ?? 0),
                'deposits_cents'    => (new DepositService($this->listing, $this->now))->receivedThisMonth($this->scope),
                'currency'          => (string) ($value['currency'] ?? '') ?: 'ZAR',
            ];
        });
    }

    /**
     * Today's appointments by person, in team order, each with its place in
     * the day (`past`, `now` or `later`). Not cached: it is the list a
     * reception desk works from.
     *
     * @return list<array{provider_id:int,name:string,color:string,appointments:list<array<string,mixed>>}>
     */
    public function schedule(): array
    {
        $events = (new CalendarQuery($this->listingId, $this->tz))->forRange($this->today(), $this->today(), ['status' => self::TODAY], $this->scope);
        $now    = $this->now->format('Y-m-d\TH:i:sP');
        $groups = [];
        foreach ($events as $e) {
            $e['when'] = $e['end'] <= $now ? 'past' : ($e['start'] <= $now ? 'now' : 'later');
            $e['time'] = substr((string) $e['start'], 11, 5) . ' to ' . substr((string) $e['end'], 11, 5);
            $groups[$e['provider_id']] ??= ['provider_id' => $e['provider_id'], 'name' => $e['provider_name'], 'color' => $e['provider_color'], 'appointments' => []];
            $groups[$e['provider_id']]['appointments'][] = $e;
        }
        $order = array_flip(array_map('intval', array_column((new TeamService($this->listingId))->providers(true), 'id')));
        uksort($groups, static fn (int $a, int $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return array_values($groups);
    }

    /**
     * The next appointments after today, up to a week ahead.
     *
     * @return list<array<string,mixed>>
     */
    public function comingUp(int $limit = 8): array
    {
        $from   = $this->now->modify('+1 day')->format('Y-m-d');
        $to     = $this->now->modify('+7 days')->format('Y-m-d');
        $events = (new CalendarQuery($this->listingId, $this->tz))->forRange($from, $to, ['status' => AppointmentStatus::UPCOMING], $this->scope);

        return array_slice($events, 0, $limit);
    }

    /**
     * Bookings waiting for confirmation, from today on, earliest first.
     *
     * @return list<array<string,mixed>>
     */
    public function waiting(int $limit = 10): array
    {
        $to     = $this->now->modify('+1 year')->format('Y-m-d');
        $events = (new CalendarQuery($this->listingId, $this->tz))->forRange($this->today(), $to, ['status' => [A::STATUS_PENDING]], $this->scope);
        $from   = $this->now->format('Y-m-d') . 'T00:00';

        // A booking still waiting for its deposit is the customer's to finish, not the business's to confirm.
        return array_slice(array_values(array_filter($events, static fn (array $e): bool => $e['start'] >= $from && $e['payment_status'] !== A::PAYMENT_DUE)), 0, $limit);
    }

    /**
     * Each bookable person in scope: their state now, today's next free
     * times for their first service (the quick book links), and their next
     * free time online when today has none. Ported from Bookings'
     * getProviderAvailability() and provider-slots.
     *
     * @return list<array<string,mixed>>
     */
    public function team(): array
    {
        if ($this->scope === []) {
            return [];
        }
        $team    = new TeamService($this->listingId);
        $catalog = new CatalogueService($this->listingId);
        $engine  = new AvailabilityService($this->listing, $this->now);
        $off     = $this->timeOffNow();
        $weekday = (int) $this->now->format('N');
        $clock   = $this->now->format('H:i');
        $nowUtc  = $this->utc($this->now);
        $links   = $catalog->providerLinks();
        $all     = $catalog->services();
        $out     = [];

        foreach ($team->providers() as $p) {
            $id = (int) $p['id'];
            if (! $p['bookable'] || ($this->scope !== null && ! in_array($id, $this->scope, true))) {
                continue;
            }
            // Their first service in the business's own order.
            $services = array_values(array_filter($all, static fn (array $s): bool => in_array($id, $links[(int) $s['id']] ?? [], true)));
            if ($services === []) {
                continue; // Nothing to book with them (Bookings left them off too).
            }
            $service = $services[0];
            $day     = $team->hours($id)[$weekday];
            $state   = 'off';
            if (isset($off[0]) || isset($off[$id])) {
                $state = 'time_off';
            } elseif ($day['active'] && $clock >= $day['start'] && $clock < $day['end']) {
                $onBreak = $day['break_start'] !== '' && $clock >= $day['break_start'] && $clock < $day['break_end'];
                $state   = $onBreak ? 'break' : 'working';
            }
            $free = array_values(array_filter(
                $engine->slots($id, (int) $service['id'], $this->today(), false),
                static fn (array $s): bool => $s['start'] >= $nowUtc,
            ));
            $next = $free === [] ? $engine->nextAvailable((int) $service['id'], $id, $this->now->modify('+1 day')->format('Y-m-d'), 14) : null;

            $out[] = [
                'id'         => $id,
                'name'       => (string) $p['name'],
                'color'      => CatalogueService::hex($p['color'] ?? null),
                'state'      => $state,
                'service'    => ['id' => (int) $service['id'], 'name' => (string) $service['name']],
                'free_count' => count($free),
                'free'       => array_map(static fn (array $s): string => (string) $s['time'], array_slice($free, 0, self::FREE_TIMES)),
                'next'       => $next,
            ];
        }

        return $out;
    }

    /**
     * What needs a person's attention, most urgent first. Each alert has a
     * level (`warn` or `info`), its text, and a link to act on it.
     *
     * @param array<string,mixed> $member
     *
     * @return list<array{code:string,level:string,text:string,link:string,action:string}>
     */
    public function alerts(array $member): array
    {
        $auth = new BookingAuthorization();
        $out  = [];

        $pending = $this->pendingCount();
        if ($pending > 0) {
            $out[] = [
                'code' => 'pending', 'level' => 'warn',
                'text' => $pending === 1 ? 'One booking is waiting for your confirmation.' : $pending . ' bookings are waiting for your confirmation.',
                'link' => 'business#waiting', 'action' => 'Review',
            ];
        }

        if ($auth->can($member, 'providers.manage') || $auth->can($member, 'services.manage')) {
            foreach (SetupCheck::problems($this->listingId) as $p) {
                $out[] = ['code' => $p['code'], 'level' => 'warn', 'text' => $p['text'], 'link' => $p['link'], 'action' => 'Fix'];
            }
        }

        foreach ($this->holidaysWithoutAnswer() as $h) {
            $out[] = [
                'code' => 'holiday', 'level' => 'info',
                'text' => $h['name'] . ' (' . date('D j M', (int) strtotime($h['date'])) . ') is a public holiday and your profile does not say if you are open, so customers cannot book online that day.',
                'link' => 'manage', 'action' => 'Set holiday hours',
            ];
        }

        foreach ($this->closuresSoon() as $c) {
            $out[] = [
                'code' => 'closed', 'level' => 'info',
                'text' => 'The business is closed from ' . $c['from'] . ' to ' . $c['to'] . ($c['reason'] !== '' ? ' (' . $c['reason'] . ')' : '') . '.',
                'link' => 'business/time-off', 'action' => 'Time off',
            ];
        }

        if ($auth->can($member, 'payments.manage')) {
            $refunds = count((new DepositService($this->listing, $this->now))->toRefund($this->scope));
            if ($refunds > 0) {
                $out[] = [
                    'code' => 'refund', 'level' => 'warn',
                    'text' => ($refunds === 1 ? 'One deposit was' : $refunds . ' deposits were') . ' paid on a booking that is cancelled or was freed. Refund in your PayFast or Paystack account if your terms say so, then mark it refunded.',
                    'link' => 'business/payments', 'action' => 'See payments',
                ];
            }
        }

        if ($auth->can($member, 'settings.manage')) {
            $failed = $this->failedMessages();
            if ($failed > 0) {
                $out[] = [
                    'code' => 'messages', 'level' => 'warn',
                    'text' => ($failed === 1 ? 'One message' : $failed . ' messages') . ' to customers could not be sent in the last 7 days.',
                    'link' => 'business/notifications/sent?outcome=failed', 'action' => 'See why',
                ];
            }
        }

        return $out;
    }

    // Internals

    private function appointments(): A
    {
        $model = A::forBusiness($this->listingId);
        if ($this->scope !== null) {
            $model->whereIn('provider_id', $this->scope);
        }

        return $model;
    }

    /** Waiting for confirmation, from the start of today on (Bookings' rule). */
    private function pendingCount(): int
    {
        if ($this->scope === []) {
            return 0;
        }

        return $this->appointments()->where('status', A::STATUS_PENDING)->where('payment_status !=', A::PAYMENT_DUE)
            ->where('start_at >=', $this->dayUtc($this->today())[0])->countAllResults();
    }

    /** All customers for the whole business; a member's own otherwise (permissions.md). */
    private function customerCount(): int
    {
        if ($this->scope === null) {
            return BookingCustomerModel::forBusiness($this->listingId)->countAllResults();
        }
        $row = $this->appointments()->select('COUNT(DISTINCT customer_id) AS n')->where('customer_id IS NOT NULL')->first();

        return (int) ($row['n'] ?? 0);
    }

    /**
     * Time off running now, keyed by provider id (0 for the whole business).
     *
     * @return array<int,true>
     */
    private function timeOffNow(): array
    {
        $nowUtc = $this->utc($this->now);
        $out    = [];
        foreach ((new TimeOffService($this->listingId, $this->tz->getName()))->upcoming($this->scope) as $t) {
            if ((string) $t['start_at'] <= $nowUtc && (string) $t['end_at'] > $nowUtc) {
                $out[(int) ($t['provider_id'] ?? 0)] = true;
            }
        }

        return $out;
    }

    /**
     * Whole business closures that start within the next 14 days.
     *
     * @return list<array{from:string,to:string,reason:string}>
     */
    private function closuresSoon(): array
    {
        $limit = $this->utc($this->now->modify('+14 days'));
        $out   = [];
        foreach ((new TimeOffService($this->listingId, $this->tz->getName()))->upcoming([]) as $t) {
            if ($t['provider_id'] === null && (string) $t['start_at'] < $limit) {
                $out[] = [
                    'from'   => $this->shortLocal((string) $t['start_local']),
                    'to'     => $this->shortLocal((string) $t['end_local']),
                    'reason' => trim((string) ($t['reason'] ?? '')),
                ];
            }
        }

        return $out;
    }

    /**
     * Public holidays in the next 30 days (and inside the booking horizon)
     * that the profile has no answer for: the engine takes no online bookings
     * then (AvailabilityService::businessWindow()).
     *
     * @return list<array{date:string,name:string}>
     */
    private function holidaysWithoutAnswer(): array
    {
        helper('directory_hours');
        $days = min(30, max(1, BookingSettings::for($this->listingId)->int('business.future_limit')));
        $to   = $this->now->modify('+' . $days . ' days')->format('Y-m-d');
        $from = $this->today();
        $rule = hours_holiday_rule($this->listing['trading_hours'] ?? null);
        $week = hours_decode($this->listing['trading_hours'] ?? null);
        $list = hours_within($this->listing, static fn () => hours_calendar()->between($from, $to));
        $out  = [];
        foreach ($list as $h) {
            $weekday = hours_weekday_row($week, (string) $h['date']);
            if (! empty($weekday['closed'])) {
                continue; // Closed that weekday anyway.
            }
            if (hours_holiday_answer($rule, (string) $h['key'], $weekday) === null) {
                $out[] = ['date' => (string) $h['date'], 'name' => (string) $h['name']];
            }
        }

        return $out;
    }

    private function failedMessages(): int
    {
        return BookingDeliveryLogModel::forBusiness($this->listingId)
            ->where('outcome', 'failed')
            ->where('created_at >=', $this->utc($this->now->modify('-7 days')))
            ->countAllResults();
    }

    /** "Wed 24 Dec 13:00" from "2026-12-24T13:00" (or with a space). */
    private function shortLocal(string $local): string
    {
        $t = strtotime(str_replace('T', ' ', $local));

        return $t === false ? $local : date('D j M H:i', $t);
    }

    /** @return array{0:string,1:string} start of a local day and of the next, in UTC */
    private function dayUtc(string $date): array
    {
        $start = new DateTimeImmutable($date . ' 00:00', $this->tz);

        return [$this->utc($start), $this->utc($start->modify('+1 day'))];
    }

    private function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
