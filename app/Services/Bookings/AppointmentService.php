<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingAppointmentFieldModel;
use App\Models\Bookings\BookingAppointmentModel;
use App\Services\Bookings\Calendar\CalendarQuery;
use App\Services\Bookings\Notifications\NotificationQueue;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Appointments from the business side: book, reschedule, change status,
 * notes. The only writer of xs_booking_appointments besides BookingLock,
 * which does every insert and every change of time or person. Ported from
 * Bookings' Appointment\* services (mutation, form submission, status).
 *
 * - Every read and write is limited to the business and to the member's
 *   provider scope (BusinessContext::providerScope(), passed in): an
 *   appointment for someone outside it is "not found", never "forbidden",
 *   so ids cannot be probed.
 * - The business books with `public = false`: any free minute in working
 *   time, people marked not bookable included (Phase 3 rules).
 * - Times come in as the business's local date and time and are stored UTC.
 * - Reschedule keeps the length and gaps the appointment was booked with
 *   and only applies to upcoming appointments. Bringing back a cancelled
 *   one checks its time again.
 * - The service of an existing appointment does not change: cancel and book
 *   again (its snapshots, price and gaps belong to the service booked).
 * - Every change queues its messages (Notifications\NotificationQueue, Phase
 *   6) after it is written; nothing is sent inside the request.
 */
class AppointmentService
{
    /** What a reason code from the engine means, for the business (copy.md). */
    public const PROBLEMS = [
        AvailabilityService::PAST            => 'That time has passed.',
        AvailabilityService::TOO_SOON        => 'That is sooner than your minimum notice.',
        AvailabilityService::TOO_FAR         => 'That is further ahead than customers can book.',
        AvailabilityService::CLOSED          => 'The business is closed that day.',
        AvailabilityService::OUTSIDE_HOURS   => 'That is outside their working hours.',
        AvailabilityService::BUSY            => 'They already have something at that time.',
        AvailabilityService::NOT_OFFERED     => 'They do not offer that service.',
        AvailabilityService::NOT_ON_THE_GRID => 'Choose one of the times offered.',
        'not_found'                          => 'That appointment was not found.',
        'not_upcoming'                       => 'Only upcoming appointments can be moved.',
    ];

    private int $listingId;

    private DateTimeZone $tz;

    private DateTimeImmutable $now;

    private BookingAudit $audit;

    /**
     * @param array<string,mixed> $listing
     * @param list<int>|null      $scope   provider ids the member may work with, null for all
     */
    public function __construct(
        private array $listing,
        private ?array $scope,
        private ?int $memberId = null,
        private ?int $accountId = null,
        ?DateTimeImmutable $now = null,
        ?BookingAudit $audit = null,
    ) {
        $this->listingId = (int) $listing['id'];
        $tz              = (string) ($listing['timezone'] ?? '');
        $this->tz        = new DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Africa/Johannesburg');
        $this->now       = $now ?? new DateTimeImmutable('now');
        $this->audit     = $audit ?? new BookingAudit();
    }

