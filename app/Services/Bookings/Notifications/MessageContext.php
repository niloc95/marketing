<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\AccountModel;
use App\Models\Bookings\BookingAppointmentFieldModel;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingCustomerModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BusinessLocale;
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
 *   address only when the profile shows it (`place`: the branch's or the
 *   profile's, with Google Maps and Waze links and its opening hours).
 * - The team's links open the appointment and the customer in /bookings
 *   (`view_url`, `customer_page_url`); the customer's open their own page
 *   and Google Calendar (`calendar_link`, as the reference app: no ICS).
 */
class MessageContext
{
    /**
     * @param array<string,mixed> $listing
     *
     * @return array{appointment:array<string,mixed>,customer:?array<string,mixed>,values:array<string,string>,details:list<array{0:string,1:string}>,answers:list<array{0:string,1:string}>,manage_url:string,booking_url:string,calendar_url:string,view_url:string,customer_page_url:string,calendar_link:string,place:?array{name:string,address:string,maps_url:string,waze_url:string},hours:list<array{0:string,1:string}>,customer_contact:array{name:string,email:string,phone:string},booked:string,terms_url:string,privacy_url:string,enquiries:array{email:string,phone:string},from_name:string,reply_to:string,timezone:string,timezone_note:string}|null
     */
    public static function build(array $listing, int $appointmentId, ?DateTimeImmutable $now = null): ?array
    {
        helper(['site', 'directory_ui', 'map', 'directory_hours']);
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

        // The place: a branch row, or the profile. The snapshot always holds
        // the address (BookingLock), so publicity is checked here: a travel
        // business that hides its address keeps it out of every message.
        $place = null;
        $hours = [];
        $video = (string) ($appointment['video_link'] ?? '');
        if ($appointment['delivery_mode'] === 'onsite') {
            $row = ! empty($appointment['location_id'])
                ? (new DirectoryPracticeLocationModel())->where('listing_id', $listingId)->find((int) $appointment['location_id'])
                : null;
            $public = is_array($row) || listing_address_public($listing);
            $row    = is_array($row) ? $row : $listing;
            if ($public) {
                $address = trim((string) ($appointment['location_address'] ?? '')) ?: map_address_text($row);
                $place   = [
                    'name'     => trim((string) ($appointment['location_name'] ?? '')) ?: (string) $listing['display_name'],
                    'address'  => $address,
                    'maps_url' => $address !== '' ? map_directions_url($row) : '',
                    'waze_url' => $address !== '' ? map_waze_url($row) : '',
                ];
                foreach (hours_decode($row['trading_hours'] ?? null) ?? [] as $key => $day) {
                    $hours[] = [hours_days()[$key], $day['closed'] ? 'Closed' : ($day['open'] !== '' ? $day['open'] . ' to ' . $day['close'] : 'No hours given')];
                }
            }
        }
        $location = $appointment['delivery_mode'] !== 'onsite' ? 'Online'
            : ($place === null ? '' : trim($place['name'] . ', ' . $place['address'], ', '));

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
        $replyTo  = $replyTo !== '' ? $replyTo : (string) ($listing['email'] ?? '');

        // Who made it: online by the customer, by a named team member, or the API.
        $booked = match ((string) ($appointment['source'] ?? 'business')) {
            'public' => 'online by the customer',
            'api'    => 'through the API',
            default  => 'by ' . (self::memberName($listingId, (int) ($appointment['created_by_member_id'] ?? 0)) ?? 'the team'),
        };
        $created = (new DateTimeImmutable((string) $appointment['created_at'], new DateTimeZone('UTC')))->setTimezone($tz);
        $booked .= ', ' . $created->format('j M Y') . ' at ' . ($settings->get('localization.time_format') === '12h' ? $created->format('g:i a') : $created->format('H:i'));

        // Google Calendar, as the reference app did it (no ICS: Mailer has no attachments).
        $end          = $start->modify('+' . max($minutes, 5) . ' minutes');
        $calendarLink = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
            . '&text=' . rawurlencode($values['{service}'] . ' with ' . $business)
            . '&dates=' . $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . '/' . $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
            . '&details=' . rawurlencode('Reference ' . $values['{reference}'] . ($manageUrl !== '' ? "\n" . $manageUrl : ''))
            . ($location !== '' ? '&location=' . rawurlencode($location) : '');

        $termsUrl   = safe_external_url($settings->get('legal.terms_url'));
        $privacyUrl = safe_external_url($settings->get('legal.privacy_url'));
        if ($termsUrl === '' && trim($settings->get('legal.terms')) !== '') {
            $termsUrl = listing_site_url($listing, 'directory/' . $listing['slug'] . '/book/terms');
        }

        return [
            'appointment'  => $appointment,
            'customer'     => $customer,
            'values'       => $values,
            'details'      => $details,
            'answers'      => $answers,
            'manage_url'   => $manageUrl,
            'booking_url'  => $bookingUrl,
            'calendar_url' => listing_site_url($listing, 'business/calendar?date=' . $start->format('Y-m-d')),
            'view_url'     => listing_site_url($listing, 'business/calendar?view=day&date=' . $start->format('Y-m-d') . '&open=' . $appointment['hash']),
            'customer_page_url' => $customer !== null ? listing_site_url($listing, 'business/customers/' . $customer['hash']) : '',
            'calendar_link' => $calendarLink,
            'place'        => $place,
            'hours'        => $hours,
            'customer_contact' => ['name' => $full, 'email' => (string) ($customer['email'] ?? ''), 'phone' => (string) ($customer['phone'] ?? '')],
            'booked'       => $booked,
            'terms_url'    => $termsUrl,
            'privacy_url'  => $privacyUrl !== '' ? $privacyUrl : listing_site_url($listing, 'privacy'),
            'enquiries'    => ['email' => $replyTo, 'phone' => $values['{business_phone}']],
            'from_name'    => ($fromName !== '' ? $fromName : $business) . ' via WebScheduler Local',
            'reply_to'     => $replyTo,
            'timezone'     => $tz->getName(),
            // Named only when the appointment's zone is not the profile's (copy.md).
            'timezone_note' => $tz->getName() !== BusinessLocale::timezoneName($listing) ? $tz->getName() : '',
        ];
    }

    /** The team member's name, for "booked by". */
    private static function memberName(int $listingId, int $memberId): ?string
    {
        if ($memberId <= 0) {
            return null;
        }
        $member = BusinessMemberModel::forBusiness($listingId)->find($memberId);
        $account = is_array($member) ? (new AccountModel())->find((int) $member['account_id']) : null;
        $name    = is_array($account) ? trim((string) ($account['name'] ?? '')) : '';

        return $name !== '' ? $name : null;
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
