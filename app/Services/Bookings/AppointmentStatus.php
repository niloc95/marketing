<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingAppointmentModel as A;

/**
 * Appointment statuses, ported from Bookings' AppointmentStatus. The keys
 * are the same except `no_show` (Bookings stored `no-show`); the aliases
 * `booked` and `rescheduled` are dropped because the data is new
 * (parity.md §4). Labels follow docs/bookings/copy.md.
 */
final class AppointmentStatus
{
    public const ALL = [A::STATUS_PENDING, A::STATUS_CONFIRMED, A::STATUS_COMPLETED, A::STATUS_CANCELLED, A::STATUS_NO_SHOW];

    /** Still to happen. */
    public const UPCOMING = [A::STATUS_PENDING, A::STATUS_CONFIRMED];

    /** Over, one way or another. */
    public const PAST = [A::STATUS_COMPLETED, A::STATUS_CANCELLED, A::STATUS_NO_SHOW];

    private const LABELS = [
        A::STATUS_PENDING   => 'Waiting for confirmation',
        A::STATUS_CONFIRMED => 'Confirmed',
        A::STATUS_COMPLETED => 'Completed',
        A::STATUS_CANCELLED => 'Cancelled',
        A::STATUS_NO_SHOW   => 'No show',
    ];

    /** Short labels for chips and filters. */
    private const SHORT = [
        A::STATUS_PENDING   => 'Pending',
        A::STATUS_CONFIRMED => 'Confirmed',
        A::STATUS_COMPLETED => 'Completed',
        A::STATUS_CANCELLED => 'Cancelled',
        A::STATUS_NO_SHOW   => 'No show',
    ];

    /**
     * What the customer reads about a booking in each status (copy.md,
     * "Customer sees"): the booking page and the alert after a change.
     * Empty where copy.md has no message.
     */
    public static function customerMessage(?string $status, string $business): string
    {
        return match (self::normalize($status)) {
            A::STATUS_PENDING   => 'We have your request. ' . $business . ' will confirm it.',
            A::STATUS_CONFIRMED => 'Your booking is confirmed.',
            A::STATUS_CANCELLED => 'Your booking is cancelled.',
            default             => '',
        };
    }

    public static function normalize(?string $status): ?string
    {
        $s = strtolower(trim((string) $status));
        $s = $s === 'no-show' ? A::STATUS_NO_SHOW : $s;

        return in_array($s, self::ALL, true) ? $s : null;
    }

    public static function isValid(?string $status): bool
    {
        return self::normalize($status) !== null;
    }

    public static function label(?string $status): string
    {
        return self::LABELS[self::normalize($status) ?? ''] ?? '';
    }

    public static function short(?string $status): string
    {
        return self::SHORT[self::normalize($status) ?? ''] ?? '';
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(static fn (string $s): array => ['value' => $s, 'label' => self::SHORT[$s]], self::ALL);
    }
}
