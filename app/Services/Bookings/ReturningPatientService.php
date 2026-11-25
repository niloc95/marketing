<?php

namespace App\Services\Bookings;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\Bookings\BookingCustomerModel;
use DateTimeImmutable;

/**
 * "Booked here before?" on the public booking page (owner, 25 Nov 2026,
 * decision T37). A returning patient picks a time as usual, then proves
 * their email on the details step and gets the form filled in from their
 * record with this business.
 *
 * - **One email, a code and a link.** The 6 digit code keeps a phone user on
 *   the page they were on; the link (carrying the chosen time) is for anyone
 *   who would rather click. Both are stored hashed on the customer row
 *   (`login_*`, the only columns of xs_booking_customers written here): the
 *   link as SHA-256 (256 random bits need no key), the code as HMAC SHA-256
 *   keyed with encryption.key, so a copy of the database alone cannot be
 *   searched for a 6 digit code. The code ends after CODE_MINUTES, the link
 *   after LINK_MINUTES, and using either clears both.
 * - **Nothing says whether someone is a patient.** An unknown email gets the
 *   same answer as a known one and no mail is sent; a code typed for an
 *   unknown email simply never matches. Seeing a particular doctor is
 *   medical information.
 * - **Five wrong codes and the code is dead**, counted on the row
 *   (login_tries) and by the controller in the session, so neither a new
 *   session nor a new device gets more tries.
 * - **Proven for this business only**, in the session, for VERIFIED_MINUTES
 *   or until the booking is made. Each business keeps its own record of a
 *   person (privacy policy section 12a), so nothing from another business is
 *   ever shown.
 */
class ReturningPatientService
{
    /** How long the emailed code works. */
    public const CODE_MINUTES = 5;

    /** How long the emailed link works. */
    public const LINK_MINUTES = 10;

    public const MAX_TRIES = 5;

    public const VERIFIED_MINUTES = 30;

    /** Session key: [listing id => {email, sent_at, tries, customer_id, verified_at}]. */
    public const SESSION_KEY = 'book_patient';

    private int $listingId;

    private DateTimeImmutable $now;

    /** @param array<string,mixed> $listing */
    public function __construct(private array $listing, ?DateTimeImmutable $now = null)
    {
        $this->listingId = (int) $listing['id'];
        $this->now       = $now ?? new DateTimeImmutable('now');
    }

    /**
     * Email a code and a link to a customer of this business. Says nothing
     * back: an unknown address gets no email and the caller shows the same
     * message either way.
     *
     * @param array<string,string> $selection the chosen time for the link (service_id, provider_id, place, start)
     */
    public function send(string $email, array $selection): void
    {
        $customer = $this->customerByEmail($email);
        if ($customer === null) {
            return;
        }
        if (self::key() === '') {
            // Never store a code hashed without the key (the partner bank
            // details rule): send nothing, and say so in the log.
            log_message('critical', 'Returning patient code not sent: encryption.key is not set.');

            return;
        }
        $code  = sprintf('%06d', random_int(0, 999999));
        $token = TokenHash::mint();
        BookingCustomerModel::forBusiness($this->listingId)->update((int) $customer['id'], [
            'login_token_hash' => TokenHash::hash($token),
            'login_code_hash'  => $this->codeHash($code, (string) $customer['hash']),
            'login_expires'      => gmdate('Y-m-d H:i:s', $this->now->getTimestamp() + self::LINK_MINUTES * 60),
            'login_code_expires' => gmdate('Y-m-d H:i:s', $this->now->getTimestamp() + self::CODE_MINUTES * 60),
            'login_tries'        => 0,
        ]);

        $query = array_filter(self::selection($selection), static fn (string $v): bool => $v !== '');
        $link  = listing_site_url($this->listing, 'directory/' . $this->listing['slug'] . '/book/returning/' . $token)
            . ($query === [] ? '' : '?' . http_build_query($query));
        $body = view('emails/booking-returning', [
            'business'  => (string) $this->listing['display_name'],
            'firstName' => (string) $customer['first_name'],
            'code'      => $code,
            'link'      => $link,
            'codeMinutes' => self::CODE_MINUTES,
            'linkMinutes' => self::LINK_MINUTES,
        ], ['saveData' => false]);
        (new Mailer())->send($email, 'Your code to book with ' . $this->listing['display_name'], $body, '', [
            'fromName' => $this->listing['display_name'] . ' via WebScheduler Local',
        ]);
    }

