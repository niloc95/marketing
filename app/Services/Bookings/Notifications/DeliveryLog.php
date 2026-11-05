<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\BookingDeliveryLogModel;

/**
 * One line per send attempt, for the business's "Sent messages" page and
 * for support. Ported from Bookings' NotificationDeliveryLogService, with the
 * recipient masked (decision D12): enough to tell two customers apart, not
 * enough to contact anyone. The only writer of xs_booking_delivery_logs.
 */
class DeliveryLog
{
    public const SENT      = 'sent';
    public const FAILED    = 'failed';
    public const RETRYING  = 'retrying';
    public const CANCELLED = 'cancelled';

    /** What the business reads for each outcome. */
    public const LABELS = [
        self::SENT      => 'Sent',
        self::FAILED    => 'Not sent',
        self::RETRYING  => 'Trying again',
        self::CANCELLED => 'Not needed',
    ];

    /** @param array<string,mixed> $row a queue row */
    public static function record(array $row, string $outcome, ?string $recipient, ?string $error = null): void
    {
        try {
            BookingDeliveryLogModel::forBusiness((int) $row['listing_id'])->insert([
                'queue_id'       => $row['id'] ?? null,
                'correlation_id' => $row['correlation_id'] ?? null,
                'appointment_id' => $row['appointment_id'] ?? null,
                'event'          => (string) $row['event'],
                'channel'        => (string) $row['channel'],
                'recipient_type' => (string) ($row['recipient_type'] ?? 'customer'),
                'recipient'      => $recipient === null ? null : self::mask($recipient),
                'outcome'        => $outcome,
                'attempt'        => min(255, (int) ($row['attempts'] ?? 0) + 1),
                'error'          => $error === null ? null : mb_substr(\App\Libraries\Mailer::oneLine($error), 0, 255),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Booking delivery log not written: ' . $e->getMessage());
        }
    }

    /** "n***@example.com", "+27 *** *** 123". */
    public static function mask(string $recipient): string
    {
        $recipient = trim($recipient);
        if (str_contains($recipient, '@')) {
            [$local, $domain] = explode('@', $recipient, 2);

            return mb_substr($local, 0, 1) . '***@' . $domain;
        }
        $digits = (string) preg_replace('/\D+/', '', $recipient);

        return strlen($digits) <= 3 ? '***' : '*** *** ' . substr($digits, -3);
    }

    /**
     * The latest lines for a business, newest first.
     *
     * @param array{channel?:string,outcome?:string} $filters
     *
     * @return list<array<string,mixed>>
     */
    public static function latest(int $listingId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $model = BookingDeliveryLogModel::forBusiness($listingId)
            ->select('xs_booking_delivery_logs.*, a.reference, a.service_name, a.start_at')
            ->join('xs_booking_appointments a', 'a.id = xs_booking_delivery_logs.appointment_id AND a.listing_id = xs_booking_delivery_logs.listing_id', 'left');
        if (in_array($filters['channel'] ?? '', \Config\BookingNotifications::CHANNELS, true)) {
            $model->where('xs_booking_delivery_logs.channel', $filters['channel']);
        }
        if (isset(self::LABELS[$filters['outcome'] ?? ''])) {
            $model->where('xs_booking_delivery_logs.outcome', $filters['outcome']);
        }

        return $model->orderBy('xs_booking_delivery_logs.id', 'DESC')->findAll($limit, $offset);
    }

    /** Delete lines older than the cut off, for every business. Returns how many. */
    public static function purge(string $beforeUtc): int
    {
        $db = db_connect();
        $db->table('xs_booking_delivery_logs')->where('created_at <', $beforeUtc)->delete();

        return $db->affectedRows();
    }
}
