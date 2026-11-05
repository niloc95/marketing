<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingNotificationQueueModel;
use App\Models\Bookings\BookingProviderModel;
use App\Models\Bookings\BookingStaffAssignmentModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Services\Bookings\AppointmentStatus;
use App\Services\Bookings\BookingSettings;
use Config\BookingNotifications as Catalog;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Puts messages on the queue; never sends (NotificationDispatcher does, from
 * cron, so a slow or broken mail server cannot slow down or fail a booking).
 * Ported from Bookings' NotificationQueueService and AppointmentEventService.
 * The only writer of new xs_booking_notification_queue rows.
 *
 * - `appointmentEvent()` is called by the writers of appointments
 *   (AppointmentService, PublicBookingService) after each change. Customer
 *   rows go out for each channel whose rule is on; team rows (email, plus the
 *   notification centre) only when the customer did it online, since the team
 *   already knows what the team did.
 * - Idempotency: one row per business and key. A move's key carries the
 *   schedule fingerprint, so two moves are two messages and a double click is
 *   one; a reminder's key carries its offset and the fingerprint, so moving an
 *   appointment makes a fresh reminder possible. A cancelled or failed row
 *   with the same key is replaced (Bookings' rule).
 * - Reminders (`dueReminders()`, each minute from bookings:dispatch): each
 *   offset on its own; none fires when its moment was before the booking was
 *   made (it would only echo the confirmation, Bookings' 4 July 2026 fix), and
 *   one missed while cron was down still goes while the appointment is ahead.
 * - Only enqueued while the business takes bookings; the dispatcher checks
 *   again when it sends.
 */
class NotificationQueue
{
    private Catalog $catalog;

    public function __construct(private int $listingId, private ?DateTimeImmutable $now = null)
    {
        $this->catalog = config('BookingNotifications');
    }

    /**
     * The status an appointment has now, as an event (Bookings'
     * AppointmentStatus::notificationEvent()); completed sends nothing.
     */
    public static function statusEvent(string $status): ?string
    {
        return match ($status) {
            BookingAppointmentModel::STATUS_PENDING   => 'pending',
            BookingAppointmentModel::STATUS_CONFIRMED => 'confirmed',
            BookingAppointmentModel::STATUS_CANCELLED => 'cancelled',
            BookingAppointmentModel::STATUS_NO_SHOW   => 'no_show',
            default                                   => null,
        };
    }

    /**
     * Queue what an appointment change should send.
     *
     * @return int rows added
     */
    public function appointmentEvent(int $appointmentId, string $event, bool $byCustomer = false): int
    {
        if (! isset(Catalog::EVENTS[$event]) || $event === 'reminder') {
            return 0;
        }
        $appointment = BookingAppointmentModel::forBusiness($this->listingId)->find($appointmentId);
        if (! is_array($appointment)) {
            return 0;
        }

        try {
            $rules        = new NotificationRules($this->listingId);
            $integrations = new BookingIntegrations($this->listingId);
            $marker       = $this->marker($event, $appointment);
            $added        = 0;
            foreach (Catalog::CHANNELS as $channel) {
                if ($rules->enabled($event, $channel) && ($channel === 'email' || $integrations->active($channel))) {
                    $added += (int) $this->add($appointment, $event, $channel, null, $marker);
                }
            }
            if ($byCustomer && in_array($event, Catalog::TEAM_EVENTS, true)) {
                $emailTeam = $rules->enabled($event, 'email', Catalog::TEAM);
                foreach ($this->teamFor((int) $appointment['provider_id']) as $memberId) {
                    $this->toCentre($appointment, $event, $memberId);
                    if ($emailTeam) {
                        $added += (int) $this->add($appointment, $event, 'email', $memberId, $marker);
                    }
                }
            }

            return $added;
        } catch (\Throwable $e) {
            // A message must never undo or fail the booking that caused it.
            log_message('error', 'Booking notification not queued for appointment ' . $appointmentId . ': ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Queue a message again on request (the business's "Send again"), now.
     * Same rules as an event: the channel must be on for the business.
     */
    public function resend(int $appointmentId, string $event, string $channel): bool
    {
        $appointment = BookingAppointmentModel::forBusiness($this->listingId)->find($appointmentId);
        if (! is_array($appointment) || ! isset(Catalog::EVENTS[$event]) || ! in_array($channel, Catalog::CHANNELS, true)) {
            return false;
        }
        if ($channel !== 'email' && ! (new BookingIntegrations($this->listingId))->active($channel)) {
            return false;
        }

        return $this->add($appointment, $event, $channel, null, 'manual:' . bin2hex(random_bytes(8)));
    }

    /**
     * Queue the reminders that are due now for this business.
     *
     * @return array{scanned:int,queued:int}
     */
    public function dueReminders(): array
    {
        $stats    = ['scanned' => 0, 'queued' => 0];
        $settings = BookingSettings::for($this->listingId);
        $offsets  = array_values(array_unique(array_filter([
            $settings->int('notifications.reminder_primary'),
            $settings->int('notifications.reminder_secondary'),
        ])));
        $rules        = new NotificationRules($this->listingId);
        $integrations = new BookingIntegrations($this->listingId);
        $channels     = array_values(array_filter(Catalog::CHANNELS, static fn (string $c): bool => $rules->enabled('reminder', $c)
            && ($c === 'email' || $integrations->active($c))));
        if ($offsets === [] || $channels === []) {
            return $stats;
        }

        // Appointments still to come whose earliest reminder is due: the
        // largest offset decides how far ahead to look. One that was due
        // while cron was down is still sent, as long as it starts later.
        $now  = $this->now();
        $to   = $now->modify('+' . (max($offsets) + 1) . ' minutes');
        $rows = BookingAppointmentModel::forBusiness($this->listingId)
            ->whereIn('status', AppointmentStatus::UPCOMING)
            ->where('start_at >', $now->format('Y-m-d H:i:s'))
            ->where('start_at <=', $to->format('Y-m-d H:i:s'))
            ->findAll(2000);

        foreach ($rows as $appointment) {
            $stats['scanned']++;
            $start   = new DateTimeImmutable((string) $appointment['start_at'], new DateTimeZone('UTC'));
            $created = new DateTimeImmutable((string) ($appointment['created_at'] ?? $appointment['start_at']), new DateTimeZone('UTC'));
            foreach ($offsets as $offset) {
                $due = $start->modify('-' . $offset . ' minutes');
                if ($now < $due || $due < $created) {
                    continue;
                }
                foreach ($channels as $channel) {
                    $stats['queued'] += (int) $this->add($appointment, 'reminder', $channel, null, 'offset:' . $offset . ':sch:' . self::fingerprint($appointment), $offset);
                }
            }
        }

        return $stats;
    }

    /** What makes a reminder still right: the time and the person. */
    public static function fingerprint(array $appointment): string
    {
        return sha1($appointment['start_at'] . '|' . $appointment['end_at'] . '|' . $appointment['provider_id']);
    }

    /**
     * Active team members with notices on who work with this person: admins,
     * the person's own account, and reception assigned to them.
     *
     * @return list<int> member ids
     */
    public function teamFor(int $providerId): array
    {
        $members = BusinessMemberModel::forBusiness($this->listingId)
            ->where('status', BusinessMemberModel::STATUS_ACTIVE)->where('notify_on_appointments', 1)->findAll();
        if ($members === []) {
            return [];
        }
        $provider = BookingProviderModel::forBusiness($this->listingId)->find($providerId);
        $assigned = array_map('intval', array_column(
            BookingStaffAssignmentModel::forBusiness($this->listingId)->where('provider_id', $providerId)->findAll(),
            'member_id',
        ));
        $out = [];
        foreach ($members as $m) {
            $id = (int) $m['id'];
            if ($m['role'] === \Config\BookingPermissions::ADMIN
                || (is_array($provider) && (int) ($provider['member_id'] ?? 0) === $id)
                || in_array($id, $assigned, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function marker(string $event, array $appointment): ?string
    {
        return match ($event) {
            // A move is its own message each time; a second status change back
            // and forth (confirmed, cancelled, confirmed) is too.
            'rescheduled' => 'sch:' . self::fingerprint($appointment),
            default       => 'at:' . substr(sha1((string) ($appointment['updated_at'] ?? '') . '|' . $appointment['status']), 0, 16),
        };
    }

    private function toCentre(array $appointment, string $event, int $memberId): void
    {
        $template = $this->catalog->teamTemplates[$event] ?? null;
        if ($template === null) {
            return;
        }
        $when  = (new DateTimeImmutable((string) $appointment['start_at'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) ($appointment['stored_timezone'] ?: 'Africa/Johannesburg')));
        $name  = 'A customer';
        if (! empty($appointment['customer_id'])) {
            $c = \App\Models\Bookings\BookingCustomerModel::forBusiness($this->listingId)->find((int) $appointment['customer_id']);
            if (is_array($c)) {
                $name = trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''));
            }
        }
        NotificationCentre::add(
            $this->listingId,
            $memberId,
            (int) $appointment['id'],
            $event,
            strtr($template['inbox'], ['{customer_name}' => $name]),
            $appointment['service_name'] . ' with ' . $appointment['provider_name'] . ', ' . $when->format('D j M, H:i'),
        );
    }

    private function add(array $appointment, string $event, string $channel, ?int $memberId, ?string $marker, ?int $offset = null): bool
    {
        $key = implode(':', array_filter([
            $memberId === null ? 'c' : 'm' . $memberId, $channel, $event, 'a' . $appointment['id'], $marker,
        ], static fn ($p) => $p !== null && $p !== ''));
        if (strlen($key) > 128) {
            $key = substr($key, 0, 80) . ':' . sha1($key);
        }

        $model    = BookingNotificationQueueModel::forBusiness($this->listingId);
        $existing = $model->where('idempotency_key', $key)->first();
        if (is_array($existing)) {
            if (in_array($existing['status'], ['queued', 'sent'], true)) {
                return false;
            }
            BookingNotificationQueueModel::forBusiness($this->listingId)->delete((int) $existing['id']);
        }

        try {
            BookingNotificationQueueModel::forBusiness($this->listingId)->insert([
                'appointment_id'       => (int) $appointment['id'],
                'event'                => $event,
                'channel'              => $channel,
                'recipient_type'       => $memberId === null ? Catalog::CUSTOMER : Catalog::TEAM,
                'member_id'            => $memberId,
                'status'               => 'queued',
                'attempts'             => 0,
                'max_attempts'         => $this->catalog->maxAttempts,
                'run_after'            => $this->now()->format('Y-m-d H:i:s'),
                'idempotency_key'      => $key,
                'correlation_id'       => bin2hex(random_bytes(16)),
                'reminder_offset_min'  => $offset,
                'schedule_fingerprint' => in_array($event, ['reminder', 'rescheduled'], true) ? self::fingerprint($appointment) : null,
            ]);
        } catch (\Throwable $e) {
            // The unique key: another request queued the same message first.
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}
