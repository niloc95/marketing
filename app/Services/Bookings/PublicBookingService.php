<?php

namespace App\Services\Bookings;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingProviderModel;
use App\Models\Bookings\BookingProviderServiceModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\Integrations\DeliveryModes;
use App\Services\Bookings\Payments\DepositService;
use Config\Bookings;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Booking online from a business profile (Phase 5): the customer's side.
 * Ported from Bookings' PublicBookingService, PublicBookingPolicy and
 * PublicBookingInputSanitizer. Reads here; every write goes through
 * AppointmentService (and so BookingLock) and CustomerService.
 *
 * What differs from Bookings, on purpose:
 *
 * - **The change or cancel link is the key.** Bookings asked for the token
 *   and then the email and the phone again. Here the token (64 hex, stored
 *   hashed, rotated on every change, ending 30 days after the appointment)
 *   is enough, like Local's /manage links. The customer page works the same
 *   way with the customer's 64 character hash.
 * - **Find by email sends links, never lists bookings** (anyone can type an
 *   email): `/book/find` emails the address its customer pages and gives the
 *   same answer whether it matched or not. `/book/r/{reference}` only opens
 *   that form; a reference is sequential and must not open a booking.
 * - **The same customer is never renamed by a booking**: findOrCreate() fills
 *   only empty fields (Bookings' preserveNames for the public channel).
 * - **Policy windows** are hours per business (`business.reschedule`,
 *   `business.cancel`, 0 = not online) instead of Bookings' fixed 12h or 24h.
 * - Where a profile takes bookings is decided once, by takesBookings().
 */
class PublicBookingService
{
    private int $listingId;

    private DateTimeImmutable $now;

    private AvailabilityService $engine;

    private BookingSettings $settings;

    /** @param array<string,mixed> $listing a published profile */
    public function __construct(private array $listing, ?DateTimeImmutable $now = null)
    {
        $this->listingId = (int) $listing['id'];
        $this->now       = $now ?? new DateTimeImmutable('now');
        $this->engine    = new AvailabilityService($listing, $this->now);
        $this->settings  = BookingSettings::for($this->listingId);
    }

    /**
     * Whether a profile takes bookings on this request's site: the global
     * switch, the site (decision D2: co.za first), the profile's own switch,
     * published, and at least one service someone can be booked for.
     *
     * @param array<string,mixed> $listing
     */
    public static function takesBookings(array $listing): bool
    {
        if (! config('Bookings')->siteAllowed(site()->key())
            || site()->forRow($listing) !== site()->key()
            || ! (int) ($listing['bookings_enabled'] ?? 0)
            || ($listing['status'] ?? '') !== 'published'
            || ! (new Plans\BookingPlanService())->allows($listing)) {
            return false;
        }

        return (new self($listing))->services() !== [];
    }

    /** The link to book a profile, or '' when it takes no bookings. */
    public static function bookUrl(array $listing): string
    {
        return self::takesBookings($listing) ? base_url('directory/' . $listing['slug'] . '/book') : '';
    }

    public function timezone(): DateTimeZone
    {
        return $this->engine->timezone();
    }

    public function today(): string
    {
        return $this->now->setTimezone($this->timezone())->format('Y-m-d');
    }

    /**
     * Services customers can book: not archived, with at least one bookable
     * person who offers it.
     *
     * @return list<array<string,mixed>>
     */
    public function services(): array
    {
        $bookable = array_map('intval', array_column(
            BookingProviderModel::forBusiness($this->listingId)->where('archived_at', null)->where('bookable', 1)->findAll(),
            'id',
        ));
        if ($bookable === []) {
            return [];
        }
        $offered = array_map('intval', array_column(
            BookingProviderServiceModel::forBusiness($this->listingId)->whereIn('provider_id', $bookable)->findAll(),
            'service_id',
        ));

        return array_values(array_filter(
            (new CatalogueService($this->listingId))->services(),
            static fn (array $s): bool => in_array((int) $s['id'], $offered, true),
        ));
    }

    /** @return array<string,mixed>|null */
    public function service(int $id): ?array
    {
        foreach ($this->services() as $s) {
            if ((int) $s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Bookable people who offer a service, in team order.
     *
     * @return list<array<string,mixed>>
     */
    public function providersFor(int $serviceId): array
    {
        $service = $this->service($serviceId);
        if ($service === null) {
            return [];
        }

        return array_values(array_filter(
            (new TeamService($this->listingId))->providers(),
            static fn (array $p): bool => (int) $p['bookable'] === 1 && in_array((int) $p['id'], $service['provider_ids'], true),
        ));
    }

    /**
     * Dates with at least one free time, from a date, for this many days.
     *
     * @return list<string>
     */
    public function days(int $serviceId, ?int $providerId, string $from, int $count = 14): array
    {
        if (! $this->offered($serviceId, $providerId)) {
            return [];
        }
        $from = max($from, $this->today());
        $to   = (new DateTimeImmutable($from, $this->timezone()))->modify('+' . (max(1, min(31, $count)) - 1) . ' days')->format('Y-m-d');

        return $this->engine->availableDates($serviceId, $providerId, $from, $to);
    }

    /**
     * Free times on a date. With nobody chosen, each time names who is free.
     *
     * @return list<array<string,mixed>>
     */
    public function times(int $serviceId, ?int $providerId, string $date): array
    {
        if (! $this->offered($serviceId, $providerId) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        return $providerId !== null
            ? $this->engine->slots($providerId, $serviceId, $date, true)
            : $this->engine->slotsForAnyone($serviceId, $date, true);
    }

    /** The first free time from today, for "Next free time". */
    public function nextAvailable(int $serviceId, ?int $providerId): ?array
    {
        return $this->offered($serviceId, $providerId) ? $this->engine->nextAvailable($serviceId, $providerId) : null;
    }

    /**
     * The customer fields of the booking form, in order. With a deposit to
     * pay the email is always asked for: the receipt goes there, and
     * Paystack will not take a payment without one.
     *
     * @return list<array{stem:string,name:string,required:bool}>
     */
    public function customerFields(bool $deposit = false): array
    {
        $out = [];
        foreach (Bookings::CUSTOMER_FIELDS as $stem => $name) {
            if ($stem === 'first_names' || ($deposit && $stem === 'email')) {
                $out[] = ['stem' => $stem, 'name' => $name, 'required' => true];
            } elseif ($this->settings->get('booking.' . $stem . '_display') === '1') {
                $out[] = ['stem' => $stem, 'name' => $name, 'required' => $this->settings->get('booking.' . $stem . '_required') === '1'];
            }
        }

        return $out;
    }

    /**
     * The business's own terms and policies for the terms page and the form.
     *
     * @return array{terms:string,cancellation:string,rescheduling:string,terms_url:string,privacy_url:string,reschedule_hours:int,cancel_hours:int}
     */
    public function policies(): array
    {
        return [
            'terms'            => $this->settings->get('legal.terms'),
            'cancellation'     => $this->settings->get('legal.cancellation_policy'),
            'rescheduling'     => $this->settings->get('legal.rescheduling_policy'),
            'terms_url'        => safe_external_url($this->settings->get('legal.terms_url')),
            'privacy_url'      => safe_external_url($this->settings->get('legal.privacy_url')),
            'reschedule_hours' => $this->settings->int('business.reschedule'),
            'cancel_hours'     => $this->settings->int('business.cancel'),
        ];
    }

    /** Whether the business has terms the customer must accept. */
    public function hasTerms(): bool
    {
        $p = $this->policies();

        return trim($p['terms'] . $p['cancellation'] . $p['rescheduling']) !== '' || $p['terms_url'] !== '';
    }

    /**
     * Book. `provider_id` 0 is "anyone available": the first person free at
     * that time, in team order.
     *
     * @param array<string,mixed> $input service_id, provider_id, start (UTC Y-m-d H:i:s),
     *                                   the customer fields, field_1 ... field_6, notes, accept
     *
     * @return array{token:?string,reference:?string,errors:array<string,string>}
     */
    public function book(array $input): array
    {
        $serviceId  = (int) ($input['service_id'] ?? 0);
        $providerId = (int) ($input['provider_id'] ?? 0);
        $start      = (string) ($input['start'] ?? '');
        $errors     = [];

        $service = $this->service($serviceId);
        if ($service === null) {
            return ['token' => null, 'reference' => null, 'errors' => ['service_id' => AppointmentService::CUSTOMER_PROBLEMS[AvailabilityService::NOT_OFFERED]]];
        }
        $deposits = new DepositService($this->listing, $this->now);
        $deposit  = $deposits->depositFor($service);
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $start)) {
            return ['token' => null, 'reference' => null, 'errors' => ['start' => AppointmentService::CUSTOMER_PROBLEMS[AvailabilityService::NOT_ON_THE_GRID]]];
        }
        if ($providerId === 0) {
            $date = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone($this->timezone())->format('Y-m-d');
            foreach ($this->times($serviceId, null, $date) as $slot) {
                if ($slot['start'] === $start) {
                    $providerId = (int) $slot['provider_ids'][0];

                    break;
                }
            }
            if ($providerId === 0) {
                return ['token' => null, 'reference' => null, 'errors' => ['start' => AppointmentService::CUSTOMER_PROBLEMS[AvailabilityService::BUSY]]];
            }
        } elseif (! in_array($providerId, array_map('intval', array_column($this->providersFor($serviceId), 'id')), true)) {
            return ['token' => null, 'reference' => null, 'errors' => ['provider_id' => AppointmentService::CUSTOMER_PROBLEMS[AvailabilityService::NOT_OFFERED]]];
        }

        // The customer as the business's form asks for them.
        $customers = new CustomerService($this->listingId);
        $person    = [];
        foreach ($this->customerFields($deposit !== null) as $f) {
            $value = trim((string) ($input[$f['name']] ?? ''));
            if ($f['required'] && $value === '') {
                $errors[$f['name']] = 'Please fill this in.';
            }
            if ($f['name'] !== 'notes') {
                $person[$f['name']] = $value;
            }
        }
        [, $customerErrors] = $customers->clean($person + ['notes' => null]);
        foreach ($customerErrors as $key => $message) {
            // "Add an email or a phone" is the form's own required flags' job;
            // keep CustomerService's word only on an address that is wrong.
            if ($key === 'email' && trim((string) ($person['email'] ?? '')) === '') {
                continue;
            }
            $errors[$key] ??= $message;
        }
        $errors += $customers->answerErrors($input);
        $mode = DeliveryModes::pick($this->listingId, $service, isset($input['delivery_mode']) ? (string) $input['delivery_mode'] : null);
        if ($mode === null) {
            $errors['delivery_mode'] = 'Please choose how you would like it.';
        }
        if ($this->hasTerms() && empty($input['accept'])) {
            $errors['accept'] = 'Please accept the booking terms.';
        }
        if ($errors !== []) {
            return ['token' => null, 'reference' => null, 'errors' => $errors];
        }

        $customer = $customers->findOrCreate($person + ['notes' => null] + $customers->answers($input), null);
        if ($customer['id'] === null) {
            return ['token' => null, 'reference' => null, 'errors' => $customer['errors']];
        }

        // A deposit to pay holds the time as a request; paying it moves the
        // booking to the usual status (DepositService::paid()).
        $status = $deposit !== null ? BookingAppointmentModel::STATUS_PENDING : $this->settings->get('booking.default_appointment_status');
        $result = BookingLock::reserve($this->listing, [
            'provider_id' => $providerId,
            'service_id'  => $serviceId,
            'customer_id' => $customer['id'],
            'start_at'    => $start,
            'status'      => $status,
            'notes'       => $this->text($input['notes'] ?? null, 1000),
            'delivery_mode' => $mode,
        ], true, $this->now);
        if ($result['problem'] !== null) {
            return ['token' => null, 'reference' => null, 'errors' => ['start' => AppointmentService::CUSTOMER_PROBLEMS[$result['problem']] ?? AppointmentService::CUSTOMER_PROBLEMS[AvailabilityService::BUSY]]];
        }

        $id      = (int) $result['id'];
        $answers = $customers->answers($input);
        if ($answers !== []) {
            $rows = [];
            foreach ($answers as $key => $value) {
                $rows[] = ['appointment_id' => $id, 'field_key' => $key, 'value' => $value];
            }
            (new \App\Models\Bookings\BookingAppointmentFieldModel())->insertBatch($rows);
            $customers->rememberAnswers($customer['id'], $answers);
        }
        (new BookingAudit())->record($this->listingId, null, 'appointment.booked_online', 'appointment', $id, null, ['start_at' => $start, 'provider_id' => $providerId, 'service_id' => $serviceId, 'deposit_cents' => $deposit]);
        if ($deposit !== null) {
            // No messages yet: they go out once the deposit is paid.
            $deposits->ask($id, $deposit);
            $this->appointments()->notify($id, null, true, 'created');
        } else {
            $this->appointments()->notify($id, \App\Services\Bookings\Notifications\NotificationQueue::statusEvent($status), true, 'created');
        }

        return ['token' => $this->appointments()->issuePublicToken($id), 'reference' => $result['reference'], 'errors' => []];
    }

    // The customer's link

    /**
     * The appointment a change or cancel link opens, with its business, or
     * null when the link is unknown or has expired.
     *
     * @return array{listing:array<string,mixed>,appointment:array<string,mixed>}|null
     */
    public static function byToken(string $token, ?DateTimeImmutable $now = null): ?array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $row = db_connect()->table('xs_booking_appointments')
            ->where('public_token_hash', TokenHash::hash($token))
            ->get()->getRowArray();
        if ($row === null || (string) $row['public_token_expires'] < gmdate('Y-m-d H:i:s', ($now ?? new DateTimeImmutable())->getTimestamp())) {
            return null;
        }

        return self::withListing($row);
    }

    /**
     * One appointment from the customer's own page.
     *
     * @return array{listing:array<string,mixed>,appointment:array<string,mixed>}|null
     */
    public static function byCustomer(string $customerHash, string $appointmentHash): ?array
    {
        $customer = self::customerByHash($customerHash);
        if ($customer === null || ! preg_match('/^[0-9a-f]{32}$/', $appointmentHash)) {
            return null;
        }
        $row = BookingAppointmentModel::forBusiness((int) $customer['listing_id'])
            ->where('hash', $appointmentHash)->where('customer_id', $customer['id'])->first();

        return is_array($row) ? self::withListing($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function customerByHash(string $hash): ?array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return null;
        }
        $row = db_connect()->table('xs_booking_customers')->where('hash', $hash)->where('purged_at', null)->get()->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * A customer's appointments with this business, upcoming first then the
     * last 20.
     *
     * @return array{upcoming:list<array<string,mixed>>,past:list<array<string,mixed>>}
     */
    public function customerAppointments(int $customerId): array
    {
        $now  = gmdate('Y-m-d H:i:s', $this->now->getTimestamp());
        $base = static fn (int $listingId) => BookingAppointmentModel::forBusiness($listingId)->where('customer_id', $customerId);

        return [
            'upcoming' => $base($this->listingId)->where('end_at >', $now)->whereIn('status', AppointmentStatus::UPCOMING)->orderBy('start_at', 'ASC')->findAll(50),
            'past'     => $base($this->listingId)->groupStart()->where('end_at <=', $now)->orWhereNotIn('status', AppointmentStatus::UPCOMING)->groupEnd()->orderBy('start_at', 'DESC')->findAll(20),
        ];
    }

    /** Hours before the start after which the customer can no longer do it online; null = never online. */
    public function windowHours(string $what): ?int
    {
        $hours = $this->settings->int($what === 'cancel' ? 'business.cancel' : 'business.reschedule');

        return $hours > 0 ? $hours : null;
    }

    /**
     * Whether the customer may still change or cancel online, and if not, why.
     *
     * @param array<string,mixed> $appointment
     */
    public function refusal(array $appointment, string $what): ?string
    {
        if (! in_array($appointment['status'], AppointmentStatus::UPCOMING, true)) {
            return $appointment['status'] === BookingAppointmentModel::STATUS_CANCELLED
                ? 'This booking is cancelled.'
                : 'This booking can no longer be changed online.';
        }
        $hours = $this->windowHours($what);
        $label = $what === 'cancel' ? 'cancelled' : 'changed';
        if ($hours === null) {
            return 'This booking cannot be ' . $label . ' online. Please contact the business.';
        }
        $start = (new DateTimeImmutable((string) $appointment['start_at'], new DateTimeZone('UTC')))->getTimestamp();
        if ($start - $hours * HOUR < $this->now->getTimestamp()) {
            return 'Bookings can be ' . $label . ' online up to ' . $hours . ' ' . ($hours === 1 ? 'hour' : 'hours') . ' before. Please contact the business.';
        }

        return null;
    }

    /**
     * Move to another time offered online, then a new link.
     *
     * @param array<string,mixed> $appointment
     *
     * @return array{token:?string,error:?string}
     */
    public function reschedule(array $appointment, string $startUtc): array
    {
        if (($why = $this->refusal($appointment, 'reschedule')) !== null) {
            return ['token' => null, 'error' => $why];
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $startUtc)) {
            return ['token' => null, 'error' => AppointmentService::CUSTOMER_PROBLEMS[AvailabilityService::NOT_ON_THE_GRID]];
        }
        $moved = $this->appointments()->moveForCustomer((int) $appointment['id'], $startUtc);
        if (! $moved['ok']) {
            return ['token' => null, 'error' => $moved['error']];
        }

        return ['token' => $this->appointments()->issuePublicToken((int) $appointment['id']), 'error' => null];
    }

    /**
     * Times the customer could move to on a date: the same person, the
     * appointment's own time left out of the busy list.
     *
     * @param array<string,mixed> $appointment
     *
     * @return list<array<string,mixed>>
     */
    public function rescheduleTimes(array $appointment, string $date): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        return $this->engine->slots((int) $appointment['provider_id'], (int) $appointment['service_id'], $date, true, (int) $appointment['id']);
    }

    /** @param array<string,mixed> $appointment */
    public function cancel(array $appointment): ?string
    {
        if (($why = $this->refusal($appointment, 'cancel')) !== null) {
            return $why;
        }

        return $this->appointments()->cancelForCustomer((int) $appointment['id']) ? null : 'This booking can no longer be changed online.';
    }

    /**
     * Email someone the links to their bookings (Find my booking). The same
     * answer whatever happens; nothing is sent when the address has no
     * upcoming booking.
     */
    public static function emailLinks(string $email, ?DateTimeImmutable $now = null): void
    {
        $email = CustomerService::normalEmail($email);
        if ($email === null) {
            return;
        }
        $nowUtc    = gmdate('Y-m-d H:i:s', ($now ?? new DateTimeImmutable())->getTimestamp());
        $customers = db_connect()->table('xs_booking_customers')->where('email', $email)->where('purged_at', null)->get()->getResultArray();
        $links     = [];
        foreach ($customers as $c) {
            $listing = (new DirectoryListingModel())->find((int) $c['listing_id']);
            if ($listing === null || ! self::takesBookingsAnywhere($listing)) {
                continue;
            }
            $upcoming = BookingAppointmentModel::forBusiness((int) $c['listing_id'])->where('customer_id', $c['id'])
                ->where('end_at >', $nowUtc)->whereIn('status', AppointmentStatus::UPCOMING)->countAllResults();
            if ($upcoming > 0) {
                $links[] = ['business' => (string) $listing['display_name'], 'count' => $upcoming, 'url' => listing_site_url($listing, 'book/customer/' . $c['hash'])];
            }
        }
        if ($links === []) {
            return;
        }
        $body = view('emails/booking-links', ['links' => $links], ['saveData' => false]);
        (new Mailer())->send($email, 'Your bookings on WebScheduler Local', $body);
    }

    // Internals

    /**
     * Like takesBookings() but for a profile on any site (an email may list
     * bookings with businesses on the other site).
     *
     * @param array<string,mixed> $listing
     */
    private static function takesBookingsAnywhere(array $listing): bool
    {
        return config('Bookings')->siteAllowed(site()->forRow($listing)) && (int) ($listing['bookings_enabled'] ?? 0) === 1;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array{listing:array<string,mixed>,appointment:array<string,mixed>}|null
     */
    private static function withListing(array $row): ?array
    {
        $listing = (new DirectoryListingModel())->find((int) $row['listing_id']);
        if ($listing === null || ! self::takesBookingsAnywhere($listing)) {
            return null;
        }

        return ['listing' => $listing, 'appointment' => $row];
    }

    private function offered(int $serviceId, ?int $providerId): bool
    {
        if ($this->service($serviceId) === null) {
            return false;
        }

        return $providerId === null || in_array($providerId, array_map('intval', array_column($this->providersFor($serviceId), 'id')), true);
    }

    private function appointments(): AppointmentService
    {
        return new AppointmentService($this->listing, null, null, null, $this->now);
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim(strip_tags((string) ($value ?? '')));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
