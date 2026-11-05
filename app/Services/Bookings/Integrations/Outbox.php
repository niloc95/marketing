<?php

namespace App\Services\Bookings\Integrations;

use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingOutboxModel as O;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\Api\Resources;
use App\Services\Bookings\Notifications\BookingIntegrations;
use DateTimeImmutable;

/**
 * What an appointment change means for the outside world, queued for
 * bookings:dispatch (IntegrationWorker). The only writer of
 * xs_booking_outbox. Nothing here calls out: a slow Google or a webhook
 * endpoint that hangs can never hold up a booking.
 *
 * - Webhooks: one row per event, the body frozen as it was then, so the
 *   endpoint hears every step in order even if the appointment moves on.
 * - Google Calendar and Zoom: at most one queued row per appointment. The
 *   worker reads the appointment as it is when it runs, so five quick
 *   changes are one call.
 * - Jitsi needs no call: its link is set here at once (IntegrationSync).
 *
 * AppointmentService::notify() calls appointmentChanged() after every change;
 * DepositService calls it when it frees an unpaid time, and depositPaid().
 */
class Outbox
{
    private int $listingId;

    private DateTimeImmutable $now;

    private BookingIntegrations $integrations;

    public function __construct(private array $listing, ?DateTimeImmutable $now = null)
    {
        $this->listingId    = (int) $listing['id'];
        $this->now          = $now ?? new DateTimeImmutable('now');
        $this->integrations = new BookingIntegrations($this->listingId);
    }

    public static function forListing(int $listingId, ?DateTimeImmutable $now = null): ?self
    {
        $listing = (new DirectoryListingModel())->find($listingId);

        return is_array($listing) ? new self($listing, $now) : null;
    }

    /**
     * @param string $change created, rescheduled or status
     */
    public function appointmentChanged(int $appointmentId, string $change): void
    {
        $a = BookingAppointmentModel::forBusiness($this->listingId)->find($appointmentId);
        if (! is_array($a)) {
            return;
        }
        $a = (new IntegrationSync($this->listing, $this->now))->jitsi($a);

        $event = match ($change) {
            'created'     => 'appointment.created',
            'rescheduled' => 'appointment.rescheduled',
            default       => 'appointment.' . $a['status'],
        };
        $this->webhook($event, $a, ['appointment' => Resources::appointment($a)]);

        if ($this->integrations->active('google') || ! empty($a['google_event_id'])) {
            $this->sync(O::GOOGLE, $appointmentId);
        }
        if (($a['delivery_mode'] === 'online_zoom' && $this->integrations->active('zoom')) || ! empty($a['video_meeting_id'])) {
            $this->sync(O::ZOOM, $appointmentId);
        }
    }

    /**
     * A deposit came in (paid online or marked paid).
     *
     * @param array<string,mixed> $payment
     */
    public function depositPaid(int $appointmentId, array $payment): void
    {
        $a = BookingAppointmentModel::forBusiness($this->listingId)->find($appointmentId);
        if (! is_array($a)) {
            return;
        }
        $this->webhook('deposit.paid', $a, [
            'appointment' => Resources::appointment($a),
            'payment'     => [
                'reference'    => (string) $payment['reference'],
                'gateway'      => (string) $payment['gateway'],
                'amount_cents' => (int) $payment['amount_cents'],
                'currency'     => (string) $payment['currency'],
                'test'         => (bool) $payment['test'],
            ],
        ]);
    }

    /** Every upcoming appointment, after Google Calendar was connected. */
    public function backfillGoogle(int $limit = 500): int
    {
        $rows = BookingAppointmentModel::forBusiness($this->listingId)
            ->select('id')
            ->whereIn('status', [BookingAppointmentModel::STATUS_PENDING, BookingAppointmentModel::STATUS_CONFIRMED])
            ->where('start_at >', gmdate('Y-m-d H:i:s', $this->now->getTimestamp()))
            ->orderBy('start_at')->findAll($limit);
        foreach ($rows as $row) {
            $this->sync(O::GOOGLE, (int) $row['id']);
        }

        return count($rows);
    }

    /** Stop what is still queued for a target (it was disconnected). */
    public function dropQueued(string $target): void
    {
        O::forBusiness($this->listingId)->where('target', $target)->where('status', O::QUEUED)
            ->set(['status' => O::CANCELLED, 'last_error' => 'Disconnected.', 'done_at' => $this->nowUtc()])->update();
    }

    /** A failed webhook, sent again with its original body. */
    public function retry(int $outboxId): bool
    {
        $row = O::forBusiness($this->listingId)->find($outboxId);
        if (! is_array($row) || $row['status'] !== O::FAILED) {
            return false;
        }
        O::forBusiness($this->listingId)->update($outboxId, ['status' => O::QUEUED, 'attempts' => 0, 'next_attempt_at' => $this->nowUtc(), 'done_at' => null]);

        return true;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $data
     */
    private function webhook(string $event, array $a, array $data): void
    {
        $hook = (new Webhooks($this->listingId))->settings();
        if (! $hook['active'] || ! in_array($event, $hook['events'], true)) {
            return;
        }
        O::forBusiness($this->listingId)->insert([
            'target'          => O::WEBHOOK,
            'appointment_id'  => (int) $a['id'],
            'event'           => $event,
            'payload'         => json_encode([
                'id'       => 'evt_' . bin2hex(random_bytes(12)),
                'type'     => $event,
                'created'  => gmdate('Y-m-d\TH:i:s\Z', $this->now->getTimestamp()),
                'business' => ['id' => $this->listingId, 'name' => (string) $this->listing['display_name']],
                'data'     => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status'          => O::QUEUED,
            'next_attempt_at' => $this->nowUtc(),
        ]);
    }

    private function sync(string $target, int $appointmentId): void
    {
        $queued = O::forBusiness($this->listingId)->where('target', $target)->where('appointment_id', $appointmentId)
            ->where('status', O::QUEUED)->countAllResults();
        if ($queued > 0) {
            return;
        }
        O::forBusiness($this->listingId)->insert([
            'target'          => $target,
            'appointment_id'  => $appointmentId,
            'event'           => 'sync',
            'status'          => O::QUEUED,
            'next_attempt_at' => $this->nowUtc(),
        ]);
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
    }
}