    /** The customer id when the code is right and still live, else null (a wrong one counts as a try). */
    public function checkCode(string $email, string $code): ?int
    {
        $code     = preg_replace('/\D+/', '', $code) ?? '';
        $customer = $this->customerByEmail($email);
        if ($customer === null || strlen($code) !== 6 || self::key() === '' || ! $this->live($customer, 'login_code_expires')
            || (string) ($customer['login_code_hash'] ?? '') === '') {
            return null;
        }
        if (! hash_equals((string) $customer['login_code_hash'], $this->codeHash($code, (string) $customer['hash']))) {
            $tries = (int) $customer['login_tries'] + 1;
            BookingCustomerModel::forBusiness($this->listingId)->update((int) $customer['id'], $tries >= self::MAX_TRIES
                ? $this->cleared()
                : ['login_tries' => $tries]);

            return null;
        }
        BookingCustomerModel::forBusiness($this->listingId)->update((int) $customer['id'], $this->cleared());

        return (int) $customer['id'];
    }

    /** The customer id an emailed link proves, once, while it is live; else null. */
    public function redeemLink(string $token): ?int
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $customer = BookingCustomerModel::forBusiness($this->listingId)
            ->where('login_token_hash', TokenHash::hash($token))->where('purged_at', null)->first();
        if (! is_array($customer) || ! $this->live($customer, 'login_expires')) {
            return null;
        }
        BookingCustomerModel::forBusiness($this->listingId)->update((int) $customer['id'], $this->cleared());

