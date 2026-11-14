<?php

namespace App\Controllers;

use App\Controllers\Concerns\RedirectsToOwningSite;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AppointmentStatus;
use App\Services\Bookings\Notifications\NotificationRules;
use App\Services\Bookings\Payments\DepositService;
use App\Services\Bookings\PublicBookingService;
use CodeIgniter\Exceptions\PageNotFoundException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Booking online from a business profile (Phase 5, docs/bookings/routes.md,
 * Bookings' PublicBookingController and BookingLinkController).
 *
 * - `/directory/{slug}/book`: choose a service, a person or anyone, a day
 *   and a time, then the details form. Every step is a plain GET link, so it
 *   works without script and starts no session; the session (flash) starts
 *   only when the details form is sent, like Bookings.
 * - `/book/{token}`: the customer's change or cancel page. `/book/customer/
 *   {hash}`: all their bookings with that business. Both are private:
 *   noindex, no store, no referrer (the key is in the address).
 * - `/book/find`: emails the links; the same answer whether anything matched.
 *
 * All of it answers 404 while bookings are off, on a site that does not take
 * them yet (D2: co.za first), or for a profile that takes none.
 */
class Book extends BaseController
{
    use RedirectsToOwningSite;

    public const SENT_MESSAGE = 'If that address has upcoming bookings, we have emailed it the links. Check your inbox in a minute or two.';

    public function page(string $slug)
    {
        [$listing, $svc] = $this->bookable($slug);
        if ($listing instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $listing;
        }

        $services  = $svc->services();
        $who       = (string) ($this->request->getGet('provider') ?? '');
        // "Book with" on the profile (T28) names a person before a service:
        // list only what they offer, and keep them chosen. Anyone else's id
        // (or another business's) is ignored.
        $withId = ctype_digit($who) ? (int) $who : 0;
        $theirs = $withId > 0 ? array_values(array_filter($services, static fn (array $s): bool => in_array($withId, $s['provider_ids'], true))) : [];
        if ($theirs === []) {
            $withId = 0;
        } else {
            $services = $theirs;
        }
        $serviceId = (int) $this->request->getGet('service');
        $service   = $svc->service($serviceId) ?? (count($services) === 1 ? $services[0] : null);
        // Where: the places that service is given at (with the named person,
        // when "Book with" chose one). One place is chosen for them.
        $placeOptions = $service !== null ? $svc->placesFor((int) $service['id'], $withId > 0 ? $withId : null) : [];
        $placeParam   = (string) ($this->request->getGet('place') ?? '');
        $place        = null;
        foreach ($placeOptions as $pl) {
            if (count($placeOptions) === 1 || ($placeParam !== '' && ctype_digit($placeParam) && $pl['id'] === (int) $placeParam)) {
                $place = $pl;
            }
        }
        $placeId   = $place !== null ? $place['id'] : null;
        $providers = $service !== null && $place !== null ? $svc->providersFor((int) $service['id'], $placeId) : [];
        // Who works at each place, for the Where cards.
        $placePeople = [];
        if ($service !== null && $place === null) {
            foreach ($placeOptions as $pl) {
                $placePeople[$pl['id']] = array_column($svc->providersFor((int) $service['id'], $pl['id']), 'name');
            }
        }
        // A person's headshot from their team card, which only a Verified or
        // Plus profile shows publicly.
        if ($providers !== [] && listing_has_premium($listing)) {
            $photos = array_column((new \App\Models\DirectoryListingTeamModel())->forListing((int) $listing['id']), 'photo_path', 'id');
            foreach ($providers as &$p) {
                $p['photo_path'] = $photos[(int) ($p['team_member_id'] ?? 0)] ?? null;
            }
            unset($p);
        }
        $provider  = null;
        if (count($providers) === 1) {
            $provider = $providers[0];
        } elseif ($who !== '' && $who !== 'any') {
            foreach ($providers as $p) {
                if ((int) $p['id'] === (int) $who) {
                    $provider = $p;
                }
            }
        }
        $whoChosen  = $provider !== null || ($who === 'any' && $providers !== []);
        $providerId = $provider !== null ? (int) $provider['id'] : null;

        $today = $svc->today();
        $date  = $this->date($this->request->getGet('date'));
        $start = (string) ($this->request->getGet('start') ?? '');
        $ready = $service !== null && $whoChosen;

        // The month calendar of free days, as on the change page.
        $calendar = $ready ? $svc->month((int) $service['id'], $providerId, $placeId, (string) ($this->request->getGet('month') ?? ($date !== null ? substr($date, 0, 7) : ''))) : null;
        $times    = $ready && $date !== null ? $svc->times((int) $service['id'], $providerId, $date, $placeId) : [];
        $slot  = null;
        foreach ($times as $t) {
            if ($t['start'] === $start) {
                $slot = $t;
            }
        }

        // The deposit each service asks for now, by service id (none without a payment account).
        $depositSvc = new DepositService($listing);
        $deposits   = [];
        foreach ($services as $s) {
            $deposits[(int) $s['id']] = $depositSvc->depositFor($s);
        }
        $deposit = $service !== null ? ($deposits[(int) $service['id']] ?? null) : null;

        return $this->respondPrivate(view('book/page', [
            'listing'   => $listing,
            'deposits'  => $deposits,
            'deposit'   => $deposit,
            'holdMinutes' => $depositSvc->holdMinutes(),
            'services'  => $services,
            'service'   => $service,
            'withId'    => $withId,
            'placeOptions' => $placeOptions,
            'place'     => $place,
            'placePeople' => $placePeople,
            'providers' => $providers,
            'provider'  => $provider,
            'who'       => $whoChosen ? ($provider !== null ? (string) $provider['id'] : 'any') : '',
            'today'     => $today,
            'calendar'  => $calendar,
            'date'      => $date,
            'times'     => $times,
            'slot'      => $slot,
            'next'      => $ready && $slot === null ? $svc->nextAvailable((int) $service['id'], $providerId, $placeId) : null,
            'modes'     => $service !== null ? \App\Services\Bookings\Integrations\DeliveryModes::offered((int) $listing['id'], $service) : [],
            'fields'    => $svc->customerFields($deposit !== null),
            'questions' => (new \App\Services\Bookings\CustomerService((int) $listing['id']))->customFields(),
            'hasTerms'  => $svc->hasTerms(),
            'policies'  => $svc->policies(),
            'old'       => flash('book_old') ?: [],
            'errors'    => flash('errors') ?: [],
            'timezone'  => $svc->timezone()->getName(),
            'twelveHour' => \App\Services\Bookings\BookingSettings::for((int) $listing['id'])->get('localization.time_format') === '12h',
        ]), false);
    }

    /** JSON: {data: [Y-m-d, ...]} free days from ?from for 14 days. */
    public function days(string $slug)
    {
        if (($limited = $this->jsonThrottle()) !== null) {
            return $limited;
        }
        [$listing, $svc] = $this->bookable($slug, false);
        $provider = (int) $this->request->getGet('provider');

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['data' => $svc->days(
            (int) $this->request->getGet('service'),
            $provider > 0 ? $provider : null,
            $this->date($this->request->getGet('from')) ?? $svc->today(),
            14,
            $this->place(),
        ), 'meta' => ['timezone' => $svc->timezone()->getName()]]);
    }

    /** JSON: {data: [{start, end, time, provider_id|provider_ids}]} for ?service&provider&date. */
    public function slots(string $slug)
    {
        if (($limited = $this->jsonThrottle()) !== null) {
            return $limited;
        }
        [$listing, $svc] = $this->bookable($slug, false);
        $provider = (int) $this->request->getGet('provider');
        $date     = $this->date($this->request->getGet('date'));
        $data     = $date === null ? [] : array_map(
            static fn (array $s): array => array_intersect_key($s, array_flip(['start', 'end', 'time', 'provider_id', 'provider_ids'])),
            $svc->times((int) $this->request->getGet('service'), $provider > 0 ? $provider : null, $date, $this->place()),
        );

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['data' => $data, 'meta' => ['date' => $date, 'timezone' => $svc->timezone()->getName()]]);
    }

    /** ?place as a location id (0 = the main address), or null when not given. */
    private function place(): ?int
    {
        $place = (string) ($this->request->getGet('place') ?? '');

        return ctype_digit($place) ? (int) $place : null;
    }

    public function store(string $slug)
    {
        [$listing, $svc] = $this->bookable($slug, false);
        $post = (array) $this->request->getPost();
        $back = base_url('directory/' . $listing['slug'] . '/book') . '?' . http_build_query(array_filter([
            'service'  => (string) ($post['service_id'] ?? ''),
            'place'    => ctype_digit((string) ($post['place'] ?? '')) ? (string) $post['place'] : '',
            'provider' => ((int) ($post['provider_id'] ?? 0)) > 0 ? (string) $post['provider_id'] : 'any',
            'date'     => $this->localDate((string) ($post['start'] ?? ''), $svc->timezone()),
            'start'    => (string) ($post['start'] ?? ''),
        ])) . '#details';

        if (honeypot_tripped('book')) {
            return redirect()->to($back)->with('error', 'We could not take this booking. Please try again.');
        }
        // A demo profile shows the steps and never books, emails or stores anything.
        if (listing_is_demo($listing)) {
            return redirect()->to($back)->with('book_old', form_typed_fields($post))
                ->with('error', 'This is a demo business profile, so nothing was booked and nobody was contacted.');
        }
        $email     = strtolower(trim((string) ($post['email'] ?? '')));
        $throttler = service('throttler');
        if ($throttler->check('book-ip-' . md5((string) $this->request->getIPAddress()), 10, HOUR) === false
            || ($email !== '' && $throttler->check('book-to-' . md5($email), 5, DAY) === false)) {
            form_outcome('throttled');

            return redirect()->to($back)->with('book_old', form_typed_fields($post))
                ->with('error', 'Too many bookings from here in a short time. Please wait a little and try again.');
        }

        $result = $svc->book($post);
        if ($result['errors'] !== []) {
            // A time that has gone sends them back to choose another; anything else stays on the form.
            if (isset($result['errors']['start']) || isset($result['errors']['provider_id']) || isset($result['errors']['service_id'])) {
                $back = preg_replace('/&?start=[^&#]*/', '', $back);
            }

            return redirect()->to($back)->with('errors', $result['errors'])->with('book_old', form_typed_fields($post))
                ->with('error', reset($result['errors']));
        }

        return redirect()->to(base_url('book/' . $result['token'] . '?new=1'), 303);
    }

    public function terms(string $slug)
    {
        [$listing, $svc] = $this->bookable($slug);
        if ($listing instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $listing;
        }

        return $this->respondPrivate(view('book/terms', ['listing' => $listing, 'policies' => $svc->policies()]), false);
    }

    // The customer's own pages

    public function manage(string $token)
    {
        return $this->showBooking(PublicBookingService::byToken($token), 'book/' . $token, (bool) $this->request->getGet('new'));
    }

    public function manageFromCustomer(string $customerHash, string $appointmentHash)
    {
        return $this->showBooking(PublicBookingService::byCustomer($customerHash, $appointmentHash), 'book/customer/' . $customerHash . '/' . $appointmentHash, false);
    }

    public function reschedule(string $token)
    {
        return $this->changeBooking(PublicBookingService::byToken($token), 'book/' . $token, 'reschedule', true);
    }

    public function rescheduleFromCustomer(string $customerHash, string $appointmentHash)
    {
        return $this->changeBooking(PublicBookingService::byCustomer($customerHash, $appointmentHash), 'book/customer/' . $customerHash . '/' . $appointmentHash, 'reschedule', false);
    }

    public function cancel(string $token)
    {
        return $this->changeBooking(PublicBookingService::byToken($token), 'book/' . $token, 'cancel', true);
    }

    public function cancelFromCustomer(string $customerHash, string $appointmentHash)
    {
        return $this->changeBooking(PublicBookingService::byCustomer($customerHash, $appointmentHash), 'book/customer/' . $customerHash . '/' . $appointmentHash, 'cancel', false);
    }

    public function pay(string $token)
    {
        return $this->payDeposit(PublicBookingService::byToken($token), 'book/' . $token);
    }

    public function payFromCustomer(string $customerHash, string $appointmentHash)
    {
        return $this->payDeposit(PublicBookingService::byCustomer($customerHash, $appointmentHash), 'book/customer/' . $customerHash . '/' . $appointmentHash);
    }

    /**
     * Back from PayFast or Paystack (`book/paid/{payment hash}`): on to the
     * customer's page for the booking, which says whether the deposit is in.
     */
    public function paid(string $hash)
    {
        $found = DepositService::returned($hash);
        if ($found === null) {
            return $this->respondPrivate(view('book/expired'))->setStatusCode(404);
        }
        $customer = db_connect()->table('xs_booking_customers')->select('hash')->where('id', (int) $found['appointment']['customer_id'])->get()->getRowArray();
        if ($customer === null) {
            return $this->respondPrivate(view('book/expired'))->setStatusCode(404);
        }
        $to = listing_site_url($found['listing'], 'book/customer/' . $customer['hash'] . '/' . $found['appointment']['hash'] . '#deposit');

        return $found['payment']['status'] === \App\Models\Bookings\BookingPaymentModel::PAID
            ? redirect()->to($to)->with('success', 'Thank you. Your deposit is paid and your booking is made.')
            : redirect()->to($to);
    }

    /** PayFast's notification for a deposit, server to server: always 200, the outcome goes to the log. */
    public function payfastNotify()
    {
        [$raw] = \App\Libraries\PayFast::readNotificationBody($this->request);
        $outcome = DepositService::payfastNotified($raw, (string) $this->request->getIPAddress());
        log_message($outcome === 'paid' ? 'info' : 'warning', 'Booking deposit notification: ' . $outcome);

        return $this->response->setStatusCode(200)->setBody('');
    }

    public function customer(string $hash)
    {
        $customer = PublicBookingService::customerByHash($hash);
        $listing  = $customer !== null ? (new DirectoryListingModel())->find((int) $customer['listing_id']) : null;
        if ($listing === null || ! config('Bookings')->siteAllowed(site()->forRow($listing)) || ! (int) $listing['bookings_enabled']) {
            throw PageNotFoundException::forPageNotFound();
        }
        if (($moved = $this->redirectToOwningSite($listing)) !== null) {
            return $moved;
        }
        $svc = new PublicBookingService($listing);

        return $this->respondPrivate(view('book/customer', [
            'listing'  => $listing,
            'customer' => $customer,
            'list'     => $svc->customerAppointments((int) $customer['id']),
            'timezone' => $svc->timezone(),
        ]));
    }

    /**
     * "Stop emails from {business}": GET asks, POST acts (mail scanners open
     * every link). The POST is also RFC 8058 one click, posted by the mail
     * provider with no session, so it is CSRF exempt and never redirected.
     * Works while the business has bookings off too: stopping is always allowed.
     */
    public function unsubscribePage(string $hash)
    {
        [, $listing] = $this->unsubscribeTarget($hash);

        return $this->respondPrivate(view('book/unsubscribe', ['listing' => $listing, 'hash' => $hash, 'done' => false]));
    }

    public function unsubscribe(string $hash)
    {
        [$customer, $listing] = $this->unsubscribeTarget($hash);
        if (! empty($customer['email'])) {
            (new \App\Services\Bookings\Notifications\OptOuts((int) $listing['id']))
                ->optOut('email', (string) $customer['email'], $this->request->getPost('List-Unsubscribe') !== null ? 'one_click' : 'link');
        }

        return $this->respondPrivate(view('book/unsubscribe', ['listing' => $listing, 'hash' => $hash, 'done' => true]));
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function unsubscribeTarget(string $hash): array
    {
        $customer = PublicBookingService::customerByHash($hash);
        $listing  = $customer !== null ? (new DirectoryListingModel())->find((int) $customer['listing_id']) : null;
        if ($listing === null || ! config('Bookings')->enabled()) {
            throw PageNotFoundException::forPageNotFound();
        }

        return [$customer, $listing];
    }

    public function find()
    {
        $this->siteOrNotFound();

        return $this->respondPrivate(view('book/find', ['reference' => mb_substr((string) $this->request->getGet('reference'), 0, 40)]), false);
    }

    public function sendLinks()
    {
        $this->siteOrNotFound();
        if (honeypot_tripped('book_find')) {
            return redirect()->to(base_url('book/find'))->with('success', self::SENT_MESSAGE);
        }
        $email     = strtolower(trim((string) $this->request->getPost('email')));
        $throttler = service('throttler');
        if ($throttler->check('bookfind-ip-' . md5((string) $this->request->getIPAddress()), 5, HOUR) === false
            || ($email !== '' && $throttler->check('bookfind-to-' . md5($email), 3, DAY) === false)) {
            form_outcome('throttled');

            return redirect()->to(base_url('book/find'))->with('error', 'Too many requests from here. Please wait a little and try again.');
        }
        PublicBookingService::emailLinks($email);

        return redirect()->to(base_url('book/find'))->with('success', self::SENT_MESSAGE);
    }

    /** A booking reference opens the find form only: references are sequential. */
    public function reference(string $reference)
    {
        $this->siteOrNotFound();

        return redirect()->to(base_url('book/find?reference=' . rawurlencode(mb_substr($reference, 0, 40))));
    }

    // Internals

    /**
     * The profile and its booking service, or a 404 (or a redirect to the
     * owning site on a GET when $redirect).
     *
     * @return array{0:array<string,mixed>|\CodeIgniter\HTTP\ResponseInterface,1:PublicBookingService|null}
     */
    private function bookable(string $slug, bool $redirect = true): array
    {
        $listing = (new DirectoryListingModel())->findPublishedBySlug($slug);
        if ($listing === null || ! config('Bookings')->enabled()) {
            throw PageNotFoundException::forPageNotFound();
        }
        if ($redirect && ($moved = $this->redirectToOwningSite($listing)) !== null) {
            return [$moved, null];
        }
        if (! PublicBookingService::takesBookings($listing)) {
            throw PageNotFoundException::forPageNotFound();
        }
        hours_context($listing);

        return [$listing, new PublicBookingService($listing)];
    }

    /** @param array{listing:array<string,mixed>,appointment:array<string,mixed>}|null $found */
    private function showBooking(?array $found, string $path, bool $new)
    {
        if ($found === null) {
            return $this->respondPrivate(view('book/expired'))->setStatusCode(404);
        }
        if (($moved = $this->redirectToOwningSite($found['listing'])) !== null) {
            return $moved;
        }
        $svc  = new PublicBookingService($found['listing']);
        $a    = $found['appointment'];
        $date = $this->date($this->request->getGet('date'));

        return $this->respondPrivate(view('book/manage', [
            'listing'      => $found['listing'],
            'a'            => $a,
            'path'         => $path,
            'new'          => $new,
            'customerHash' => (string) (db_connect()->table('xs_booking_customers')->select('hash')->where('id', $a['customer_id'])->get()->getRowArray()['hash'] ?? ''),
            'timezone'     => $svc->timezone(),
            'moveRefusal'  => $svc->refusal($a, 'reschedule'),
            'cancelRefusal' => $svc->refusal($a, 'cancel'),
            'policies'     => $svc->policies(),
            'today'        => $svc->today(),
            // The month calendar of free days, up to the future booking limit.
            'calendar'     => $svc->refusal($a, 'reschedule') === null
                ? $svc->rescheduleMonth($a, (string) ($this->request->getGet('month') ?? ($date !== null ? substr($date, 0, 7) : '')))
                : null,
            'nextFree'     => $svc->refusal($a, 'reschedule') === null ? $svc->rescheduleNext($a) : null,
            'date'         => $date,
            'times'        => $date !== null && $svc->refusal($a, 'reschedule') === null ? $svc->rescheduleTimes($a, $date) : [],
            'twelveHour'   => \App\Services\Bookings\BookingSettings::for((int) $found['listing']['id'])->get('localization.time_format') === '12h',
            'deposit'      => (new DepositService($found['listing']))->forCustomer($a),
        ]));
    }

    /**
     * Pay the deposit: off to Paystack's checkout, or a page whose button
     * posts the signed form to PayFast. That page renders on this POST, so
     * the honeypot filter is off for it (Config\Filters): a field PayFast's
     * signature does not cover would get the payment refused.
     *
     * @param array{listing:array<string,mixed>,appointment:array<string,mixed>}|null $found
     */
    private function payDeposit(?array $found, string $path)
    {
        if ($found === null) {
            form_outcome('expired');

            return $this->respondPrivate(view('book/expired'))->setStatusCode(404);
        }
        $start = (new DepositService($found['listing']))->start($found['appointment']);
        if ($start['error'] !== null) {
            return redirect()->to(base_url($path . '#deposit'))->with('error', $start['error']);
        }
        if ($start['redirect'] !== null) {
            return redirect()->to($start['redirect']);
        }

        return $this->respondPrivate(view('book/pay', ['listing' => $found['listing'], 'a' => $found['appointment'], 'form' => $start['form'], 'back' => $path]));
    }

    /** @param array{listing:array<string,mixed>,appointment:array<string,mixed>}|null $found */
    private function changeBooking(?array $found, string $path, string $what, bool $rotates)
    {
        if ($found === null) {
            form_outcome('expired');

            return $this->respondPrivate(view('book/expired'))->setStatusCode(404);
        }
        $svc = new PublicBookingService($found['listing']);
        if ($what === 'cancel') {
            if ($this->request->getPost('confirm') !== '1') {
                return redirect()->to(base_url($path . '#cancel'))->with('error', 'Tick the box to confirm.');
            }
            $error = $svc->cancel($found['appointment']);

            return redirect()->to(base_url($path))->with($error === null ? 'success' : 'error', $error ?? 'Your booking is cancelled.');
        }

        $result = $svc->reschedule($found['appointment'], (string) $this->request->getPost('start'));
        if ($result['error'] !== null) {
            return redirect()->to(base_url($path . '#change'))->with('error', $result['error']);
        }
        // The old link stops working; the token path follows the new one.
        $next = $rotates ? 'book/' . $result['token'] : $path;

        // Moving keeps the status, so the alert says what that status means
        // for the customer (AppointmentStatus::customerMessage()). Waiting:
        // also how they will hear, unless the business switched the
        // confirmation email off.
        $status  = (string) ($found['appointment']['status'] ?? '');
        $message = trim('Done. Your booking has a new time. '
            . AppointmentStatus::customerMessage($status, (string) $found['listing']['display_name']));
        if ($status === BookingAppointmentModel::STATUS_PENDING
            && (new NotificationRules((int) $found['listing']['id']))->enabled('confirmed', 'email')) {
            $message = rtrim($message, '.') . ', and we will email you when they do.';
        }

        return redirect()->to(base_url($next))->with('success', $message);
    }

    /**
     * No caching and no index; where the address carries the key, no Referer
     * either (BaseController::privatePage()).
     */
    private function respondPrivate(string $html, bool $hasKey = true)
    {
        if ($hasKey) {
            $this->privatePage();
        } else {
            $this->response->setHeader('Cache-Control', 'no-store, private')->setHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        return $this->response->setBody($html);
    }

    private function siteOrNotFound(): void
    {
        if (! config('Bookings')->siteAllowed(site()->key())) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /** A JSON endpoint's own throttle: 60 a minute per address, an empty answer over it. */
    private function jsonThrottle()
    {
        if (service('throttler')->check('bookjson-ip-' . md5((string) $this->request->getIPAddress()), 60, MINUTE) === false) {
            return $this->response->setStatusCode(429)->setJSON(['data' => []]);
        }

        return null;
    }

    private function date(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        $d     = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    private function localDate(string $utc, DateTimeZone $tz): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utc)) {
            return '';
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
    }
}
