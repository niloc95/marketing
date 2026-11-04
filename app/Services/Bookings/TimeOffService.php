<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingBlockedTimeModel;
use App\Models\Bookings\BookingProviderModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Time off: a provider away, or the whole business closed, between two
 * moments. The only writer of xs_booking_blocked_times.
 *
 * Bookings' availability read this table (xs_blocked_times) but nothing could
 * write it; its only closures were one global setting. Entered in the
 * business's own time zone, stored in UTC (Bookings' rule).
 */
class TimeOffService
{
    public const MAX_DAYS = 366;

    public function __construct(private int $listingId, private string $timezone, private ?BookingAudit $audit = null)
    {
        $this->audit ??= new BookingAudit();
    }

    /**
     * Time off that has not ended, soonest first, with local times added.
     *
     * @param list<int>|null $providerIds null for all; otherwise these
     *                                    providers plus whole business closures
     *
     * @return list<array<string,mixed>>
     */
    public function upcoming(?array $providerIds = null): array
    {
        $model = BookingBlockedTimeModel::forBusiness($this->listingId)
            ->select('xs_booking_blocked_times.*, p.name AS provider_name')
            ->join('xs_booking_providers p', 'p.id = xs_booking_blocked_times.provider_id', 'left')
            ->where('xs_booking_blocked_times.end_at >=', gmdate('Y-m-d H:i:s'));
        if ($providerIds !== null) {
            $model->groupStart()
                ->where('xs_booking_blocked_times.provider_id', null)
                ->orWhereIn('xs_booking_blocked_times.provider_id', $providerIds === [] ? [0] : $providerIds)
                ->groupEnd();
        }
        $rows = $model->orderBy('xs_booking_blocked_times.start_at', 'ASC')->findAll(200);

        foreach ($rows as &$row) {
            $row['start_local'] = $this->toLocal((string) $row['start_at']);
            $row['end_local']   = $this->toLocal((string) $row['end_at']);
        }

        return $rows;
    }

    /**
     * Add time off. Start and end are local date and time strings
     * ("2026-12-24T13:00", the datetime-local input).
     *
     * @return array{id:?int,errors:array<string,string>}
     */
    public function add(?int $providerId, string $startLocal, string $endLocal, string $reason, ?int $accountId): array
    {
        $errors = [];
        if ($providerId !== null && BookingProviderModel::forBusiness($this->listingId)->find($providerId) === null) {
            $errors['provider_id'] = 'Choose one of the people customers book.';
        }
        $start = $this->toUtc($startLocal);
        $end   = $this->toUtc($endLocal);
        if ($start === null || $end === null) {
            $errors['start'] = 'Enter a start and an end date and time.';
        } elseif ($start >= $end) {
            $errors['start'] = 'The start must be before the end.';
        } elseif ((strtotime($end) - strtotime($start)) > self::MAX_DAYS * DAY) {
            $errors['start'] = 'Add time off of up to a year at a time.';
        } elseif (strtotime($end . ' UTC') < time()) {
            $errors['start'] = 'That time is already over.';
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 160) {
            $errors['reason'] = 'Keep the note under 160 characters.';
        }
        if ($errors !== []) {
            return ['id' => null, 'errors' => $errors];
        }

        $id = (int) BookingBlockedTimeModel::forBusiness($this->listingId)->insert([
            'provider_id' => $providerId,
            'start_at'    => $start,
            'end_at'      => $end,
            'reason'      => $reason === '' ? null : $reason,
            'created_by'  => $accountId,
        ]);
        $this->audit->record($this->listingId, $accountId, 'time_off.added', 'time_off', $id, null, [
            'provider_id' => $providerId, 'start' => $startLocal, 'end' => $endLocal,
        ]);

        return ['id' => $id, 'errors' => []];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = BookingBlockedTimeModel::forBusiness($this->listingId)->find($id);

        return is_array($row) ? $row : null;
    }

    public function delete(int $id, ?int $accountId): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        BookingBlockedTimeModel::forBusiness($this->listingId)->delete($id);
        $this->audit->record($this->listingId, $accountId, 'time_off.removed', 'time_off', $id);

        return true;
    }

    private function toUtc(string $local): ?string
    {
        $local = trim($local);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $local)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', str_replace('T', ' ', $local), new DateTimeZone($this->timezone));

        return $dt === false ? null : $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function toLocal(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');
    }
}