        return (int) $customer['id'];
    }

    /**
     * What the details form starts with for a proven patient: their names,
     * email, phone, address and earlier answers. Sensitive answers are left
     * for them to give again.
     *
     * @return array<string,string>
     */
    public function prefill(int $customerId): array
    {
        $customers = new CustomerService($this->listingId);
        $customer  = $customers->find($customerId);
        if ($customer === null) {
            return [];
        }
        $out = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'address'] as $field) {
            $out[$field] = (string) ($customer[$field] ?? '');
        }
        foreach ($customers->customFields() as $q) {
            $answer = $customer['custom_fields'][$q['key']] ?? null;
            if (! $q['sensitive'] && is_scalar($answer) && (string) $answer !== '') {
                $out[$q['key']] = (string) $answer;
            }
        }

        return $out;
    }

    // The session: which email waits for a code, and who is proven

    /** The email a code was sent to on this device, while the code can still be live. */
    public static function pendingEmail(int $listingId, ?DateTimeImmutable $now = null): ?string
    {
        $state = self::state($listingId);
        if (! isset($state['email'], $state['sent_at']) || ($now ?? new DateTimeImmutable())->getTimestamp() - (int) $state['sent_at'] > self::CODE_MINUTES * 60) {
            return null;
        }

        return (string) $state['email'];
    }

    /** The proven patient's customer id on this device, or null. */
    public static function verifiedId(int $listingId, ?DateTimeImmutable $now = null): ?int
    {
        $state = self::state($listingId);
        if (! isset($state['customer_id'], $state['verified_at']) || ($now ?? new DateTimeImmutable())->getTimestamp() - (int) $state['verified_at'] > self::VERIFIED_MINUTES * 60) {
            return null;
        }

        return (int) $state['customer_id'];
    }

    public static function rememberSent(int $listingId, string $email): void
    {
        self::put($listingId, ['email' => $email, 'sent_at' => time(), 'tries' => 0]);
    }

    /** Counts a wrong code on this device; true while more tries are allowed. */
    public static function countTry(int $listingId): bool
    {
        $state          = self::state($listingId);
        $state['tries'] = (int) ($state['tries'] ?? 0) + 1;
        if ($state['tries'] >= self::MAX_TRIES) {
            self::forget($listingId);

            return false;
        }
        self::put($listingId, $state);

        return true;
    }

    public static function rememberVerified(int $listingId, int $customerId): void
    {
        self::put($listingId, ['customer_id' => $customerId, 'verified_at' => time()]);
    }

    public static function forget(int $listingId): void
    {
        if (! session_exists()) {
            return;
        }
        $all = (array) (session()->get(self::SESSION_KEY) ?? []);
        unset($all[$listingId]);
        session()->set(self::SESSION_KEY, $all);
    }

    /**
     * The chosen time as the booking form posts it, checked for shape only
     * (Book::page() checks it against the free times).
     *
     * @param array<string,mixed> $input
     *
     * @return array{service_id:string,provider_id:string,place:string,start:string}
     */
    public static function selection(array $input): array
    {
        $digits = static fn (mixed $v): string => ctype_digit((string) ($v ?? '')) ? (string) $v : '';
        $start  = (string) ($input['start'] ?? '');

        return [
            'service_id'  => $digits($input['service_id'] ?? ''),
            'provider_id' => $digits($input['provider_id'] ?? ''),
            'place'       => $digits($input['place'] ?? ''),
            'start'       => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start) ? $start : '',
        ];
    }

    // Internals

    /** @return array<string,mixed>|null */
    private function customerByEmail(string $email): ?array
    {
        $email = CustomerService::normalEmail($email);
        if ($email === null) {
            return null;
        }
        $row = BookingCustomerModel::forBusiness($this->listingId)->where('email', $email)->where('purged_at', null)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Whether the code (login_code_expires) or the link (login_expires) is
     * still live: not past its time and not spent by five wrong codes.
     *
     * @param array<string,mixed> $customer
     */
    private function live(array $customer, string $expiresColumn): bool
    {
        return ! empty($customer[$expiresColumn])
            && (string) $customer[$expiresColumn] > gmdate('Y-m-d H:i:s', $this->now->getTimestamp())
            && (int) ($customer['login_tries'] ?? 0) < self::MAX_TRIES;
    }

    /** @return array<string,null> */
    private function cleared(): array
    {
        return ['login_token_hash' => null, 'login_code_hash' => null, 'login_expires' => null, 'login_code_expires' => null, 'login_tries' => null];
    }

    /**
     * HMAC SHA-256 keyed with encryption.key, over the code and the
     * customer's own hash (so one row's value says nothing about another's).
     * Compared with hash_equals() only.
     */
    private function codeHash(string $code, string $customerHash): string
    {
        return hash_hmac('sha256', $code . '|' . $customerHash, self::key());
    }

    /**
     * The application's encryption.key as raw bytes ('' when it is not set),
     * read like CI4's Encrypter reads it: `hex2bin:` and `base64:` prefixes
     * decoded.
     */
    private static function key(): string
    {
        $key = (string) (config('Encryption')->key ?? '');
        if (str_starts_with($key, 'hex2bin:')) {
            return (string) hex2bin(substr($key, 8));
        }
        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7), true);
        }

        return $key;
    }

    /** @return array<string,mixed> */
    private static function state(int $listingId): array
    {
        if (! session_exists()) {
            return [];
        }

        return (array) ((session()->get(self::SESSION_KEY) ?? [])[$listingId] ?? []);
    }

    /** @param array<string,mixed> $state */
    private static function put(int $listingId, array $state): void
    {
        $all             = (array) (session()->get(self::SESSION_KEY) ?? []);
        $all[$listingId] = $state;
        session()->set(self::SESSION_KEY, $all);
    }
}
