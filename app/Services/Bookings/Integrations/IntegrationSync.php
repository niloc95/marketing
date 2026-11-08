<?php

namespace App\Services\Bookings\Integrations;

use App\Models\Bookings\BookingAppointmentModel as A;
use App\Models\Bookings\BookingCustomerModel;
use App\Services\Bookings\Notifications\BookingIntegrations;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Brings one appointment's online meeting and Google Calendar event in line
 * with the appointment as it is now. The only writer of an appointment's
 * video_link, video_meeting_id and google_event_id (written without touching
 * updated_at: they are not a change to the booking).
 *
 * - Jitsi: a link made from the appointment's random hash, set at once
 *   (no call to anyone). Ported from Bookings' JitsiIntegrationService,
 *   hashed once more so the room name does not give the hash away.
 * - Zoom (worker): an upcoming online Zoom appointment gets a meeting; a new
 *   time moves it; a cancellation deletes it and clears the link.
 * - Google (worker): an event for every appointment that is not cancelled;
 *   a cancellation deletes it.
 *
 * @phpstan-type Result array{ok:bool,error:?string,final:bool}
 */
class IntegrationSync
{
    public const JITSI_SERVER = 'https://meet.jit.si';

    private int $listingId;

    private DateTimeImmutable $now;

    public function __construct(private array $listing, ?DateTimeImmutable $now = null)
    {
        $this->listingId = (int) $listing['id'];
        $this->now       = $now ?? new DateTimeImmutable('now');
    }

    /**
     * Give an online Jitsi appointment its link, if it has none.
     *
     * @param array<string,mixed> $a
     *
     * @return array<string,mixed> the appointment as it is now
     */
    public function jitsi(array $a): array
    {
        if ($a['delivery_mode'] !== 'online_jitsi' || ($a['video_link'] ?? '') !== '') {
            return $a;
        }
        $server = rtrim((string) ((new BookingIntegrations($this->listingId))->account('jitsi')['config']['server_url'] ?? ''), '/') ?: self::JITSI_SERVER;
        $link   = $server . '/WebSchedulerLocal' . substr(hash('sha256', 'jitsi|' . $a['hash']), 0, 24);
        $this->write((int) $a['id'], ['video_link' => $link]);

        return ['video_link' => $link] + $a;
    }