    /** @return array<string,mixed>|null the row, when in scope */
    public function find(int $id): ?array
    {
        $row = BookingAppointmentModel::forBusiness($this->listingId)->find($id);

        return is_array($row) && $this->inScope((int) $row['provider_id']) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByHash(string $hash): ?array
    {
        if (! preg_match('/^[0-9a-f]{32}$/', $hash)) {
            return null;
        }
        $row = BookingAppointmentModel::forBusiness($this->listingId)->where('hash', $hash)->first();

        return is_array($row) && $this->inScope((int) $row['provider_id']) ? $row : null;
    }

    /**
     * Everything the details panel shows: the calendar shape plus the
     * customer, the answers and what the member may do.
     *
     * @return array<string,mixed>|null
     */
    public function detail(int $id): ?array
    {
        if ($this->find($id) === null) {
            return null;
        }
        $a   = 'xs_booking_appointments';
        $row = BookingAppointmentModel::forBusiness($this->listingId)
            ->select($a . '.*, c.first_name AS customer_first_name, c.last_name AS customer_last_name,'
                . ' c.email AS customer_email, c.phone AS customer_phone, c.hash AS customer_hash, p.color AS provider_color')
            ->join('xs_booking_customers c', 'c.id = ' . $a . '.customer_id AND c.listing_id = ' . $a . '.listing_id', 'left')
            ->join('xs_booking_providers p', 'p.id = ' . $a . '.provider_id AND p.listing_id = ' . $a . '.listing_id', 'left')
            ->find($id);
        $out = (new CalendarQuery($this->listingId, $this->tz))->format($row);

        $labels = array_column((new CustomerService($this->listingId))->customFields(), 'title', 'key');
        $out['fields'] = [];
        foreach ((new BookingAppointmentFieldModel())->where('appointment_id', $id)->orderBy('field_key')->findAll() as $f) {
            $out['fields'][] = ['key' => $f['field_key'], 'title' => $labels[$f['field_key']] ?? $f['field_key'], 'value' => $f['value']];
        }
        $out['status_label'] = AppointmentStatus::label($out['status']);
        $out['upcoming']     = $this->isUpcoming($row);
        $out['created_at']   = $row['created_at'];
        $out['messages']     = $this->messages($id);
        $out['whatsapp_url'] = $this->whatsappUrl($row);

        return $out;
    }

    /**
     * Book an appointment.
     *
     * @param array<string,mixed> $input provider_id, service_id, date (Y-m-d), time (H:i),
     *                                   customer_id or first_name, last_name, email, phone,
     *                                   notes, status (pending|confirmed), field_1 ... field_6
     *
     * @return array{id:?int,hash:?string,reference:?string,errors:array<string,string>}
     */
    public function create(array $input): array
    {
        $errors     = [];
        $providerId = (int) ($input['provider_id'] ?? 0);
        $serviceId  = (int) ($input['service_id'] ?? 0);
        if ($providerId <= 0 || ! $this->inScope($providerId)) {
            $errors['provider_id'] = 'Choose who the appointment is with.';
        }
        if ($serviceId <= 0) {
            $errors['service_id'] = 'Choose a service.';
        }
        $start = $this->localToUtc((string) ($input['date'] ?? ''), (string) ($input['time'] ?? ''));
        if ($start === null) {
            $errors['time'] = 'Choose a date and time.';
        }
        $status = AppointmentStatus::normalize((string) ($input['status'] ?? '')) ?? BookingAppointmentModel::STATUS_CONFIRMED;
        if (! in_array($status, AppointmentStatus::UPCOMING, true)) {
            $errors['status'] = 'A new appointment is waiting for confirmation or confirmed.';
        }

        // Any customer of this business may be booked; finding them is scoped (CustomerService::list()).
        $customers  = new CustomerService($this->listingId);
        $customerId = (int) ($input['customer_id'] ?? 0);
        if ($customerId > 0) {
            if ($customers->find($customerId) === null) {
                $errors['customer_id'] = 'That customer was not found.';
            }
        } else {
            // `notes` on this form are the appointment's, not the customer's.
            [, $customerErrors] = $customers->clean(['notes' => null] + $input);
            $errors += $customerErrors;
        }
        $errors += $customers->answerErrors($input);
        if ($errors !== []) {
            return ['id' => null, 'hash' => null, 'reference' => null, 'errors' => $errors];
        }

        if ($customerId <= 0) {
            $customer   = $customers->findOrCreate(['notes' => null] + $input, $this->accountId);
            $customerId = (int) $customer['id'];
        }

        $result = BookingLock::reserve($this->listing, [
            'provider_id'          => $providerId,
            'service_id'           => $serviceId,
            'customer_id'          => $customerId,
            'start_at'             => $start,
            'status'               => $status,
            'notes'                => $this->notes($input['notes'] ?? null),
            'created_by_member_id' => $this->memberId,
        ], false, $this->now);
        if ($result['problem'] !== null) {
            return ['id' => null, 'hash' => null, 'reference' => null, 'errors' => ['time' => self::PROBLEMS[$result['problem']] ?? 'That time cannot be booked.']];
        }

        $id = (int) $result['id'];
        $this->saveAnswers($id, $customerId, (new CustomerService($this->listingId))->answers($input));
        $this->audit->record($this->listingId, $this->accountId, 'appointment.created', 'appointment', $id, null, ['start_at' => $start, 'provider_id' => $providerId, 'service_id' => $serviceId]);
        $this->notify($id, NotificationQueue::statusEvent($status));
        $row = BookingAppointmentModel::forBusiness($this->listingId)->find($id);

        return ['id' => $id, 'hash' => (string) $row['hash'], 'reference' => $result['reference'], 'errors' => []];
    }

    /**
     * A new date and time (local), and optionally a new person.
     *
     * @return array{ok:bool,error:?string}
     */
    public function reschedule(int $id, string $date, string $time, ?int $providerId = null): array
    {
        $row = $this->find($id);
        if ($row === null) {
            return ['ok' => false, 'error' => self::PROBLEMS['not_found']];
        }
        if (! $this->isUpcoming($row)) {
            return ['ok' => false, 'error' => self::PROBLEMS['not_upcoming']];
        }
        if ($providerId !== null && ! $this->inScope($providerId)) {
            return ['ok' => false, 'error' => self::PROBLEMS[AvailabilityService::NOT_OFFERED]];
        }
        $start = $this->localToUtc($date, $time);
        if ($start === null) {
            return ['ok' => false, 'error' => 'Choose a date and time.'];
        }

        $moved = BookingLock::move($this->listing, $id, $start, $providerId, false, $this->now);
        if ($moved['problem'] !== null) {
            return ['ok' => false, 'error' => self::PROBLEMS[$moved['problem']] ?? 'That time cannot be booked.'];
        }
        if ($moved['changes'] !== []) {
            $this->audit->record($this->listingId, $this->accountId, 'appointment.rescheduled', 'appointment', $id,
                array_map(static fn (array $c) => $c[0], $moved['changes']),
                array_map(static fn (array $c) => $c[1], $moved['changes']));
            $this->notify($id, 'rescheduled');
        }

        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok:bool,error:?string} */
    public function setStatus(int $id, string $status): array
    {
        $row    = $this->find($id);
        $status = AppointmentStatus::normalize($status);
        if ($row === null) {
            return ['ok' => false, 'error' => self::PROBLEMS['not_found']];
        }
        if ($status === null) {
            return ['ok' => false, 'error' => 'Choose a status.'];
        }
        if ($status === $row['status']) {
            return ['ok' => true, 'error' => null];
        }

        // Back from cancelled: the time may have gone to someone else.
        if ($row['status'] === BookingAppointmentModel::STATUS_CANCELLED) {
            $check = (new AvailabilityService($this->listing, $this->now))->problem(
                (int) $row['provider_id'],
                (int) $row['service_id'],
                (string) $row['start_at'],
                false,
                $id,
            );
            if ($check === AvailabilityService::BUSY) {
                return ['ok' => false, 'error' => 'That time has been booked since. Move it to a new time first.'];
            }
        }

        BookingAppointmentModel::forBusiness($this->listingId)->update($id, [
            'status'       => $status,
            'cancelled_at' => $status === BookingAppointmentModel::STATUS_CANCELLED ? gmdate('Y-m-d H:i:s', $this->now->getTimestamp()) : null,
        ]);
        $this->audit->record($this->listingId, $this->accountId, 'appointment.status', 'appointment', $id, $row['status'], $status);
        $this->notify($id, NotificationQueue::statusEvent($status));

        return ['ok' => true, 'error' => null];
    }

    /** @return array{ok:bool,error:?string} */
    public function setNotes(int $id, ?string $notes): array
    {
        if ($this->find($id) === null) {
            return ['ok' => false, 'error' => self::PROBLEMS['not_found']];
        }
        BookingAppointmentModel::forBusiness($this->listingId)->update($id, ['notes' => $this->notes($notes)]);
        $this->audit->record($this->listingId, $this->accountId, 'appointment.notes', 'appointment', $id);

        return ['ok' => true, 'error' => null];
    }

    // The customer's side (Phase 5, PublicBookingService calls these)

    /** How long a customer's change or cancel link works after the appointment ends. */
    public const TOKEN_DAYS_AFTER = 30;

    /**
     * A fresh change or cancel link for the customer. Only its hash is
     * stored, so the raw token exists only in the link given out now; any
     * earlier link stops working (Bookings rotated it the same way).
     */
    public function issuePublicToken(int $id): ?string
    {
        $row = BookingAppointmentModel::forBusiness($this->listingId)->find($id);
        if (! is_array($row)) {
            return null;
        }
        $token = \App\Libraries\TokenHash::mint();
        BookingAppointmentModel::forBusiness($this->listingId)->update($id, [
            'public_token_hash'    => \App\Libraries\TokenHash::hash($token),
            'public_token_expires' => (new DateTimeImmutable((string) $row['end_at'], new DateTimeZone('UTC')))
                ->modify('+' . self::TOKEN_DAYS_AFTER . ' days')->format('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * The customer moves their booking to another time offered online
     * (`public = true`: the grid, notice and horizon apply), with the same
     * person. Policy windows are PublicBookingService's to check first.
     *
     * @return array{ok:bool,error:?string}
     */
    public function moveForCustomer(int $id, string $startUtc): array
    {
        $row = BookingAppointmentModel::forBusiness($this->listingId)->find($id);
        if (! is_array($row) || ! $this->isUpcoming($row)) {
            return ['ok' => false, 'error' => self::PROBLEMS['not_upcoming']];
        }
        $moved = BookingLock::move($this->listing, $id, $startUtc, null, true, $this->now);
        if ($moved['problem'] !== null) {
            return ['ok' => false, 'error' => self::CUSTOMER_PROBLEMS[$moved['problem']] ?? self::CUSTOMER_PROBLEMS[AvailabilityService::BUSY]];
        }
        $this->audit->record($this->listingId, null, 'appointment.rescheduled_by_customer', 'appointment', $id,
            array_map(static fn (array $c) => $c[0], $moved['changes']),
            array_map(static fn (array $c) => $c[1], $moved['changes']));
        if ($moved['changes'] !== []) {
            $this->notify($id, 'rescheduled', true);
        }

        return ['ok' => true, 'error' => null];
    }

    /** The customer cancels. Policy windows are PublicBookingService's to check first. */
    public function cancelForCustomer(int $id): bool
    {
        $row = BookingAppointmentModel::forBusiness($this->listingId)->find($id);
        if (! is_array($row) || ! in_array($row['status'], AppointmentStatus::UPCOMING, true)) {
            return false;
        }
        BookingAppointmentModel::forBusiness($this->listingId)->update($id, [
            'status'       => BookingAppointmentModel::STATUS_CANCELLED,
            'cancelled_at' => gmdate('Y-m-d H:i:s', $this->now->getTimestamp()),
        ]);
        $this->audit->record($this->listingId, null, 'appointment.cancelled_by_customer', 'appointment', $id, $row['status'], BookingAppointmentModel::STATUS_CANCELLED);
        $this->notify($id, 'cancelled', true);

        return true;
    }

    /** What a reason code means, for the customer (copy.md). */
    public const CUSTOMER_PROBLEMS = [
        AvailabilityService::PAST            => 'That time has passed. Choose another.',
        AvailabilityService::TOO_SOON        => 'That time is too soon to book online. Choose a later one.',
        AvailabilityService::TOO_FAR         => 'That date is too far ahead to book online.',
        AvailabilityService::CLOSED          => 'The business is closed that day.',
        AvailabilityService::OUTSIDE_HOURS   => 'That time is no longer offered. Choose another.',
        AvailabilityService::BUSY            => 'Someone has just booked that time. Choose another.',
        AvailabilityService::NOT_OFFERED     => 'That service cannot be booked online right now.',
        AvailabilityService::NOT_ON_THE_GRID => 'Choose one of the times offered.',
    ];

    /**
     * Find appointments by reference, customer name, email or phone
     * ("Find", Bookings' GlobalSearchService), newest first, in scope.
     *
     * @return list<array<string,mixed>> calendar shaped
     */
    public function findMatching(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || $this->scope === []) {
            return [];
        }
        $a     = 'xs_booking_appointments';
        $model = BookingAppointmentModel::forBusiness($this->listingId)
            ->select($a . '.*, c.first_name AS customer_first_name, c.last_name AS customer_last_name,'
                . ' c.email AS customer_email, c.phone AS customer_phone, c.hash AS customer_hash, p.color AS provider_color')
            ->join('xs_booking_customers c', 'c.id = ' . $a . '.customer_id AND c.listing_id = ' . $a . '.listing_id', 'left')
            ->join('xs_booking_providers p', 'p.id = ' . $a . '.provider_id AND p.listing_id = ' . $a . '.listing_id', 'left')
            ->groupStart()
            ->like($a . '.reference', $query)
            ->orLike('c.first_name', $query)
            ->orLike('c.last_name', $query)
            ->orLike('c.email', $query)
            ->orLike('c.phone', preg_replace('/\s+/', '', $query))
            ->orLike($a . '.service_name', $query)
            ->groupEnd();
        if ($this->scope !== null) {
            $model->whereIn($a . '.provider_id', $this->scope);
        }
        $query = new CalendarQuery($this->listingId, $this->tz);

        return array_map(static fn (array $r): array => $query->format($r), $model->orderBy($a . '.start_at', 'DESC')->findAll($limit));
    }

    // Internals

    /**
     * "Send again": queue one message about this appointment now, on a
     * channel the business has on. Sent within the minute by bookings:dispatch.
     *
     * @return array{ok:bool,error:?string}
     */
    public function resend(int $id, string $event, string $channel): array
    {
        if ($this->find($id) === null) {
            return ['ok' => false, 'error' => self::PROBLEMS['not_found']];
        }
        if (! (new NotificationQueue($this->listingId, $this->now))->resend($id, $event, $channel)) {
            return ['ok' => false, 'error' => 'That message cannot be sent. Check that the channel is set up in Settings, Messages.'];
        }
        $this->audit->record($this->listingId, $this->accountId, 'appointment.resend', 'appointment', $id, null, $event . '.' . $channel);

        return ['ok' => true, 'error' => null];
    }

    /**
     * The last messages about this appointment, for the details panel.
     *
     * @return list<array{event:string,channel:string,outcome:string,recipient_type:string,at:string}>
     */
    private function messages(int $id): array
    {
        $rows = \App\Models\Bookings\BookingDeliveryLogModel::forBusiness($this->listingId)
            ->where('appointment_id', $id)->whereIn('outcome', ['sent', 'failed'])->orderBy('id', 'DESC')->findAll(10);

        return array_map(fn (array $r): array => [
            'event'          => \Config\BookingNotifications::EVENTS[$r['event']] ?? $r['event'],
            'channel'        => \Config\BookingNotifications::CHANNEL_LABELS[$r['channel']] ?? $r['channel'],
            'outcome'        => \App\Services\Bookings\Notifications\DeliveryLog::LABELS[$r['outcome']] ?? $r['outcome'],
            'recipient_type' => (string) $r['recipient_type'],
            'at'             => (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->setTimezone($this->tz)->format('j M H:i'),
        ], $rows);
    }

    /** "Open a WhatsApp chat" (WhatsApp set to link mode), or ''. @param array<string,mixed> $row with customer_phone */
    private function whatsappUrl(array $row): string
    {
        if (! (new \App\Services\Bookings\Notifications\BookingIntegrations($this->listingId))->linkMode()) {
            return '';
        }
        $to = \App\Services\Bookings\Notifications\Channels::e164((string) ($row['customer_phone'] ?? ''), (string) ($this->listing['country_code'] ?? 'ZA'));
        if ($to === null) {
            return '';
        }
        $event    = NotificationQueue::statusEvent((string) $row['status']) ?? 'confirmed';
        $context  = \App\Services\Bookings\Notifications\MessageContext::build($this->listing, (int) $row['id'], $this->now);
        $template = (new \App\Services\Bookings\Notifications\NotificationTemplates($this->listingId))->get($event, 'whatsapp');
        if ($context === null || $template === null) {
            return '';
        }

        return \App\Services\Bookings\Notifications\Channels::whatsappLink($to, \App\Services\Bookings\Notifications\NotificationTemplates::fill($template, $context['values'])['body']);
    }

    /**
     * After every appointment change: the dashboard figures start again
     * (BookingMetricsCache), and the messages are queued (the queue never throws).
     */
    public function notify(int $id, ?string $event, bool $byCustomer = false): void
    {
        BookingMetricsCache::forget($this->listingId);
        if ($event !== null) {
            (new NotificationQueue($this->listingId, $this->now))->appointmentEvent($id, $event, $byCustomer);
        }
    }

    private function inScope(int $providerId): bool
    {
        return $this->scope === null || in_array($providerId, $this->scope, true);
    }

    /** @param array<string,mixed> $row */
    private function isUpcoming(array $row): bool
    {
        return in_array($row['status'], AppointmentStatus::UPCOMING, true)
            && (string) $row['end_at'] > gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
    }

    /** A local date and time as UTC, or null when either is not valid. */
    private function localToUtc(string $date, string $time): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }
        $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $this->tz);
        if ($local === false || $local->format('Y-m-d H:i') !== $date . ' ' . $time) {
            return null;
        }

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function notes(mixed $notes): ?string
    {
        $notes = trim(strip_tags((string) ($notes ?? '')));

        return $notes === '' ? null : mb_substr($notes, 0, 5000);
    }

    /**
     * The answers belong to this appointment; the customer keeps the latest
     * for next time.
     *
     * @param array<string,string> $answers
     */
    private function saveAnswers(int $appointmentId, int $customerId, array $answers): void
    {
        if ($answers === []) {
            return;
        }
        $rows = [];
        foreach ($answers as $key => $value) {
            $rows[] = ['appointment_id' => $appointmentId, 'field_key' => $key, 'value' => $value];
        }
        (new BookingAppointmentFieldModel())->insertBatch($rows);
        (new CustomerService($this->listingId))->rememberAnswers($customerId, $answers);
    }
}
