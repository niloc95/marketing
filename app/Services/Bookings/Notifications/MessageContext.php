<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\BookingAppointmentFieldModel;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingCustomerModel;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\CustomerService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything one message about one appointment needs, read when the message
 * is sent (not when it was queued), so a reminder carries the time as it is
 * now. Ported from the context building in Bookings' NotificationQueueDispatcher
 * and NotificationTemplateService::buildPlaceholders().
 *
 * - Times are shown in the zone the appointment belongs to
 *   (`stored_timezone`), then the profile's, never the server's.
 * - The customer's link is their own page for this booking
 *   (book/customer/{customer}/{appointment}): it does not change, unlike the
 *   change or cancel token, which is rotated on every change and stored
 *   hashed, so a queued message could not carry it.
 * - Answers marked sensitive never reach a message (`answers`), and the
 *   address only when the profile shows it.
 */
class MessageContext
{
    /**
     * @param array<string,mixed> $listing
     *
     * @return array{appointment:array<string,mixed>,customer:?array<string,mixed>,values:array<string,string>,details:list<array{0:string,1:string}>,answers:list<array{0:string,1:string}>,manage_url:string,booking_url:string,calendar_url:string,from_name:string,reply_to:string,timezone:string}|null
     */
    public static function build(array $listing, int $appointmentId, ?DateTimeImmutable $now = null): ?array
    {
        helper(['site', 'directory_ui']);
        $listingId   = (int) $listing['id'];
        $appointment = BookingAppointmentModel::forBusiness($listingId)->find($appointmentId);
        if (! is_array($appointment)) {
            return null;
        }
        $customer = null;
        if (! empty($appointment['customer_id'])) {
            $row      = BookingCustomerModel::forBusiness($listingId)->where('purged_at', null)->find((int) $appointment['customer_id']);
            $customer = is_array($row) ? $row : null;
        }

        $settings = BookingSettings::for($listingId);
        $tz       = self::zone((string) ($appointment['stored_timezone'] ?? ''), (string) ($listing['timezone'] ?? ''));
        $start    = (new DateTimeImmutable((string) $appointment['start_at'], new DateTimeZone('UTC')))->setTimezone($tz);
        $today    = ($now ?? new DateTimeImmutable())->setTimezone($tz);
        $time     = $settings->get('localization.time_format') === '12h' ? $start->format('g:i a') : $start->format('H:i');
        $date     = $start->format('l j F Y');
        $when     = match ($start->format('Y-m-d')) {
            $today->format('Y-m-d')                     => 'today at ' . $time,
            $today->modify('+1 day')->format('Y-m-d')   => 'tomorrow at ' . $time,
            default                                     => 'on ' . $start->format($start->format('Y') === $today->format('Y') ? 'l j F' : 'l j F Y') . ' at ' . $time,
        };

        $location = '';
        $video    = (string) ($appointment['video_link'] ?? '');
        if ($appointment['delivery_mode'] !== 'onsite') {
            $location = 'Online';
        } elseif (trim((string) ($appointment['location_address'] ?? '')) !== '') {
            $location = trim((string) ($appointment['location_name'] ?? '') . ', ' . $appointment['location_address'], ', ');
        } elseif (listing_address_public($listing)) {
            $location = implode(', ', array_filter(array_map('trim', [
                (string) ($listing['address_line'] ?? ''), (string) ($listing['suburb'] ?? ''), (string) ($listing['city'] ?? ''),
            ])));
        }

        $answers = [];
        $fields  = (new CustomerService($listingId))->customFields();
        $byKey   = array_column($fields, null, 'key');
        foreach ((new BookingAppointmentFieldModel())->where('appointment_id', $appointmentId)->orderBy('field_key')->findAll() as $f) {
            $field = $byKey[$f['field_key']] ?? null;
            if ($field !== null && ! $field['sensitive'] && trim((string) $f['value']) !== '') {
                $answers[] = [$field['title'], (string) $f['value']];
            }
        }

        $manageUrl  = $customer !== null ? listing_site_url($listing, 'book/customer/' . $customer['hash'] . '/' . $appointment['hash']) : '';
        $bookingUrl = listing_site_url($listing, 'directory/' . $listing['slug'] . '/book');
        $business   = (string) $listing['display_name'];
        $minutes    = (int) $appointment['service_duration_min'];
        $duration   = $minutes % 60 === 0 && $minutes >= 60
            ? ($minutes / 60) . ($minutes === 60 ? ' hour' : ' hours')
            : $minutes . ' minutes';
        $first      = $customer !== null ? (string) $customer['first_name'] : 'there';
        $full       = $customer !== null ? trim($customer['first_name'] . ' ' . ($customer['last_name'] ?? '')) : 'A customer';

        $values = [
            '{customer_first_name}' => $first,
            '{customer_name}'       => $full,
            '{service}'             => (string) $appointment['service_name'],
            '{duration}'            => $duration,
            '{team_member}'         => (string) $appointment['provider_name'],
            '{business}'            => $business,
            '{date}'                => $date,
            '{time}'                => $time,
            '{when}'                => $when,
            '{location}'            => $location,
            '{business_phone}'      => (string) ($listing['phone'] ?? ''),
            '{reference}'           => (string) $appointment['reference'],
            '{manage_link}'         => $manageUrl,
            '{booking_link}'        => $bookingUrl,
            '{cancellation_policy}' => $settings->get('legal.cancellation_policy'),
            '{rescheduling_policy}' => $settings->get('legal.rescheduling_policy'),
            '{answers}'             => implode("\n", array_map(static fn (array $a): string => $a[0] . ': ' . $a[1], $answers)),
            '{video_link}'          => $video,
        ];

        $details = [['When', $date . ' at ' . $time], ['Service', $values['{service}'] . ' (' . $duration . ')'], ['With', $values['{team_member}']]];
        if ($location !== '') {
            $details[] = ['Where', $location];
        }
        if ($video !== '') {
            $details[] = ['Join online', $video];
        }
        $details[] = ['Reference', $values['{reference}']];

        $fromName = trim($settings->get('notifications.from_name'));
        $replyTo  = trim($settings->get('notifications.reply_to'));

        return [
            'appointment'  => $appointment,
            'customer'     => $customer,
            'values'       => $values,
            'details'      => $details,
            'answers'      => $answers,
            'manage_url'   => $manageUrl,
            'booking_url'  => $bookingUrl,
            'calendar_url' => listing_site_url($listing, 'bookings/calendar?date=' . $start->format('Y-m-d')),
            'from_name'    => ($fromName !== '' ? $fromName : $business) . ' via WebScheduler Local',
            'reply_to'     => $replyTo !== '' ? $replyTo : (string) ($listing['email'] ?? ''),
            'timezone'     => $tz->getName(),
        ];
    }

    private static function zone(string ...$names): DateTimeZone
    {
        foreach ($names as $name) {
            if ($name !== '' && in_array($name, timezone_identifiers_list(), true)) {
                return new DateTimeZone($name);
            }
        }

        return new DateTimeZone('Africa/Johannesburg');
    }
}