    /** @return array{ok:bool,error:?string,final:bool} */
    public function zoom(int $appointmentId): array
    {
        $a = A::forBusiness($this->listingId)->find($appointmentId);
        if (! is_array($a)) {
            return ['ok' => true, 'error' => null, 'final' => true];
        }
        $meetingId = (string) ($a['video_meeting_id'] ?? '');
        $wanted    = $a['delivery_mode'] === 'online_zoom'
            && in_array($a['status'], [A::STATUS_PENDING, A::STATUS_CONFIRMED], true)
            && (string) $a['end_at'] > $this->nowUtc();
        $gone = $a['status'] === A::STATUS_CANCELLED || $a['delivery_mode'] !== 'online_zoom';
        if (! $wanted && ! ($gone && $meetingId !== '')) {
            return ['ok' => true, 'error' => null, 'final' => true];
        }

        $integrations = new BookingIntegrations($this->listingId);
        $account      = $integrations->account('zoom');
        if ($account === null || ! $integrations->active('zoom')) {
            return ['ok' => false, 'error' => 'Zoom is not connected.', 'final' => true];
        }
        $zoom = new Zoom($account['config']);

        if ($gone) {
            $r = $zoom->delete($meetingId);
            if ($r['ok']) {
                $this->write($appointmentId, ['video_meeting_id' => null, 'video_link' => null]);
            }
            $integrations->recordHealth('zoom', $r['ok'], $r['error']);

            return $r + ['final' => false];
        }

        $meeting = [
            'topic'    => $a['service_name'] . ' with ' . $this->listing['display_name'],
            'start'    => (new DateTimeImmutable((string) $a['start_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'duration' => (int) $a['service_duration_min'],
            'timezone' => (string) $a['stored_timezone'],
        ];
        if ($meetingId !== '') {
            $r = $zoom->update($meetingId, $meeting);
            if ($r['ok'] || ! $r['gone']) {
                $integrations->recordHealth('zoom', $r['ok'], $r['error']);

                return ['ok' => $r['ok'], 'error' => $r['error'], 'final' => false];
            }
            // Deleted in Zoom by hand: make a new one.
        }
        $r = $zoom->create($meeting);
        $integrations->recordHealth('zoom', $r['ok'], $r['error']);
        if ($r['ok']) {
            $this->write($appointmentId, ['video_meeting_id' => $r['id'], 'video_link' => $r['join_url']]);
        }

        return ['ok' => $r['ok'], 'error' => $r['error'], 'final' => false];
    }

    /** @return array{ok:bool,error:?string,final:bool} */
    public function google(int $appointmentId): array
    {
        $a = A::forBusiness($this->listingId)->find($appointmentId);
        if (! is_array($a)) {
            return ['ok' => true, 'error' => null, 'final' => true];
        }
        $eventId  = (string) ($a['google_event_id'] ?? '');
        $calendar = new GoogleCalendar($this->listing);
        $status   = $calendar->status();
        if (! $status['connected'] || ! $status['active']) {
            return ['ok' => false, 'error' => 'Google Calendar is not connected.', 'final' => true];
        }

        if ($a['status'] === A::STATUS_CANCELLED) {
            if ($eventId === '') {
                return ['ok' => true, 'error' => null, 'final' => true];
            }
            $r = $calendar->delete($eventId);
            if ($r['ok']) {
                $this->write($appointmentId, ['google_event_id' => null]);
            }

            return ['ok' => $r['ok'], 'error' => $r['error'], 'final' => $r['auth']];
        }

        $r = $calendar->save($eventId !== '' ? $eventId : null, $this->event($a));
        if ($r['ok'] && $r['id'] !== $eventId) {
            $this->write($appointmentId, ['google_event_id' => $r['id']]);
        }

        return ['ok' => $r['ok'], 'error' => $r['error'], 'final' => $r['auth']];
    }

    /**
     * The Google event for an appointment. What the business sees in its own
     * calendar; answers to its questions stay out (some are sensitive).
     *
     * @param array<string,mixed> $a
     *
     * @return array<string,mixed>
     */
    public function event(array $a): array
    {
        helper('site');
        $customer = null;
        if (! empty($a['customer_id'])) {
            $row      = BookingCustomerModel::forBusiness($this->listingId)->where('purged_at', null)->find((int) $a['customer_id']);
            $customer = is_array($row) ? $row : null;
        }
        $name  = $customer !== null ? trim($customer['first_name'] . ' ' . ($customer['last_name'] ?? '')) : '';
        $title = (string) $a['service_name'] . ($name !== '' ? ' with ' . $name : '');
        if ($a['status'] === A::STATUS_PENDING) {
            $title = 'Waiting for confirmation: ' . $title;
        }

        $lines = ['With ' . $a['provider_name']];
        if ($customer !== null) {
            $lines[] = 'Customer: ' . implode(', ', array_filter([$name, (string) ($customer['phone'] ?? ''), (string) ($customer['email'] ?? '')]));
        }
        if (($a['video_link'] ?? '') !== '') {
            $lines[] = 'Join online: ' . $a['video_link'];
        }
        $lines[] = 'Reference: ' . $a['reference'];
        $lines[] = 'Open in WebScheduler Local: ' . listing_site_url($this->listing, 'bookings/calendar?open=' . $a['hash']);

        $where = $a['delivery_mode'] === 'onsite'
            ? trim((string) ($a['location_name'] ?? '') . ', ' . (string) ($a['location_address'] ?? ''), ', ')
            : (string) ($a['video_link'] ?? '');
        $utc = new DateTimeZone('UTC');

        return [
            'summary'            => mb_substr($title, 0, 250),
            'description'        => implode("\n", $lines),
            'location'           => $where,
            'start'              => ['dateTime' => (new DateTimeImmutable((string) $a['start_at'], $utc))->format('Y-m-d\TH:i:s\Z'), 'timeZone' => (string) $a['stored_timezone']],
            'end'                => ['dateTime' => (new DateTimeImmutable((string) $a['end_at'], $utc))->format('Y-m-d\TH:i:s\Z'), 'timeZone' => (string) $a['stored_timezone']],
            'extendedProperties' => ['private' => ['webschedulerReference' => (string) $a['reference']]],
        ];
    }

    /** @param array<string,mixed> $data */
    private function write(int $appointmentId, array $data): void
    {
        db_connect()->table('xs_booking_appointments')->where('id', $appointmentId)->where('listing_id', $this->listingId)->update($data);
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
    }
}
