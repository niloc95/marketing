<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\BookingInboxModel;
use DateTimeImmutable;

/**
 * The team's notification centre (/bookings/notifications): new bookings,
 * moves and cancellations made by customers online. Ported from Bookings'
 * NotificationCenterService, which built the list from the queue; here each
 * team member has their own rows and read flag. The only writer of
 * xs_booking_inbox.
 */
class NotificationCentre
{
    public function __construct(private int $listingId, private int $memberId)
    {
    }

    public static function add(int $listingId, int $memberId, ?int $appointmentId, string $event, string $title, ?string $body = null): void
    {
        BookingInboxModel::forBusiness($listingId)->insert([
            'member_id'      => $memberId,
            'appointment_id' => $appointmentId,
            'event'          => $event,
            'title'          => mb_substr($title, 0, 190),
            'body'           => $body === null ? null : mb_substr($body, 0, 255),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $limit = 50): array
    {
        return BookingInboxModel::forBusiness($this->listingId)
            ->select('xs_booking_inbox.*, a.hash AS appointment_hash, a.start_at')
            ->join('xs_booking_appointments a', 'a.id = xs_booking_inbox.appointment_id AND a.listing_id = xs_booking_inbox.listing_id', 'left')
            ->where('xs_booking_inbox.member_id', $this->memberId)
            ->orderBy('xs_booking_inbox.id', 'DESC')->findAll($limit);
    }

    public function unread(): int
    {
        return BookingInboxModel::forBusiness($this->listingId)->where('member_id', $this->memberId)->where('read_at', null)->countAllResults();
    }

    /** Mark one as read, or all of this member's when $id is null. */
    public function markRead(?int $id = null, ?DateTimeImmutable $now = null): void
    {
        $model = BookingInboxModel::forBusiness($this->listingId)->where('member_id', $this->memberId)->where('read_at', null);
        if ($id !== null) {
            $model->where('id', $id);
        }
        $model->set('read_at', gmdate('Y-m-d H:i:s', ($now ?? new DateTimeImmutable())->getTimestamp()))->update();
    }

    /** Delete read rows older than the cut off, for every business. */
    public static function purge(string $beforeUtc): int
    {
        $db = db_connect();
        $db->table('xs_booking_inbox')->where('read_at IS NOT NULL', null, false)->where('created_at <', $beforeUtc)->delete();

        return $db->affectedRows();
    }
}
